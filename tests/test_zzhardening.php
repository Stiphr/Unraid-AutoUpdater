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
  $page = file_get_contents(__DIR__ . '/../src/include/page.php'); has($page, 'window.DAU_THEME');
  $dash = file_get_contents(__DIR__ . '/../src/UnraidSwissManagerDash.page'); has($dash, 'Cond="version_compare'); has($dash, 'dau-w-go');
});

t('os: the check uses Unraid\'s own checker, which skips its notification, and keeps its release notes link', function () {
  $s = DAU_PLUGTMP . '/unraidcheck'; file_put_contents($s, '#!/bin/sh'); $GLOBALS['DAU_OSCHECK'] = $s;
  $argv = [];
  mock_exec(function ($a) use (&$argv) { $argv = $a; return ['out' => json_encode(['version' => '7.4.0', 'isNewer' => true, 'changelogPretty' => 'https://docs.unraid.net/x/'])]; });
  $c = dau_os_check(); ok($c['ok']); eq($c['latest'], '7.4.0'); ok(in_array('QUERY_STRING=json=true', $argv, true)); eq(end($argv), $s);
  $st = dau_state_defaults(); $ev = dau_os_process($st, $c, 100); eq($st['os']['available'], '7.4.0'); has($ev[0]['reason'], 'docs.unraid.net');
  mock_exec(function ($a) { return ['out' => '{"error":"could not connect"}']; }); $c = dau_os_check(); ok(!$c['ok']); has($c['error'], 'could not connect');
  mock_exec(function ($a) { return ['out' => 'not json']; }); ok(!dau_os_check()['ok']);
  unset($GLOBALS['DAU_OSCHECK']); @unlink($s); mock_exec(function ($a) { return null; });
});

t('os: an install stops when the plugin feed offers another release (it must never install what the feed offers instead)', function () {
  os_env('7.0.1'); os_cfg('install');
  $s = DAU_PLUGTMP . '/unraidcheck'; file_put_contents($s, '#!/bin/sh'); $GLOBALS['DAU_OSCHECK'] = $s;
  $prev = $GLOBALS['DAU_EXEC_MOCK'];
  mock_exec(function ($a, $i = null) use ($prev) { if (basename($a[0]) === 'env') return ['out' => '{"version":"7.4.0"}']; return $prev($a, $i); });
  $st = dau_state_load(); $st['os']['first_seen'] = 1; dau_state_save($st);
  os_tick(); $os = dau_state_load()['os'];
  eq($os['available'], '7.4.0'); has($os['attempted']['reason'] ?? '', 'Tools > Update OS'); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0);
  $why = dau_os_feed_block('7.4.0'); has($why, 'offers 7.0.1'); eq(dau_os_feed_block('7.0.1'), '');
  eq(dau_os_install(dau_load_config(), '7.4.0')['result'], 'blocked'); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0);
  unset($GLOBALS['DAU_OSCHECK']); @unlink($s);
});

t('tile: only recent failures of items that have not succeeded since count', function () {
  @unlink(DAU_HIST); $now = dau_now();
  $add = function ($n, $r, $age) use ($now) { dau_history_add(['type' => 'plugin', 'name' => $n, 'action' => 'update', 'result' => $r, 'started' => $now - $age, 'finished' => $now - $age]); };
  $add('old', 'failed', 4 * 86400); $add('fixed', 'failed', 3600); $add('fixed', 'updated', 60); $add('new', 'failed', 600);
  eq(dau_recent_failures(), 1);
  @unlink(DAU_HIST);
});

t('plugins default to Weekly, Sunday 04:00, and a saved schedule is kept', function () {
  $d = dau_defaults()['plugins']; eq(dau_sched_text(dau_sched_of($d)), 'Weekly, Sunday 04:00'); eq(dau_defaults()['os']['frequency'], 'daily');
});

