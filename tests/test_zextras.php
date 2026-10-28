<?php
/* Beta features: dependency order, skip, major-version flag, rollback, cleanup, quiet hours, stats, notes, links. All commands mocked. */

function xid($s) { return 'sha256:' . substr(hash('sha256', $s), 0, 64); }

/**
 * Three containers (db, app, cache), each with an update available. Image versions: db 15.4 -> 16.1 (major jump), others 1.2 -> 1.3.
 * Options: pull_fail (names whose pull fails), verify_fail (names that are unhealthy after an update / rollback).
 */
function xenv(array $o = []) {
  $o += ['pull_fail' => [], 'verify_fail' => [], 'extra_images' => [], 'orphans' => []];
  $GLOBALS['CALLS'] = []; $GLOBALS['NOTIFY'] = [];
  @mkdir(DAU_DM . '/scripts', 0755, true);
  foreach (['update_container', 'dockerupdate'] as $f) file_put_contents(DAU_DM . '/scripts/' . $f, 'x');
  @mkdir(dirname(DAU_NOTIFY), 0755, true); file_put_contents(DAU_NOTIFY, 'x');
  $names = ['db', 'app', 'cache'];
  $st = ['applied' => [], 'ids' => [], 'rm' => [], 'rmc' => []];
  $upd = [];
  foreach ($names as $n) {
    file_put_contents(DAU_TEMPLATES . "/my-$n.xml", "<Container><Repository>x/$n:latest</Repository></Container>");
    $st['ids'][$n] = xid("old-$n");
    $upd["x/$n:latest"] = ['local' => 'sha256:l-' . $n, 'remote' => 'sha256:r-' . $n, 'status' => 'true'];
  }
  file_put_contents(DAU_UPDSTATUS, json_encode($upd));
  $GLOBALS['XS'] = &$st;
  $ver = function ($id) { // version label per image id
    foreach (['db', 'app', 'cache'] as $n) {
      if ($id === xid("old-$n")) return $n === 'db' ? '15.4' : '1.2';
      if ($id === xid("new-$n")) return $n === 'db' ? '16.1' : '1.3';
    }
    return '';
  };
  mock_exec(function ($a) use (&$st, $o, $names, $ver) {
    $j = implode(' ', $a);
    if (strpos($j, 'webGui/scripts/notify') !== false) { $GLOBALS['NOTIFY'][] = $a; return null; }
    if (strpos($j, 'dockerupdate') !== false) return null;
    if (preg_match('# ps -aq#', $j)) return ['out' => "i1\ni2\ni3\n" . ($o['orphans'] ? "i4\n" : '')];
    if (preg_match('# inspect i1 i2 i3#', $j)) {
      $mk = function ($n) use (&$st) { return ['Name' => "/$n", 'Image' => $st['ids'][$n], 'RestartCount' => 0, 'Config' => ['Image' => "x/$n:latest", 'Labels' => [], 'Env' => []], 'State' => ['Status' => 'running', 'Running' => true, 'ExitCode' => 0, 'StartedAt' => 'x'], 'Mounts' => []]; };
      $all = array_map($mk, ['app', 'cache', 'db']);
      if ($o['orphans']) $all[] = ['Name' => '/leftover', 'Image' => xid('left'), 'RestartCount' => 0, 'Config' => ['Image' => 'x/left:1', 'Labels' => [], 'Env' => []], 'State' => ['Status' => 'exited', 'Running' => false, 'ExitCode' => 0, 'StartedAt' => 'x', 'FinishedAt' => '2026-10-01T00:00:00Z'], 'SizeRw' => 2048, 'Mounts' => []];
      return ['out' => json_encode($all)];
    }
    if (preg_match('# inspect -s leftover#', $j)) return ['out' => json_encode([['Name' => '/leftover', 'Config' => ['Image' => 'x/left:1', 'Labels' => []], 'State' => ['Status' => 'exited', 'FinishedAt' => '2026-10-01T00:00:00Z'], 'SizeRw' => 2048]])];
    if (preg_match('# inspect (app|db|cache)$#', $j, $m)) {
      $n = $m[1]; $ap = !empty($st['applied'][$n]); $bad = in_array($n, $o['verify_fail'], true) && $ap;
      return ['out' => json_encode([['Name' => "/$n", 'Image' => $st['ids'][$n], 'RestartCount' => 0, 'Config' => ['Image' => "x/$n:latest", 'Env' => []],
        'State' => ['Status' => 'running', 'Running' => true, 'ExitCode' => 0, 'StartedAt' => 'x', 'Health' => ['Status' => $bad ? 'unhealthy' : 'healthy']], 'Mounts' => []]])];
    }
    if (strpos($j, 'update_container') !== false) {
      $n = rawurldecode(end($a)); $st['applied'][$n] = true;
      $tpl = file_get_contents(DAU_TEMPLATES . "/my-$n.xml");
      $st['ids'][$n] = strpos($tpl, '@sha256') !== false ? xid("old-$n") : xid("new-$n");
      return null;
    }
    if (preg_match('# pull (\S+)#', $j, $m)) { foreach ($o['pull_fail'] as $n) if (strpos($m[1], "x/$n") === 0) return ['code' => 1, 'err' => 'pull access denied']; return ['out' => 'ok']; }
    if (preg_match('#image inspect --format \{\{\.Id\}\} \{\{\.Size\}\}#', $j)) {
      $o2 = []; foreach ($a as $x) if (strpos($x, 'sha256:') === 0) $o2[] = $x . ' ' . ($x === xid('dang') ? 5000000 : 20000000);
      return ['out' => implode("\n", $o2)];
    }
    if (preg_match('#image inspect --format \{\{\.Id\}\} (\S+)#', $j, $m)) {
      if (strpos($m[1], '@sha256') !== false) return ['out' => xid('old-' . preg_replace('#^x/(\w+)@.*#', '$1', $m[1]))];
      if (strpos($m[1], 'sha256:') === 0) return ['out' => $m[1]];
      return ['out' => xid('new-' . preg_replace('#^x/(\w+):.*#', '$1', $m[1]))];
    }
    if (strpos($j, 'RepoDigests') !== false) { foreach ($names as $n) if (strpos($j, xid("old-$n")) !== false) return ['out' => json_encode(["x/$n@sha256:olddigest-$n"])]; return ['out' => '[]']; }
    if (preg_match('#image inspect --format \{\{json \.Config\}\} (\S+)#', $j, $m)) {
      $v = $ver($m[1]); return ['out' => json_encode(['Labels' => $v ? ['org.opencontainers.image.version' => $v, 'org.opencontainers.image.source' => 'https://github.com/acme/thing'] : [], 'Env' => []])];
    }
    if (strpos($j, 'image ls') !== false) {
      $lines = [xid('dang') . '|<none>|<none>|2 weeks ago', xid('unused') . '|x/old|1.0|3 months ago', xid('old-app') . '|x/app|<none>|1 day ago'];
      return ['out' => implode("\n", $lines)];
    }
    if (preg_match('# image rm (\S+)#', $j, $m)) { $st['rm'][] = $m[1]; return null; }
    if (preg_match('# rm (\S+)$#', $j, $m)) { $st['rmc'][] = $m[1]; return null; }
    return null;
  });
}
function xcfg(?callable $f = null) {
  $c = dau_defaults(); $c['global']['cleanup'] = false; $c['global']['verify']['timeout'] = 10; $c['global']['delay'] = 0;
  foreach (['db', 'app', 'cache'] as $n) $c['containers'][$n] = ['policy' => 'auto'];
  if ($f) $f($c); dau_save_config($c); return $c;
}
function xupdate_order() { return array_map(function ($a) { return rawurldecode(end($a)); }, calls_matching('update_container')); }

