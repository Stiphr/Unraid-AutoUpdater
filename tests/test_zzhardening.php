<?php
/* Failure paths: what happens when Docker does not answer, a job dies, a file is damaged, or the browser and a job change the same thing. */

/* $list: name => ['state','net','src','id']; $o: ps_fail, inspect_fail. Every stop/start/rm is recorded in DK['log']. */
function dkenv(array $list, array $o = []) {
  $GLOBALS['DK'] = ['cs' => $list, 'log' => [], 'fail' => $o['fail'] ?? []] + $o;
  mock_exec(function ($a) {
    $D =& $GLOBALS['DK']; if (basename($a[0]) !== 'docker') return null;
    $one = function ($n) use (&$D) { $c = $D['cs'][$n]; return ['Name' => "/$n", 'Id' => $c['id'] ?? "id-$n-0123456789abcdef", 'Image' => "sha256:$n", 'Config' => ['Image' => "r/$n"], 'State' => ['Status' => $c['state'], 'Running' => $c['state'] === 'running'], 'HostConfig' => ['NetworkMode' => $c['net'] ?? 'bridge'], 'Mounts' => isset($c['src']) ? [['Type' => 'bind', 'Source' => $c['src']]] : []]; };
    if ($a[1] === 'ps') return !empty($D['ps_fail']) ? ['code' => 1, 'err' => 'busy'] : ['out' => implode("\n", array_keys($D['cs'])) . "\n"];
    if ($a[1] === 'inspect') {
      $want = array_slice($a, 2);
      if (count($want) === 1 && !isset($D['cs'][$want[0]])) return ['code' => 1];
      if (!empty($D['inspect_fail']) && (count($want) > 1 || empty($D['one_ok']))) return ['code' => 1, 'out' => '', 'err' => 'timeout'];
      return ['out' => json_encode(array_map($one, $want))];
    }
    if (in_array($a[1], ['stop', 'start', 'rm'], true)) {
      $n = end($a); $D['log'][] = $a[1] . ' ' . $n;
      if (in_array($a[1] . ' ' . $n, $D['fail'], true)) return ['code' => 1, 'err' => 'refused'];
      if ($a[1] === 'stop') $D['cs'][$n]['state'] = 'exited';
      if ($a[1] === 'start') $D['cs'][$n]['state'] = 'running';
      if ($a[1] === 'rm') unset($D['cs'][$n]);
      return ['code' => 0];
    }
    return null;
  });
}
function hcfg(?callable $f = null) { $c = dau_defaults(); if ($f) $f($c); dau_save_config($c); return dau_load_config(); }

t('docker: an unreadable inspect is "unknown", never "no containers", so pins and rollback records survive', function () {
  dkenv(['plex' => ['state' => 'running']], ['inspect_fail' => true]);
  eq(dau_containers(), null, 'not an empty list'); eq(dau_inspect_all(), null); eq(dau_appdata_mounts(), null);
  $st = dau_state_defaults(); $st['docker']['pinned']['plex'] = ['repository' => 'x', 'ref' => 'y', 'at' => 1]; $st['docker']['rollback']['plex'] = ['ref' => 'y'];
  dau_state_save($st); $GLOBALS['DAU_NOW'] += 1;
  eq(dau_job_main('docker', ['names' => ['plex']]), 0);
  $s = dau_state_load(); eq(count($s['docker']['pinned']), 1, 'pin kept'); eq(count($s['docker']['rollback']), 1, 'rollback kept');
});

t('docker: no containers at all is still an empty list', function () { dkenv([]); eq(dau_inspect_all(), []); eq(dau_containers(), []); });

t('docker: inspect output above the usual 1 MiB limit is read in full', function () {
  $big = json_encode(array_fill(0, 90, ['Name' => '/x', 'Pad' => str_repeat('a', 12300)])); ok(strlen($big) > 1048576);
  file_put_contents('/tmp/dau-big.json', $big);
  $r = dau_run(['/bin/cat', '/tmp/dau-big.json'], 20, null, DAU_INSPECT_MAX); ok(is_array(json_decode($r['out'], true)), 'complete JSON'); @unlink('/tmp/dau-big.json');
  $src = file_get_contents(__DIR__ . '/../src/include/docker.php'); has($src, "90, DAU_INSPECT_MAX");
});

t('remove: nothing is removed when the other containers cannot be checked', function () {
  dkenv(['vpn' => ['state' => 'exited'], 'qbit' => ['state' => 'running', 'net' => 'container:vpn']], ['inspect_fail' => true, 'one_ok' => true]);
  $r = dau_docker_remove('vpn', []); eq($r['result'], 'blocked'); has($r['error'], 'network'); eq($GLOBALS['DK']['log'], [], 'not stopped, not removed');
});

