<?php
/* Removing containers and plugins: docker and Unraid's plugin tool are mocked; RM['log'] records what was run, in order. */

/* $cs: name => ['state' => running|exited, 'net' => NetworkMode, 'mounts' => [...]]; $fail: commands that fail, e.g. 'rm app' */
function rmenv(array $cs, array $fail = []) {
  $GLOBALS['RM'] = ['cs' => $cs, 'fail' => $fail, 'log' => []];
  mock_exec(function ($a) {
    $R =& $GLOBALS['RM']; $b = basename($a[0]);
    if ($b === 'plugin') {
      if ($a[1] !== 'remove') return ['out' => "1\n"];        // attribute reads while listing plugins
      $R['log'][] = 'plugin ' . $a[1] . ' ' . $a[2];
      if ($a[1] === 'remove' && !in_array('plugin remove ' . $a[2], $R['fail'], true)) @unlink(DAU_PLUGLINKS . '/' . $a[2]);
      return in_array('plugin remove ' . $a[2], $R['fail'], true) ? ['code' => 1, 'err' => 'boom'] : ['code' => 0];
    }
    if ($b !== 'docker') return null;
    $insp = function ($n) use ($R) { $c = $R['cs'][$n]; return ['Name' => "/$n", 'Id' => "id-$n-0123456789abcdef", 'Image' => "sha256:img-$n", 'Config' => ['Image' => "repo/$n:latest"],
      'State' => ['Status' => $c['state'], 'Running' => $c['state'] === 'running'], 'HostConfig' => ['NetworkMode' => $c['net'] ?? 'bridge'],
      'Mounts' => array_map(function ($s) { return ['Type' => 'bind', 'Source' => $s]; }, $c['mounts'] ?? [])]; };
    $cmd = $a[1] === 'image' ? 'image ' . $a[2] . ' ' . end($a) : $a[1] . ' ' . end($a);
    if ($a[1] === 'ps') return ['out' => implode("\n", array_map(function ($n) { return "id-$n"; }, array_keys($R['cs']))) . "\n"];
    if ($a[1] === 'inspect') {
      $want = array_slice($a, 2);
      if (count($want) === 1 && isset($R['cs'][$want[0]])) return ['out' => json_encode([$insp($want[0])])];
      if (count($want) === 1 && !preg_match('/^id-/', $want[0])) return ['code' => 1, 'err' => 'No such object'];
      return ['out' => json_encode(array_map($insp, array_keys($R['cs'])))];
    }
    $R['log'][] = $cmd;
    if (in_array($cmd, $R['fail'], true)) return ['code' => 1, 'err' => 'Error response from daemon: conflict'];
    if ($a[1] === 'stop') $R['cs'][end($a)]['state'] = 'exited';
    if ($a[1] === 'rm') unset($R['cs'][end($a)]);
    return ['code' => 0];
  });
}

t('remove: a running container is stopped, removed, its image deleted; appdata and template are kept', function () {
  rmenv(['app' => ['state' => 'running', 'mounts' => ['/mnt/user/appdata/app']], 'db' => ['state' => 'running']]);
  file_put_contents(DAU_TEMPLATES . '/my-app.xml', '<Container><Name>app</Name></Container>');
  $r = dau_docker_remove('app', ['image' => true, 'template' => false]);
  eq($r['result'], 'removed', $r['error']); eq($GLOBALS['RM']['log'], ['stop app', 'rm app', 'image rm sha256:img-app'], 'stop, remove (never forced), then the image');
  foreach ($GLOBALS['RM']['log'] as $c) lacks($c, '-f', 'nothing is forced');
  eq($r['image'], 'removed'); eq($r['template'], 'kept'); ok(is_file(DAU_TEMPLATES . '/my-app.xml'), 'template stays for Previous Apps'); has($r['next'], '/mnt/user/appdata/app'); has($r['next'], 'Cleanup');
  ok(isset($GLOBALS['RM']['cs']['db']), 'other containers untouched');
});

t('remove: options - keep the image, archive the template; a stopped container is not stopped again', function () {
  rmenv(['old' => ['state' => 'exited']]);
  file_put_contents(DAU_TEMPLATES . '/my-old.xml', '<Container><Name>old</Name></Container>');
  $r = dau_docker_remove('old', ['image' => false, 'template' => true]);
  eq($r['result'], 'removed'); eq($GLOBALS['RM']['log'], ['rm old']); eq($r['image'], ''); eq($r['template'], 'archived');
  ok(!is_file(DAU_TEMPLATES . '/my-old.xml'), 'moved out of templates-user'); eq(count(glob(DAU_TPL_BACKUPS . '/old__*.xml')), 1, 'restorable from Cleanup');
});

t('remove: an image another container still uses is kept; refusals change nothing', function () {
  rmenv(['app' => ['state' => 'running']], ['image rm sha256:img-app']);
  $r = dau_docker_remove('app', ['image' => true]); eq($r['result'], 'removed'); has($r['image'], 'kept');
  rmenv(['vpn' => ['state' => 'running'], 'qbit' => ['state' => 'running', 'net' => 'container:vpn']]);
  $r = dau_docker_remove('vpn', ['image' => true]); eq($r['result'], 'blocked'); has($r['error'], 'qbit'); eq($GLOBALS['RM']['log'], [], 'a network provider is not touched');
  rmenv(['vpn' => ['state' => 'running'], 'qbit' => ['state' => 'running', 'net' => 'container:id-vpn-0123456789abcdef']]);     // Docker stores the full id
  eq(dau_docker_remove('vpn', [])['result'], 'blocked', 'also when referenced by id');
  rmenv([]); eq(dau_docker_remove('ghost', [])['result'], 'blocked'); eq(dau_docker_remove('../x', [])['result'], 'blocked');
  rmenv(['app' => ['state' => 'running']], ['stop app']); $r = dau_docker_remove('app', ['image' => true]);
  eq($r['result'], 'failed'); eq($GLOBALS['RM']['log'], ['stop app'], 'not removed when it cannot be stopped'); has($r['next'], 'Nothing was removed');
  rmenv(['app' => ['state' => 'exited']], ['rm app']); $r = dau_docker_remove('app', ['image' => true]);
  eq($r['result'], 'failed'); eq($GLOBALS['RM']['log'], ['rm app'], 'the image stays when the container could not be removed');
});