t('extras: new settings are validated', function () {
  $c = dau_sanitize_config(['quiet' => ['enabled' => true, 'start' => '25:00', 'end' => '06:30', 'urgent' => false], 'ui' => ['theme' => 'neon', 'accent' => 'red', 'css' => '@import url(x);'],
    'discord' => ['ui_url' => 'javascript:alert(1)', 'action_links' => false], 'containers' => ['a' => ['after' => ['b', '../x', 'a b', 'c'], 'watch_major' => true]]], dau_defaults());
  eq($c['quiet']['start'], '23:00', 'bad time ignored'); eq($c['quiet']['end'], '06:30'); eq($c['quiet']['urgent'], false);
  eq($c['ui']['theme'], 'dark'); eq($c['ui']['accent'], '#f15a2c'); eq($c['ui']['css'], '', 'dangerous css refused');
  eq($c['discord']['ui_url'], ''); eq($c['discord']['action_links'], false);
  eq($c['containers']['a']['after'], ['b', 'c']); eq($c['containers']['a']['watch_major'], true);
  $c = dau_sanitize_config(['ui' => ['theme' => 'light', 'accent' => '#AABBCC', 'css' => '.dau .btn{border-radius:12px}'], 'discord' => ['ui_url' => 'http://192.168.1.5:8080/']], dau_defaults());
  eq($c['ui']['theme'], 'light'); eq($c['ui']['accent'], '#aabbcc'); eq($c['ui']['css'], '.dau .btn{border-radius:12px}'); eq($c['discord']['ui_url'], 'http://192.168.1.5:8080');
  eq(dau_sanitize_config(['ui' => ['css' => '</style><script>x</script>']], dau_defaults())['ui']['css'], '', 'no markup in css');
});