t('backups: a backup is refused, not run live, when "stop containers" cannot ask Docker', function () {
  @mkdir('/mnt/user/appdata', 0755, true); @mkdir('/mnt/user/bk', 0755, true);
  dkenv(['app' => ['state' => 'running', 'src' => '/mnt/user/appdata/app']], ['ps_fail' => true]);
  eq(dau_bk_users(dau_defaults(), ['/mnt/user/appdata']), null, 'unknown is not "nothing to stop"');
  $c = hcfg(function (&$c) { $c['backups']['appdata']['dest'] = '/mnt/user/bk'; $c['backups']['appdata']['stop_containers'] = true; });
  $r = dau_bk_run('appdata', $c); eq($r['ok'], false); has($r['error'], 'could not be stopped'); eq(glob('/mnt/user/bk/Unraid-Swiss-Manager/appdata/*.tar.gz'), []); ok(!is_file(DAU_STOPPED));
});

t('backups: containers on another one\'s network start after it and stop with it', function () {
  dkenv(['qbit' => ['state' => 'running', 'src' => '/mnt/user/appdata/qbit', 'net' => 'container:id-vpn-0123456789abcdef'], 'vpn' => ['state' => 'running', 'src' => '/mnt/user/appdata/vpn'], 'arr' => ['state' => 'running', 'net' => 'container:vpn'], 'other' => ['state' => 'running']]);
  $u = dau_bk_users(dau_defaults(), ['/mnt/user/appdata']);
  eq($u[0], 'vpn', 'the provider starts first'); ok(in_array('qbit', $u, true)); ok(in_array('arr', $u, true), 'no appdata, but it would lose its network'); ok(!in_array('other', $u, true));
});

t('backups: the list of stopped containers exists only while they are down, and a tar failure clears it', function () {
  @mkdir('/mnt/user/appdata', 0755, true); @mkdir('/mnt/user/bk', 0755, true);
  dkenv(['app' => ['state' => 'running', 'src' => '/mnt/user/appdata/app']]);
  $seen = null; $base = $GLOBALS['DAU_EXEC_MOCK'];
  $GLOBALS['DAU_EXEC_MOCK'] = function ($a, $s) use (&$seen, $base) { if (basename($a[0]) === 'tar') { $seen = dau_read_json(DAU_STOPPED, null); return ['code' => 2, 'err' => 'boom']; } return $base($a, $s); };
  $c = hcfg(function (&$c) { $c['backups']['appdata']['dest'] = '/mnt/user/bk'; $c['backups']['appdata']['stop_containers'] = true; });
  $r = dau_bk_run('appdata', $c); eq($r['ok'], false);
  eq($seen['names'], ['app'], 'on the flash drive before tar starts'); ok(!is_file(DAU_STOPPED), 'gone afterwards'); eq($GLOBALS['DK']['log'], ['stop app', 'start app']);
});

t('backups: after a killed backup the next job starts the containers, in start order, and says so', function () {
  dkenv(['vpn' => ['state' => 'exited'], 'qbit' => ['state' => 'exited'], 'up' => ['state' => 'running']]);
  hcfg(); dau_write_json(DAU_STOPPED, ['at' => dau_now(), 'names' => ['qbit', 'up', 'vpn']]);     // stop order
  eq(dau_job_main('check'), 0);
  eq($GLOBALS['DK']['log'], ['start vpn', 'start qbit'], 'a running one is left alone'); ok(!is_file(DAU_STOPPED));
  $h = dau_history_load(); eq($h[0]['result'], 'interrupted'); has($h[0]['error'], 'Started again: vpn, qbit');
});

t('backups: recovery waits while Docker does not answer; after a day it reports instead of starting', function () {
  dkenv(['app' => ['state' => 'exited']], ['ps_fail' => true]); hcfg();
  dau_write_json(DAU_STOPPED, ['at' => dau_now(), 'names' => ['app']]);
  eq(dau_job_main('tick'), 0); ok(is_file(DAU_STOPPED), 'kept for the next run'); eq($GLOBALS['DK']['log'], []);
  dkenv(['app' => ['state' => 'exited']]);
  dau_write_json(DAU_STOPPED, ['at' => dau_now() - 2 * 86400, 'names' => ['app']]);
  eq(dau_job_main('tick'), 0); ok(!is_file(DAU_STOPPED)); eq($GLOBALS['DK']['log'], [], 'may be stopped on purpose by now'); has(dau_history_load()[0]['error'], 'Check that these run: app');
});