function rm_plugins(array $files) {
  @mkdir(DAU_PLUGLINKS, 0755, true); @mkdir(DAU_PLUGBOOT, 0755, true);
  foreach ($files as $f) { file_put_contents(DAU_PLUGBOOT . "/$f", "<?xml version='1.0'?><PLUGIN name=\"x\" version=\"1\"></PLUGIN>"); @symlink(DAU_PLUGBOOT . "/$f", DAU_PLUGLINKS . "/$f"); }
}

t('remove: plugins go through Unraid\'s plugin tool and are verified; this plugin and Unraid are refused', function () {
  rmenv([]); rm_plugins(['foo.plg', DAU_SELF_PLG, 'unRAIDServer.plg']);
  $r = dau_plugin_remove('foo.plg'); eq($r['result'], 'removed', $r['error']); eq($GLOBALS['RM']['log'], ['plugin remove foo.plg']); ok(!is_link(DAU_PLUGLINKS . '/foo.plg'));
  eq(dau_plugin_remove(DAU_SELF_PLG)['result'], 'blocked'); eq(dau_plugin_remove('unRAIDServer.plg')['result'], 'blocked'); eq(dau_plugin_remove('gone.plg')['result'], 'blocked');
  eq(dau_plugin_remove('../x.plg')['result'], 'blocked'); eq(count($GLOBALS['RM']['log']), 1, 'nothing else was run');
  rm_plugins(['bar.plg']); rmenv([], ['plugin remove bar.plg']); $r = dau_plugin_remove('bar.plg'); eq($r['result'], 'failed'); has($r['error'], 'still lists');
});

t('remove: the job records history, keeps going after a refusal and notifies only failures', function () {
  rmenv(['a' => ['state' => 'running'], 'vpn' => ['state' => 'running'], 'b' => ['state' => 'running', 'net' => 'container:vpn']]);
  $GLOBALS['NOTIFY'] = [];
  eq(dau_job_main('remove_docker', ['names' => ['vpn', 'a', '../bad'], 'image' => true]), 0);
  ok(!isset($GLOBALS['RM']['cs']['a']) && isset($GLOBALS['RM']['cs']['vpn']), 'a removed, vpn refused');
  $h = dau_history_load(); eq($h[0]['name'], 'a'); eq($h[0]['action'], 'remove'); eq($h[0]['result'], 'removed'); has($h[0]['message'], 'Image removed'); eq($h[1]['result'], 'blocked');
  eq(dau_state_load()['last_result']['docker']['skipped'], 1);
  rm_plugins(['foo.plg']); rmenv([]); eq(dau_job_main('remove_plugins', ['names' => ['foo.plg', DAU_SELF_PLG]]), 0);
  $h = dau_history_load(); eq($h[0]['type'], 'plugin'); eq($h[0]['name'], 'unraid-swiss-manager'); eq($h[0]['result'], 'blocked'); eq($h[1]['name'], 'foo'); eq($h[1]['result'], 'removed');
});

t('remove: api needs POST, a confirmation and valid names; it never passes this plugin or Unraid on', function () {
  dau_write_json(DAU_PROGRESS, ['queued' => true, 'ts' => dau_now() + 999999]);     // a job "is running": a request that passes every check stops at the spawn, nothing starts
  has(api_call('run', ['what' => 'remove_docker', 'names' => json_encode(['a'])])['error'] ?? '', 'confirmation');
  has(api_call('run', ['what' => 'remove_docker', 'names' => json_encode(['a'])], 'GET')['error'] ?? '', 'POST');
  has(api_call('run', ['what' => 'remove_docker', 'confirm' => '1', 'names' => json_encode(['../x'])])['error'] ?? '', 'Invalid');
  has(api_call('run', ['what' => 'remove_docker', 'confirm' => '1', 'names' => json_encode([])])['error'] ?? '', 'Nothing');
  has(api_call('run', ['what' => 'remove_plugins', 'confirm' => '1', 'names' => json_encode([DAU_SELF_PLG])])['error'] ?? '', 'cannot be removed');
  has(api_call('run', ['what' => 'remove_plugins', 'confirm' => '1', 'names' => json_encode(['foo.plg', 'unRAIDServer.plg'])])['error'] ?? '', 'cannot be removed');
  has(api_call('run', ['what' => 'remove_docker', 'confirm' => '1', 'image' => '1', 'names' => json_encode(['a', 'b'])])['error'] ?? '', 'already running', 'valid request reaches the job start');
  has(api_call('run', ['what' => 'remove_plugins', 'confirm' => '1', 'names' => json_encode(['foo.plg'])])['error'] ?? '', 'already running');
  @unlink(DAU_PROGRESS);
  $js = file_get_contents(__DIR__ . '/../src/assets/app.js'); has($js, "runJob('remove_docker'"); has($js, "runJob('remove_plugins'"); has($js, 'go.disabled = !ok.checked');
  $run = file_get_contents(__DIR__ . '/../src/scripts/run.php'); has($run, "'remove-docker:'"); has($run, "dau_job_main('remove_plugins'");
});