t('extras: dependency order', function () {
  $mk = function ($n) { return [['name' => $n], [], false]; };
  $cfg = dau_defaults(); $cfg['containers'] = ['app' => ['policy' => 'auto', 'after' => ['db']], 'web' => ['policy' => 'auto', 'after' => ['app']], 'db' => ['policy' => 'auto'], 'cache' => ['policy' => 'auto']];
  $o = array_map(function ($t) { return $t[0]['name']; }, dau_docker_order([$mk('web'), $mk('app'), $mk('cache'), $mk('db')], $cfg));
  ok(array_search('db', $o) < array_search('app', $o) && array_search('app', $o) < array_search('web', $o), 'db, app, web: ' . implode(',', $o));
  $cfg['containers']['db']['after'] = ['web'];            // cycle: falls back, nothing lost
  eq(count(dau_docker_order([$mk('web'), $mk('app'), $mk('cache'), $mk('db')], $cfg)), 4);
  $cfg['containers']['app']['after'] = ['missing', 'app'];  // unknown / self dependencies are ignored
  eq(count(dau_docker_order([$mk('app')], $cfg)), 1);
});

t('extras: containers update in dependency order and a failed dependency holds back its dependants', function () {
  xenv(); xcfg(function (&$c) { $c['containers']['app']['after'] = ['db']; $c['containers']['db']['watch_major'] = false; });
  eq(dau_job_main('docker', ['names' => ['app', 'cache', 'db']]), 0);
  $o = xupdate_order(); eq(count($o), 3); ok(array_search('db', $o) < array_search('app', $o), 'db before app: ' . implode(',', $o));
  /* db cannot be pulled: app must not be touched, cache is independent */
  xenv(['pull_fail' => ['db']]); xcfg(function (&$c) { $c['containers']['app']['after'] = ['db']; });
  dau_job_main('docker', ['names' => ['app', 'cache', 'db']]);
  $o = xupdate_order(); ok(!in_array('db', $o) && !in_array('app', $o) && in_array('cache', $o), 'only cache: ' . implode(',', $o));
  $h = dau_history_load(); $failed = array_filter($h, function ($e) { return $e['result'] === 'failed'; }); eq(count($failed), 1);
  has(file_get_contents(DAU_LOG), 'dependency failed');
});

t('extras: skip this version (docker, plugin, os)', function () {
  $cfg = dau_defaults(); $cfg['containers']['app'] = ['policy' => 'auto']; $st = dau_state_defaults();
  $c = ['name' => 'app', 'image' => 'x/app:latest', 'state' => 'running', 'managed' => true, 'update' => true, 'local' => 'sha256:l', 'remote' => 'sha256:r1'];
  $st['docker']['skipped']['app'] = 'sha256:r1';
  eq(dau_docker_eval($cfg, $st, $c, 1000)['state'], 'skipped'); eq(dau_docker_eval($cfg, $st, $c, 1000, true)['state'], 'ready', 'a manual update overrides the skip');
  dau_docker_track($st, [$c], 1000); eq($st['docker']['skipped']['app'], 'sha256:r1', 'same image keeps the skip');
  $c['remote'] = 'sha256:r2'; dau_docker_track($st, [$c], 2000); ok(!isset($st['docker']['skipped']['app']), 'a newer image clears it');
  $p = ['file' => 'a.plg', 'name' => 'a', 'version' => '1', 'self' => false, 'url' => 'https://x.y/a.plg', 'path' => '/x'];
  $cfg['plugins']['auto_update'] = true; $cfg['plugins']['items']['a.plg'] = ['policy' => 'auto']; $cfg['plugins']['delay_hours'] = 0;
  $st['plugins']['skipped']['a.plg'] = '2'; eq(dau_plugin_eval($cfg, $st, $p, '2', 1000)['state'], 'skipped'); eq(dau_plugin_eval($cfg, $st, $p, '3', 1000)['state'], 'ready');
  $st['os']['available'] = '7.4.0'; $st['os']['skipped'] = '7.4.0'; $cfg['os']['mode'] = 'install'; $cfg['os']['confirmed'] = 'install';
  eq(dau_os_eval($cfg, $st, 1000)['state'], 'skipped'); eq(dau_os_eval($cfg, $st, 1000, true)['state'], 'ready');
});