t('top menu: a tab that shares the page with Settings and can be hidden', function () {
  $tab = file_get_contents(__DIR__ . '/../src/SwissManager.page'); has($tab, 'Menu="Tasks:85"'); has($tab, 'Name="Swiss Manager"'); has($tab, 'include/page.php'); has($tab, "['ui']['menu_tab']");
  has(file_get_contents(__DIR__ . '/../src/UnraidSwissManager.page'), 'include/page.php'); ok(dau_defaults()['ui']['menu_tab']);
  $b = file_get_contents(__DIR__ . '/../build.py'); has($b, "'SwissManager.page'"); has($b, "'include/page.php'");
  $plg = file_get_contents(__DIR__ . '/../unraid-swiss-manager.plg'); has($plg, 'Name="&emhttp;/SwissManager.page"'); has($plg, 'Name="&emhttp;/include/page.php"');
  $cond = ''; preg_match('/^Cond="(.*)"$/m', $tab, $m); $f = function ($j) use ($m) { return eval('return ' . str_replace('/boot/config/plugins/unraid-swiss-manager/config.json', $j, $m[1]) . ';'); };
  $x = DAU_PLUGTMP . '/c.json'; ok($f($x . '.missing')); file_put_contents($x, '{"ui":{"menu_tab":false}}'); ok(!$f($x)); file_put_contents($x, '{"ui":{"menu_tab":true}}'); ok($f($x)); file_put_contents($x, '{'); ok($f($x)); @unlink($x);
});

t('tile: Update asks first, then runs the same job as the tabs, and says what is running', function () {
  $p = file_get_contents(__DIR__ . '/../src/UnraidSwissManagerDash.page');
  has($p, "post('run',{what:what,names:JSON.stringify(names)})"); has($p, 'Update now'); has($p, 'r.managed'); has($p, '!r.self&&!r.skipped'); has($p, "'dau-w-prog'"); has($p, '.dau-body{width:0;min-width:100%}');
  $a = strpos($p, 'function ask(what)'); $b = strpos($p, "'Update now'"); $c = strpos($p, "post('run',{what:what"); ok($a < $b && $b < $c, 'the job is only started from the confirmation');
  lacks(substr($p, 0, $a), "post('run',{what:what", 'no update starts outside the confirmation');
  $st = dau_state_defaults(); $st['backups']['flash'] = ['at' => 500, 'ok' => false] + $st['backups']['flash']; $st['backups']['appdata'] = ['at' => 400, 'ok' => true] + $st['backups']['appdata']; dau_state_save($st);
  $j = api_call('widget'); eq($j['backup_at'], 500); ok($j['backup_ok'] === false); ok(array_key_exists('next_label', $j)); eq($j['total'], 0); ok(is_string($j['phase']));
});

t('logo: one SVG, white on orange, and the PNG that Unraid needs is built from it', function () {
  $s = file_get_contents(__DIR__ . '/../src/images/unraid-swiss-manager.svg');
  has($s, 'fill="#ff5e2a"'); has($s, '<g fill="#fff">'); lacks($s, '#3a3a3a'); ok(simplexml_load_string($s) !== false);
  $plg = file_get_contents(__DIR__ . '/../unraid-swiss-manager.plg'); has($plg, 'Name="&emhttp;/images/unraid-swiss-manager.svg"');
});

t('docker: official images are found under library/, as Unraid files them', function () {
  $m = ['library/mysql:latest' => ['local' => 'sha256:a', 'remote' => 'sha256:b', 'status' => 'false'], 'library/postgres:17' => ['local' => 'sha256:c', 'remote' => 'sha256:c', 'status' => 'true']];
  ok(dau_update_info('mysql', $m)['flag'] === true); ok(dau_update_info('postgres:17', $m)['flag'] === false); ok(dau_update_info('docker.io/library/mysql:latest', $m)['flag'] === true);
  ok(dau_update_info('someone/mysql', $m)['flag'] === null, 'an image with an author is never taken for the official one');
});