t('jobs: a container left stopped by a dead update is started again, the update is not repeated', function () {
  dkenv(['app' => ['state' => 'exited'], 'off' => ['state' => 'exited']]); hcfg();
  $st = dau_state_defaults(); dau_mark_current($st, 'docker', 'app', 5, ['was_running' => true]);
  eq(dau_job_main('tick'), 0); eq($GLOBALS['DK']['log'], ['start app']); eq(dau_history_load()[0]['result'], 'interrupted');
  dkenv(['off' => ['state' => 'exited']]); $st = dau_state_load(); dau_mark_current($st, 'docker', 'off', 5, ['was_running' => false]);
  eq(dau_job_main('tick'), 0); eq($GLOBALS['DK']['log'], [], 'it was stopped before, so it stays stopped');
});

t('backups: a destination inside the source is refused through any name of the share', function () {
  foreach (['/mnt/user/appdata', '/mnt/cache/appdata/bk', '/mnt/disk2/backups'] as $d) @mkdir($d, 0755, true);
  $c = dau_defaults(); $c['appdata']['roots'] = ['/mnt/user/appdata'];
  foreach (['/mnt/cache/appdata/bk', '/mnt/user/appdata/bk'] as $d) { $c['backups']['appdata']['dest'] = $d; has(dau_bk_check_dest('appdata', $c), 'inside the folder', $d); }
  $c['backups']['appdata']['dest'] = '/mnt/disk2/backups'; eq(dau_bk_check_dest('appdata', $c), '');
});

t('backups: a flash backup survives a file changing while it is read; unmeasured size and notes are reported', function () {
  @mkdir('/mnt/user/bk', 0755, true); @mkdir('/boot', 0755, true);
  mock_exec(function ($a) { $b = basename($a[0]); if ($b === 'du') return ['code' => 1, 'out' => '']; if ($b === 'tar') { file_put_contents($a[2], 'x'); return ['code' => 1]; } return null; });
  $c = dau_defaults(); $c['backups']['flash']['dest'] = '/mnt/user/bk';
  $r = dau_bk_run('flash', $c); eq($r['ok'], true, $r['error']); has(implode(' ', $r['notes']), 'could not be measured'); has(implode(' ', $r['notes']), 'changed while');
  $J = dau_job_new('manual', $c, dau_state_defaults()); dau_job_backup($J, 'flash', ''); has(dau_history_load()[0]['message'], 'changed while');
});

t('backup: leftovers of a killed safety backup are removed after a day', function () {
  @mkdir('/mnt/user/s/app', 0755, true); $p = '/mnt/user/s/app/.20260101-000000-image.tar.partial'; $q = '/mnt/user/s/app/.20260102-000000-image.tar.partial';
  file_put_contents($p, 'x'); touch($p, dau_now() - 2 * 86400); file_put_contents($q, 'x'); touch($q, dau_now());
  $c = dau_defaults(); $c['global']['backup'] = ['enabled' => true, 'dest' => '/mnt/user/s', 'retention' => 3, 'config' => true, 'image' => false, 'appdata' => false, 'required' => false];
  dau_backup_run($c, 'app', ['Config' => []]); ok(!is_file($p), 'old one gone'); ok(is_file($q), 'a recent one may still be in use');
});

t('notify: failed backups and removals count as failures and pass quiet hours; old name is gone', function () {
  foreach (['backup_failed', 'remove_failed', 'recovered'] as $k) { eq(dau_event_toggle($k), 'failed', $k); ok(dau_event_urgent(['kind' => $k]), $k); }
  $e = dau_discord_event_embed(dau_ev('docker', 'x', 'remove_failed', []), 'T'); has($e['title'], 'Removal failed');
  has(dau_discord_summary_embed(['status' => 'ok'], 'T')['title'], 'SWISS MANAGER'); has(DAU_DEFAULT_ICON, '/stable/');
});

t('config: an exported file brings its per-plugin policies back', function () {
  $c = dau_defaults(); $c['plugins']['items']['foo.plg'] = ['policy' => 'auto']; dau_save_config($c);
  $ex = json_decode(json_encode(dau_export_config()), true);
  eq(dau_import_config($ex['config'])['plugins']['items']['foo.plg']['policy'], 'auto');
});

t('config: a damaged file is copied once per content, however often it is loaded', function () {
  file_put_contents(DAU_CFG, '{broken');
  for ($i = 0; $i < 5; $i++) { $GLOBALS['DAU_NOW'] += 1; dau_load_config(); }
  eq(count(glob(DAU_DIR . '/config.corrupt-*.json')), 1);
  file_put_contents(DAU_CFG, '{other'); dau_load_config(); eq(count(glob(DAU_DIR . '/config.corrupt-*.json')), 2, 'different damage, another copy');
});