t('extras: major-version jump is held back for watched containers, allowed explicitly, and a rollback is recorded', function () {
  xenv(); xcfg(function (&$c) { $c['containers']['db']['watch_major'] = true; });
  dau_job_main('docker', ['names' => ['db', 'cache']]);
  eq(xupdate_order(), ['cache'], 'db was not applied, cache was');
  $st = dau_state_load(); eq($st['docker']['major']['db']['from'], '15.4'); eq($st['docker']['major']['db']['to'], '16.1');
  eq($st['docker']['rollback']['cache']['ref'], 'x/cache@sha256:olddigest-cache', 'rollback info saved after the update');
  $h = dau_history_load(); $b = array_values(array_filter($h, function ($e) { return $e['name'] === 'db'; }))[0]; eq($b['result'], 'blocked'); has($b['error'], 'Major version change (15.4 -> 16.1)');
});

t('extras: allow major, rollback, pin and unpin', function () {
  xenv(); xcfg(function (&$c) { $c['containers']['db']['watch_major'] = true; });
  dau_job_main('docker', ['names' => ['db'], 'allow_major' => ['db']]);
  eq(xupdate_order(), ['db']); $st = dau_state_load(); $r = $st['docker']['rollback']['db']; eq($r['from'], '15.4'); eq($r['to'], '16.1');
  eq($GLOBALS['XS']['ids']['db'], xid('new-db'));
  /* roll back */
  $GLOBALS['CALLS'] = [];
  eq(dau_job_main('rollback', ['name' => 'db']), 0);
  $tpl = file_get_contents(DAU_TEMPLATES . '/my-db.xml'); has($tpl, '<Repository>x/db@sha256:olddigest-db</Repository>');
  eq($GLOBALS['XS']['ids']['db'], xid('old-db'), 'container recreated on the old image');
  $st = dau_state_load(); ok(isset($st['docker']['pinned']['db'])); eq($st['docker']['pinned']['db']['repository'], 'x/db:latest'); eq($st['docker']['skipped']['db'], 'sha256:r-db'); ok(!isset($st['docker']['rollback']['db']));
  $h = dau_history_load(); eq($h[0]['action'], 'rollback'); eq($h[0]['result'], 'rolled_back');
  ok(count(glob(DAU_DIR . '/rollback/my-db.xml.*.bak')) === 1, 'template backup kept');
  $cfg = dau_load_config(); $c = ['name' => 'db', 'image' => 'x/db:latest', 'state' => 'running', 'managed' => true, 'update' => true, 'local' => 'a', 'remote' => 'sha256:r-db'];
  eq(dau_docker_eval($cfg, $st, $c, 1000)['state'], 'pinned');
  /* unpin restores the template, not the container */
  $r = dau_docker_unpin($st, 'db'); ok($r['ok']); has(file_get_contents(DAU_TEMPLATES . '/my-db.xml'), '<Repository>x/db:latest</Repository>'); ok(!isset($st['docker']['pinned']['db']));
  ok(!dau_docker_unpin($st, 'db')['ok'], 'second unpin refused');
  /* nothing to roll back to */
  $GLOBALS['CALLS'] = []; dau_job_main('rollback', ['name' => 'app']); eq(count(calls_matching('update_container')), 0);
  eq(dau_history_load()[0]['result'], 'blocked');
});

t('extras: a rollback that does not come up restores the template and reports it', function () {
  xenv(); xcfg();
  dau_job_main('docker', ['names' => ['app']]);
  $GLOBALS['XS']['applied']['app'] = false;
  xenv(['verify_fail' => ['app']]); /* fresh mock; keep the saved rollback record in state */
  $st = dau_state_load(); $st['docker']['rollback']['app'] = ['ref' => 'x/app@sha256:olddigest-app', 'prev_id' => xid('old-app'), 'prev_digest' => 'sha256:olddigest-app', 'new_digest' => 'sha256:r-app', 'image' => 'x/app:latest', 'at' => 1]; dau_state_save($st);
  file_put_contents(DAU_TEMPLATES . '/my-app.xml', '<Container><Repository>x/app:latest</Repository></Container>');
  dau_job_main('rollback', ['name' => 'app']);
  has(file_get_contents(DAU_TEMPLATES . '/my-app.xml'), '<Repository>x/app:latest</Repository>', 'template restored');
  $st = dau_state_load(); ok(!isset($st['docker']['pinned']['app']), 'not pinned'); ok(isset($st['docker']['rollback']['app']), 'record kept for another try');
  eq(dau_history_load()[0]['result'], 'failed');
  /* a bad reference is never written into a template */
  ok(!dau_valid_image_ref('x/app@sha256:aa"><evil/>') && !dau_valid_image_ref('-bad') && dau_valid_image_ref('lscr.io/x/app@sha256:abc'));
});