t('lock: a process a job starts does not inherit it, and probing it does not look like a running job', function () {
  $fp = dau_lock_try(); ok(is_resource($fp));
  $p = proc_open(['sleep', '3'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  dau_lock_release($fp);
  ok(!dau_is_locked(), 'the child must not keep the lock'); $l = dau_lock_try(); ok(is_resource($l)); ok(dau_is_locked()); dau_lock_release($l);
  $sh = fopen(DAU_LOCK, 'c'); flock($sh, LOCK_SH); ok(!dau_is_locked(), 'another probe is not a job'); fclose($sh);
  proc_terminate($p, 9); proc_close($p);
});

t('run: a command that ended is not waited for because of what it left in the background, and its exit code is kept', function () {
  unset($GLOBALS['DAU_EXEC_MOCK']);
  $t = microtime(true); $r = dau_run(['sh', '-c', 'sleep 4 & echo hello; exit 3'], 20);
  ok(microtime(true) - $t < 3, 'returned soon after the command ended'); eq($r['code'], 3); has($r['out'], 'hello'); ok(!$r['timeout']);
  $r = dau_run(['sh', '-c', 'echo done; exit 5'], 10); eq($r['code'], 5); eq(trim($r['out']), 'done');
  $r = dau_run(['sh', '-c', 'sleep 5'], 1); ok($r['timeout']); eq($r['code'], 124);
});

t('verify: a container that was created but never started is a failure, not a slow start', function () {
  mock_exec(function ($a) { if (in_array('inspect', $a, true)) return ['out' => json_encode([['Image' => 'sha256:new', 'RestartCount' => 0, 'State' => ['Status' => 'created', 'Running' => false]]])]; return null; });
  $v = dau_docker_verify('app', 'sha256:new', true, 10); eq($v['result'], 'not_started'); ok(!$v['ok']);
  eq(dau_docker_verify('app', 'sha256:new', false, 10)['result'], 'not_running_expected');
});

t('image version: words and epochs do not hide the major version', function () {
  $v = function ($env, $lab = []) { mock_exec(function ($a) use ($env, $lab) { return ['out' => json_encode(['Env' => $env, 'Labels' => $lab])]; }); return dau_image_version('sha256:x'); };
  $r = $v(['MYSQL_MAJOR=innovation', 'MYSQL_VERSION=9.4.0-1.el9']); eq($r['major'], 9); eq($r['version'], '9.4.0-1.el9');
  $r = $v(['MARIADB_VERSION=1:11.4.2+maria~ubu2404']); eq($r['major'], 11); eq($r['version'], '11.4.2+maria~ubu2404');
  eq($v([], ['org.opencontainers.image.version' => '13.0.2'])['major'], 13);
});

t('events: the verification is sent as text, and notifications keep their reason', function () {
  $e = dau_discord_event_embed(dau_ev('docker', 'app', 'updated', ['verification' => 'Running and stable.']), 'Tower'); $x = json_encode($e); has($x, 'Running and stable.'); lacks($x, 'Array');
  $j = file_get_contents(__DIR__ . '/../src/include/jobs.php'); lacks($j, "'verification' => \$res['verification'],"); has($j, "\$res['verification']['detail'] ?? null");
  has($j, "((\$e['error'] ?? '') ?: (\$e['reason'] ?? ''))");
});

t('pre-OS: skipped versions and pinned containers stay out, as do skipped plugins', function () {
  file_put_contents(DAU_TEMPLATES . '/my-a.xml', '<x/>'); file_put_contents(DAU_TEMPLATES . '/my-b.xml', '<x/>'); file_put_contents(DAU_TEMPLATES . '/my-c.xml', '<x/>');
  file_put_contents(DAU_UPDSTATUS, json_encode(['img:latest' => ['local' => 'sha256:1', 'remote' => 'sha256:2', 'status' => 'false']]));
  $c = function ($n) { return ['Id' => str_repeat($n, 64), 'Name' => "/$n", 'Image' => 'sha256:i', 'Config' => ['Image' => 'img:latest', 'Labels' => []], 'State' => ['Status' => 'running']]; };
  mock_exec(function ($a) use ($c) { if (in_array('ps', $a, true)) return ['out' => "aaa\nbbb\nccc\n"]; if (in_array('inspect', $a, true)) return ['out' => json_encode([$c('a'), $c('b'), $c('c')])]; return null; });
  $cfg = dau_sanitize_config(['containers' => ['a' => ['policy' => 'auto'], 'b' => ['policy' => 'auto'], 'c' => ['policy' => 'auto']]], dau_defaults());
  $st = dau_state_defaults(); eq(dau_os_pre_containers($cfg, $st), ['a', 'b', 'c']);
  $st['docker']['skipped']['a'] = 'sha256:2'; $st['docker']['pinned']['b'] = ['repository' => 'img']; eq(dau_os_pre_containers($cfg, $st), ['c']);
  has(file_get_contents(__DIR__ . '/../src/include/jobs.php'), "(\$J['st']['plugins']['skipped'][\$f] ?? '') === \$latest)) continue;");
});

t('os: 7.3.10 is newer than 7.3.9 for the install check too', function () {
  $x = '<?xml version="1.0"?><PLUGIN name="unRAIDServer" version="7.3.10" pluginURL="https://x.example/unRAIDServer.plg"/>';
  $d = '<?xml version="1.0"?><PLUGIN name="unRAIDServer" version="7.3.9" pluginURL="https://x.example/unRAIDServer.plg"/>' . str_repeat(' ', 60);
  file_put_contents(DAU_PLUGTMP . '/unRAIDServer.plg', $x . str_repeat(' ', 60)); $old = DAU_PLUGBOOT . '/unRAIDServer.plg'; file_put_contents($old, $d);
  $p = ['file' => 'unRAIDServer.plg', 'path' => $old, 'self' => false, 'name' => 'unRAIDServer', 'version' => '7.3.9', 'url' => 'https://x.example/unRAIDServer.plg'];
  ok(dau_plugin_validate($p, '7.3.10')['ok']);
  ok(!dau_plugin_validate(['file' => 'a.plg', 'name' => 'unRAIDServer'] + $p, '7.3.10')['ok'], 'plugins keep Unraid\'s text comparison');
  @unlink($old);
});

t('cleanup: an unused image with two names keeps both and is removed by name', function () {
  $id = 'sha256:' . str_repeat('a', 64);
  mock_exec(function ($a) use ($id) {
    if ($a[1] === 'ps') return ['out' => ''];
    if ($a[1] === 'image' && $a[2] === 'ls') return ['out' => "$id|one/app|1.0|2 days ago\n$id|two/app|latest|2 days ago\n"];
    if ($a[1] === 'image' && $a[2] === 'inspect') return ['out' => "$id 100\n"];
    return null;
  });
  $s = dau_cleanup_scan(); eq(count($s['images']), 1); eq($s['images'][0]['tags'], ['one/app:1.0', 'two/app:latest']);
  $r = dau_cleanup_run([$id], []); eq(count($r['removed']), 1);
  eq(count(calls_matching('image rm one/app:1.0 two/app:latest')), 1);
});

t('log: the size setting is used', function () {
  unset($GLOBALS['DAU_LOG_CFG']); eq(dau_log_max_bytes(), 1048576);
  dau_write_json(DAU_CFG, ['version' => 2, 'log' => ['max_kb' => 64]]); dau_load_config(); eq(dau_log_max_bytes(), 65536); unset($GLOBALS['DAU_LOG_CFG']); @unlink(DAU_CFG);
});

t('log: the tail reaches into the rotated file only when it has to', function () {
  file_put_contents(DAU_LOG . '.1', "old1\nold2\n"); file_put_contents(DAU_LOG, "new1\nnew2\n");
  eq(dau_log_tail(3), "old2\nnew1\nnew2\n"); eq(dau_log_tail(2), "new1\nnew2\n"); eq(dau_log_recent(1), 'new2');
  file_put_contents(DAU_LOG, str_repeat("x\n", 40000)); eq(strlen(dau_log_recent(5)), 9); @unlink(DAU_LOG); @unlink(DAU_LOG . '.1'); eq(dau_log_tail(5), '');
});

t('json: text that is not UTF-8 is kept, not emptied', function () {
  $f = DAU_DIR . '/u.json'; dau_write_json($f, ['a' => "bad \xE9 text", 'b' => 1]); $d = json_decode(file_get_contents($f), true); has($d['a'], 'bad'); eq($d['b'], 1); @unlink($f);
});

t('cron: the cleanup report and the quarantine expiry keep the dispatcher on', function () {
  mock_exec(function ($a) { return null; }); $c = dau_defaults(); $c['plugins']['check_enabled'] = false; $c['os']['check_enabled'] = false;
  dau_cron_apply($c); ok(!is_file(DAU_CRON)); $c['appdata']['report'] = true; dau_cron_apply($c); ok(is_file(DAU_CRON));
  $c['appdata']['report'] = false; dau_cron_apply($c); ok(!is_file(DAU_CRON)); $c['appdata']['purge_days'] = 7; dau_cron_apply($c); ok(is_file(DAU_CRON)); @unlink(DAU_CRON);
});

t('plugins: updating chosen ones checks only those and keeps what is known about the rest', function () {
  foreach (['a', 'b'] as $n) { $p = DAU_PLUGBOOT . "/$n.plg"; file_put_contents($p, "<?xml version=\"1.0\"?><PLUGIN name=\"$n\" version=\"1\" pluginURL=\"https://x.example/$n.plg\"/>"); symlink($p, DAU_PLUGLINKS . "/$n.plg"); }
  mock_exec(function ($a) { if ($a[1] === 'check') { $f = $a[2]; file_put_contents(DAU_PLUGTMP . "/$f", '<?xml version="1.0"?><PLUGIN version="2"/>'); } if ($a[1] === 'version') return ['out' => "2\n"]; return null; });
  $J = dau_job_new('manual', dau_defaults(), dau_state_defaults()); $J['st']['plugins']['available'] = ['b.plg' => '2'];
  dau_job_plugins($J, ['names' => ['a.plg'], 'update' => false, 'manual' => true]);
  eq(count(calls_matching('check a.plg')), 1); eq(count(calls_matching('check b.plg')), 0); eq($J['st']['plugins']['available']['b.plg'], '2'); eq($J['st']['plugins']['available']['a.plg'], '2'); eq($J['st']['plugins']['last_check'], 0);
  foreach (['a', 'b'] as $n) { @unlink(DAU_PLUGLINKS . "/$n.plg"); @unlink(DAU_PLUGBOOT . "/$n.plg"); }
});

t('docker: a manual update reuses a fresh check, an old one is repeated', function () {
  file_put_contents(DAU_UPDSTATUS, '{}'); touch(DAU_UPDSTATUS, dau_now() - 60); ok(dau_docker_status_fresh());
  touch(DAU_UPDSTATUS, dau_now() - 4000); ok(!dau_docker_status_fresh()); @unlink(DAU_UPDSTATUS); ok(!dau_docker_status_fresh());
});

t('tile: skipped and pinned items are not waiting, and "Checked" means a check', function () {
  $st = dau_state_defaults(); $st['summary'] = ['containers' => 5, 'docker_updates' => 3, 'plugin_updates' => 2, 'at' => 500];
  $st['docker']['seen'] = ['a' => ['remote' => 'r1'], 'b' => ['remote' => 'r2'], 'c' => ['remote' => 'r3']]; $st['docker']['skipped']['a'] = 'r1'; $st['docker']['pinned']['b'] = ['repository' => 'x'];
  $st['plugins']['available'] = ['p.plg' => '2', 'q.plg' => '3']; $st['plugins']['skipped'] = ['q.plg' => '3']; $st['os']['last_check'] = 900; $st['last_run'] = 5000; dau_state_save($st);
  $j = api_call('widget'); eq($j['docker'], 1); eq($j['plugins'], 1); eq($j['checked'], 900);
  $p = file_get_contents(__DIR__ . '/../src/UnraidSwissManagerDash.page'); has($p, "r.state!=='pinned'&&r.state!=='skipped'"); has(file_get_contents(__DIR__ . '/../src/assets/app.js'), 'r.managed && !r.pinned && !r.skipped');
});