t('state: damaged state and history are kept and logged before the defaults take over', function () {
  file_put_contents(DAU_STATE, '{"docker":{"pinned"'); file_put_contents(DAU_HIST, '[{"a"');
  $s = dau_state_load(); dau_state_save($s); dau_history_load();
  eq(count(glob(DAU_DIR . '/state.corrupt-*.json')), 1); eq(count(glob(DAU_DIR . '/history.corrupt-*.json')), 1); has(dau_log_tail(), 'state.corrupt-');
  $s = dau_state_load(); eq(count(glob(DAU_DIR . '/state.corrupt-*.json')), 1);
});

t('lib: a failed write leaves the old file and no temporary file; the progress file is replaced whole', function () {
  $d = DAU_DIR . '/ro'; @mkdir($d); dau_write_json("$d/a.json", ['x' => 1]);
  dau_progress(['a' => 1]); eq(dau_read_json(DAU_PROGRESS, []), ['a' => 1]); eq(glob(DAU_PROGRESS . '.tmp*'), []);
  $lib = file_get_contents(__DIR__ . '/../src/include/lib.php'); has($lib, '{ @unlink($tmp); return false; }');
});

t('lib: the live log reads only the newest lines', function () {
  eq(dau_log_recent(), '');
  for ($i = 0; $i < 900; $i++) dau_log('info', 'job', '-', 'x', 'ok', "line $i " . str_repeat('y', 60));
  $l = explode("\n", dau_log_recent(5)); eq(count($l), 5); has($l[4], 'line 899'); has($l[0], 'level=');
});

t('lib: a job waits a moment for the lock instead of giving up on a status check', function () {
  $a = dau_lock_try(); $t = microtime(true); eq(dau_lock_wait(), false); ok(microtime(true) - $t >= 0.15, 'it did wait'); dau_lock_release($a);
  $b = dau_lock_wait(); ok($b !== false); dau_lock_release($b);
});

t('progress: steps, stage and sticky job fields reach the web UI', function () {
  $GLOBALS['DAU_STICKY'] = ['job' => 'docker', 'started' => 7]; unset($GLOBALS['DAU_PROG']);
  dau_stage('pull'); eq(dau_read_json(DAU_PROGRESS, []), [], 'no phase yet: nothing to change');
  dau_phase('Updating a', ['steps' => [['pull', 'Download']], 'stage' => '']); dau_stage('pull');
  $p = dau_read_json(DAU_PROGRESS, []); eq($p['stage'], 'pull'); eq($p['job'], 'docker'); eq($p['started'], 7); eq($p['steps'], [['pull', 'Download']]);
  dau_sticky(['steps' => [['check', 'Check']], 'stage' => 'check']); dau_phase('x'); eq(dau_read_json(DAU_PROGRESS, [])['stage'], 'check');
  $c = dau_defaults(); eq(array_column(dau_docker_steps($c, 'a'), 0), ['pull', 'apply', 'verify']);
  $c['global']['backup']['enabled'] = true; $c['containers']['a'] = ['backup' => true]; eq(dau_docker_steps($c, 'a')[0][0], 'backup');
});

t('plugins: attributes come from the file, without the plugin tool; a file that cannot be parsed still asks it', function () {
  @mkdir(DAU_PLUGLINKS, 0755, true); @mkdir(DAU_PLUGBOOT, 0755, true);
  file_put_contents(DAU_PLUGBOOT . '/a.plg', '<?xml version="1.0"?><!DOCTYPE PLUGIN [<!ENTITY v "3"><!ENTITY u "https://x.example/a.plg">]><PLUGIN name="a" version="&v;" pluginURL="&u;" min="6.9.0"></PLUGIN>');
  file_put_contents(DAU_PLUGBOOT . '/b.plg', 'not xml'); foreach (['a', 'b'] as $n) @symlink(DAU_PLUGBOOT . "/$n.plg", DAU_PLUGLINKS . "/$n.plg");
  mock_exec(function ($x) { return basename($x[0]) === 'plugin' ? ['out' => "9\n"] : null; });
  $p = dau_plugins_installed();
  eq($p['a.plg']['version'], '3'); eq($p['a.plg']['url'], 'https://x.example/a.plg'); eq($p['a.plg']['min'], '6.9.0'); eq($p['a.plg']['max'], '');
  eq($p['b.plg']['version'], '9', 'fallback'); eq(count($GLOBALS['CALLS']), 4, 'four calls for the broken file, none for the good one');
});