t('extras: cleanup scan and removal', function () {
  xenv(['orphans' => true]); xcfg();
  $s = dau_cleanup_scan(); ok($s['ok'], json_encode($s));
  $ids = array_column($s['images'], 'id'); ok(in_array(xid('dang'), $ids) && in_array(xid('unused'), $ids));
  eq($s['containers'][0]['name'], 'leftover'); ok($s['total'] > 0); has($s['total_text'], 'MB');
  $dang = array_values(array_filter($s['images'], function ($i) { return $i['dangling']; }))[0]; eq($dang['size'], 5000000);
  /* the preview removes nothing */
  eq($GLOBALS['XS']['rm'], []); eq($GLOBALS['XS']['rmc'], []);
  $r = dau_cleanup_run([xid('dang'), xid('old-db'), xid('unused')], ['leftover', 'app']);
  eq($GLOBALS['XS']['rm'], [xid('dang'), xid('unused')], 'an image that is in use is refused'); eq($GLOBALS['XS']['rmc'], ['leftover'], 'a managed/running container is refused');
  eq(count($r['skipped']), 2); eq($r['failed'], []); ok($r['freed'] > 0 && $r['size_removed'] === $r['freed']);
  $rm = calls_matching(' rm '); foreach ($rm as $a) ok(!in_array('-f', $a, true) && !in_array('--force', $a, true), 'never forced');
});

t('extras: quiet hours hold notifications, urgent ones pass, the queue is sent afterwards', function () {
  $cfg = dau_defaults(); $cfg['quiet'] = ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '23:00', 'end' => '07:00', 'urgent' => true];
  $t23 = gmmktime(23, 30, 0, 10, 26, 2026); $t12 = gmmktime(12, 0, 0, 10, 26, 2026);
  ok(dau_quiet_now($cfg, $t23) && dau_quiet_now($cfg, gmmktime(3, 0, 0, 10, 27, 2026)) && !dau_quiet_now($cfg, $t12)); ok(!dau_quiet_now(dau_defaults(), $t23));
  $ev = [['kind' => 'available', 'type' => 'docker', 'name' => 'a'], ['kind' => 'failed', 'type' => 'docker', 'name' => 'b'], ['kind' => 'available', 'type' => 'docker', 'name' => 'c', 'silent' => true]];
  list($now, $hold) = dau_quiet_split($cfg, $ev, $t23); eq(array_column($now, 'name'), ['b', 'c']); eq(array_column($hold, 'name'), ['a']);
  $cfg['quiet']['urgent'] = false; list($now, $hold) = dau_quiet_split($cfg, $ev, $t23); eq(array_column($hold, 'name'), ['a', 'b']);
  list($now, $hold) = dau_quiet_split($cfg, $ev, $t12); eq(count($now), 3); eq($hold, []);
  /* end to end: a check during quiet hours sends nothing, the next run after it flushes the queue */
  xenv(); $GLOBALS['SENT'] = [];
  $GLOBALS['DAU_HTTP_MOCK'] = function ($u, $body) { $GLOBALS['SENT'][] = json_decode($body, true); return ['code' => 204]; };
  dau_secret_set('discord_url', 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyzABCDEF0123456789');
  xcfg(function (&$c) use ($t23) { $c['discord']['enabled'] = true; $c['notify_unraid']['docker'] = true; $c['quiet'] = ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '23:00', 'end' => '07:00', 'urgent' => true]; });
  $GLOBALS['DAU_NOW'] = $t23; $GLOBALS['NOTIFY'] = [];
  dau_job_main('check');
  eq($GLOBALS['SENT'], [], 'nothing sent during quiet hours'); eq($GLOBALS['NOTIFY'], [], 'no Unraid notification either');
  $q = dau_state_load()['queue']; ok(count($q) >= 3, 'held: ' . count($q));
  $GLOBALS['DAU_NOW'] = $t23 + 9 * 3600;       // 08:30, quiet hours over
  eq(dau_job_main('tick'), 0);
  ok(count($GLOBALS['SENT']) >= 1, 'flushed afterwards'); eq(dau_state_load()['queue'], []);
  has(json_encode($GLOBALS['SENT']), 'Update available');
});

t('extras: update statistics over time', function () {
  $st = dau_state_defaults(); $GLOBALS['DAU_NOW'] = 1793000000;
  dau_stats_record($st, 'docker', true, 30, 'app'); dau_stats_record($st, 'docker', true, 20, 'app'); dau_stats_record($st, 'docker', false, 0, 'db'); dau_stats_record($st, 'plugin', true, 5, 'x');
  $GLOBALS['DAU_NOW'] += 86400; dau_stats_record($st, 'docker', true, 10, 'cache');
  $s = dau_stats_summary($st);
  eq(count($s['daily']), 30); eq($s['daily'][29]['ok'], 1); eq($s['daily'][28]['ok'], 3); eq($s['daily'][28]['failed'], 1);
  eq($s['top'][0]['name'], 'app'); eq($s['top'][0]['ok'], 2); eq($s['top_failing'][0]['name'], 'db'); eq($s['by_type']['docker']['ok'], 3);
  ok(!isset($s['by_type']['daily']) && !isset($s['by_type']['per']));
  for ($i = 0; $i < 100; $i++) { $GLOBALS['DAU_NOW'] += 86400; dau_stats_record($st, 'os', true, 0); }
  ok(count($st['stats']['daily']) <= 90, 'bounded');
});

t('extras: release notes and links in notifications', function () {
  $plg = '<?xml version="1.0"?><PLUGIN name="a" version="2026.10.30"><CHANGES>' . "\n###2026.10.30\n- newest change\n###2026.10.29\n- middle change\n###2026.10.01\n- old change\n" . '</CHANGES></PLUGIN>';
  @mkdir(DAU_PLUGTMP, 0755, true); file_put_contents(DAU_PLUGTMP . '/a.plg', $plg);
  $n = dau_plugin_notes('a.plg', '2026.10.20'); has($n, 'newest change'); has($n, 'middle change'); lacks($n, 'old change');
  has(dau_plugin_notes('a.plg', '2026.10.30'), 'newest change', 'falls back to the latest entry'); eq(dau_plugin_notes('../a.plg', '1'), '');
  eq(dau_plugin_notes('missing.plg', '1'), '');
  xenv(); $c = ['image_id' => xid('new-db')]; $nt = dau_docker_notes($c); eq($nt['version'], '16.1'); eq($nt['link'], 'https://github.com/acme/thing/releases');
  $cfg = dau_defaults(); $cfg['discord']['ui_url'] = 'http://192.168.50.50';
  $l = dau_action_links($cfg, ['kind' => 'available', 'type' => 'docker', 'name' => 'my app', 'reason' => 'Marked critical: waiting for your approval']);
  has($l, '[Update now](http://192.168.50.50/Settings/UnraidSwissManager?act=update&type=docker&name=my+app)'); has($l, 'act=approve'); has($l, 'Skip this version');
  eq(dau_action_links($cfg, ['kind' => 'updated', 'type' => 'docker', 'name' => 'a']), '', 'only for update-available');
  $cfg['discord']['action_links'] = false; eq(dau_action_links($cfg, ['kind' => 'available', 'type' => 'docker', 'name' => 'a']), '');
  $cfg['discord']['action_links'] = true; $cfg['discord']['ui_url'] = ''; has(dau_action_links($cfg, ['kind' => 'available', 'type' => 'os', 'name' => 'Unraid OS']), 'http://Tower/Settings');
  $e = dau_discord_event_embed(['kind' => 'available', 'type' => 'plugin', 'name' => 'a', 'installed' => '1', 'available' => '2', 'notes' => "###2\n- fix", 'ts' => 1793000000], 'Tower');
  $f = array_column($e['fields'], 'value', 'name'); has($f['What changed'], '- fix'); has($f['Actions'], 'Update now');
  $e = dau_discord_event_embed(['kind' => 'major_jump', 'type' => 'docker', 'name' => 'db', 'reason' => 'x', 'ts' => 1793000000], 'Tower'); has($e['title'], 'Major version');
  $e = dau_discord_event_embed(['kind' => 'rolled_back', 'type' => 'docker', 'name' => 'db', 'ts' => 1793000000], 'Tower'); has($e['title'], 'Rolled back');
});