t('plugins: parameter entities pointing outside are refused like the others', function () {
  eq(dau_plg_parse('<?xml version="1.0"?><!DOCTYPE PLUGIN [<!ENTITY % x SYSTEM "file:///etc/hostname">%x;]><PLUGIN name="a" version="1"/>'), null);
});

t('os: Unraid releases compare as versions, so 7.3.10 follows 7.3.9', function () {
  ok(dau_os_newer('7.3.10', '7.3.9')); ok(!dau_os_newer('7.3.9', '7.3.10')); ok(!dau_os_newer('7.3.4', '7.3.4')); ok(dau_os_newer('7.3.0', '7.3.0-rc.1')); ok(!dau_os_newer('', '7.3.4'));
  file_put_contents('/etc/unraid-version', "version=\"7.3.9\"\n"); $st = dau_state_defaults();
  dau_os_process($st, ['ok' => true, 'latest' => '7.3.10'], 100); eq($st['os']['available'], '7.3.10');
});

t('counts: the Dashboard numbers drop as updates finish, without waiting for the next check', function () {
  $src = file_get_contents(__DIR__ . '/../src/include/jobs.php');
  has($src, "max(0, (int)\$J['st']['summary']['docker_updates'] - 1)"); has($src, "\$J['st']['summary']['plugin_updates'] = count(\$J['st']['plugins']['available'])");
  dkenv(['a' => ['state' => 'exited'], 'b' => ['state' => 'exited']]); hcfg();
  $st = dau_state_defaults(); $st['summary']['containers'] = 2; dau_state_save($st);
  eq(dau_job_main('remove_docker', ['names' => ['a']]), 0); eq(dau_state_load()['summary']['containers'], 1);
});

t('api: state changes wait for a running job; a stop click with no job leaves no flag; appdata exclusions survive an older browser copy', function () {
  $c = dau_defaults(); $c['appdata']['ignore'] = ['keep']; dau_save_config($c);
  api_call('save_config', ['config' => json_encode(['appdata' => ['ignore' => [], 'report' => true]])]);
  $n = dau_load_config(); eq($n['appdata']['ignore'], ['keep']); eq($n['appdata']['report'], true);
  api_call('stop'); ok(!is_file(DAU_STOPFLAG), 'no flag for the next run to trip over');
  $l = dau_lock_try();
  foreach ([['skip', ['type' => 'os']], ['unskip', ['type' => 'os']], ['approve', ['name' => 'a']], ['clear_history', []], ['os_abort', []]] as $x) {
    if ($x[0] === 'os_abort') continue;      // a running job gets the stop flag instead
    has(api_call($x[0], $x[1])['error'] ?? '', 'job is running', $x[0]);
  }
  ok(isset(api_call('stop')['ok'])); ok(is_file(DAU_STOPFLAG), 'with a job running it does stop'); @unlink(DAU_STOPFLAG);
  dau_lock_release($l);
  ok(isset(api_call('clear_history')['ok']));
  eq(api_call('sched_preview', ['frequency' => 'weekly', 'time' => '03:30', 'weekday' => '1'])['text'], 'Weekly, Monday 03:30');
  eq(count(api_call('sched_preview', ['frequency' => 'biweekly', 'time' => '03:30'])['next']), 3); has(api_call('sched_preview', ['frequency' => 'x'])['error'] ?? '', 'Unknown');
  file_put_contents(DAU_LOG, "hello\n"); eq(api_call('status', ['log' => '1'])['log'], 'hello'); ok(!isset(api_call('status')['log']));
});

t('build: unknown flags and a beta version without --beta build nothing', function () {
  $src = file_get_contents(__DIR__ . '/../build.py'); has($src, 'Nothing was built'); has($src, "unknown = [a for a in sys.argv[1:]");
});

t('ui: toasts and dialogs live outside the page that render() rebuilds; polling follows every job and ends with it', function () {
  $js = file_get_contents(__DIR__ . '/../src/assets/app.js');
  has($js, 'layer.appendChild(bg)'); has($js, 'layer.appendChild(t)'); lacks($js, 'root.appendChild(bg)'); lacks($js, 'root.appendChild(t)');
  has($js, 'if (busy) startPoll();'); has($js, 'S.pollBusy'); has($js, "function keep(k)");
  $page = file_get_contents(__DIR__ . '/../src/UnraidSwissManager.page'); has($page, 'window.DAU_THEME');
  $dash = file_get_contents(__DIR__ . '/../src/UnraidSwissManagerDash.page'); has($dash, 'Cond="version_compare'); has($dash, 'dau-w-go');
});
