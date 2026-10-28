<?php
/* End-to-end job tests with every external command mocked. */

/** Docker+plugin mock combined: one container "app" with an update, optional unraid notify capture. */
function job_env(array $o = []) {
  $o += ['pull_ok' => true, 'pull_err' => '', 'final' => ['Status' => 'running', 'Running' => true, 'Health' => 'healthy']];
  $GLOBALS['CALLS'] = []; $GLOBALS['NOTIFY'] = [];
  @mkdir(DAU_DM . '/scripts', 0755, true);
  file_put_contents(DAU_DM . '/scripts/update_container', "x"); file_put_contents(DAU_DM . '/scripts/dockerupdate', "x");
  @mkdir(dirname(DAU_NOTIFY), 0755, true); file_put_contents(DAU_NOTIFY, 'x');
  file_put_contents(DAU_TEMPLATES . '/my-app.xml', '<Container/>'); file_put_contents(DAU_TEMPLATES . '/my-db.xml', '<Container/>');
  file_put_contents(DAU_UPDSTATUS, json_encode(['x/app:latest' => ['local' => 'sha256:l1', 'remote' => 'sha256:r1', 'status' => 'false'], 'x/db:latest' => ['local' => 'sha256:l2', 'remote' => 'sha256:l2', 'status' => 'true']]));
  $S = ['ids' => ['app' => 'sha256:old', 'db' => 'sha256:dbid'], 'applied' => []];
  $GLOBALS['JS'] = &$S;
  mock_exec(function ($a) use (&$S, $o) {
    $j = implode(' ', $a);
    if (strpos($j, 'notify') !== false) { $GLOBALS['NOTIFY'][] = $a; return null; }
    if (strpos($j, 'dockerupdate') !== false) return null;
    if (preg_match('# ps -aq#', $j)) return ['out' => "id1\nid2\n"];
    if (preg_match('# inspect id1 id2#', $j)) {
      $mk = function ($n, $img) use (&$S, $o) {
        $ap = !empty($S['applied'][$n]);
        return ['Name' => "/$n", 'Image' => $S['ids'][$n], 'RestartCount' => 0, 'Config' => ['Image' => $img, 'Labels' => [], 'Env' => []],
                'State' => ['Status' => 'running', 'Running' => true, 'ExitCode' => 0, 'StartedAt' => 'x'] + ($ap && $o['final']['Health'] ? ['Health' => ['Status' => $o['final']['Health']]] : []), 'Mounts' => []];
      };
      return ['out' => json_encode([$mk('app', 'x/app:latest'), $mk('db', 'x/db:latest')])];
    }
    if (preg_match('# inspect (app|db)$#', $j, $m)) {
      $n = $m[1]; $ap = !empty($S['applied'][$n]);
      return ['out' => json_encode([['Name' => "/$n", 'Image' => $S['ids'][$n], 'RestartCount' => 0, 'Config' => ['Image' => "x/$n:latest", 'Env' => []],
        'State' => ['Status' => 'running', 'Running' => true, 'ExitCode' => 0, 'StartedAt' => 'x'] + ($ap && $o['final']['Health'] ? ['Health' => ['Status' => $o['final']['Health']]] : []), 'Mounts' => []]])];
    }
    if (strpos($j, 'update_container') !== false) { $n = rawurldecode(end($a)); $S['applied'][$n] = true; $S['ids'][$n] = 'sha256:new-' . $n; return null; }
    if (preg_match('# pull #', $j)) return $o['pull_ok'] ? ['out' => 'ok'] : ['code' => 1, 'err' => $o['pull_err']];
    if (strpos($j, 'image inspect --format {{.Id}}') !== false) return ['out' => 'sha256:new-' . (strpos($j, 'x/db') !== false ? 'db' : 'app')];
    if (strpos($j, 'RepoDigests') !== false) return ['out' => '[]'];
    if (strpos($j, 'image rm') !== false) { $S['rm'][] = end($a); return null; }
    return null;
  });
  // unraid notify goes through dau_unraid_notify -> dau_run, so the mock above records it
}
function job_set_update($app = true) {
  file_put_contents(DAU_UPDSTATUS, json_encode(['x/app:latest' => ['local' => 'sha256:l1', 'remote' => 'sha256:r1', 'status' => $app ? 'true' : 'false'], 'x/db:latest' => ['local' => 'sha256:l2', 'remote' => 'sha256:l2', 'status' => 'false']]));
}
function job_cfg(callable $f) { $c = dau_defaults(); $f($c); dau_save_config($c); return $c; }

t('jobs: scheduled run updates only selected policy=auto containers', function () {
  job_env(); job_set_update();
  job_cfg(function (&$c) { $c['global']['enabled'] = true; $c['global']['update_delay_hours'] = 0; $c['containers']['app'] = ['policy' => 'auto']; $c['containers']['db'] = ['policy' => 'ignore']; });
  $st = dau_state_load(); dau_slots_register($st, ['d:global'], 1793000000 - 86400 * 2); dau_state_save($st);
  $GLOBALS['DAU_NOW'] = 1793000000 + 0;   // 07:33 UTC; schedule 04:00 daily -> occurrence 3.5h ago, within catch-up
  eq(dau_job_main('tick'), 0);
  eq(count(calls_matching('update_container')), 1); has(implode(' ', calls_matching('update_container')[0]), 'app');
  $h = dau_history_load(); eq($h[0]['result'], 'updated'); eq($h[0]['verification']['result'], 'healthy'); eq($h[0]['trigger'], 'scheduled');
  eq(dau_state_load()['stats']['docker']['ok'], 1);
  eq(count(calls_matching('image rm')), 1);
  // same tick again: slot already ran
  $before = count($GLOBALS['CALLS']); dau_job_main('tick'); eq(count(calls_matching('update_container')), 1);
});

t('jobs: update delay defers, then applies once after the delay, tick-driven', function () {
  job_env(); job_set_update();
  job_cfg(function (&$c) { $c['global']['enabled'] = true; $c['global']['update_delay_hours'] = 24; $c['containers']['app'] = ['policy' => 'auto']; });
  $st = dau_state_load(); dau_slots_register($st, ['d:global'], 1793000000 - 86400 * 2); dau_state_save($st);
  dau_job_main('tick'); eq(count(calls_matching('update_container')), 0, 'delayed');
  $d = dau_state_load()['docker']; ok(isset($d['deferred']['app'])); $first = $d['seen']['app']['first'];
  $GLOBALS['DAU_NOW'] += 3600; dau_job_main('tick'); eq(count(calls_matching('update_container')), 0);
  $GLOBALS['DAU_NOW'] += 86400; dau_job_main('tick'); eq(count(calls_matching('update_container')), 1, 'applied after delay');
  eq($first, 1793000000);
});

t('jobs: notify policy never updates; availability announced once', function () {
  job_env(); job_set_update();
  job_cfg(function (&$c) { $c['containers']['app'] = ['policy' => 'notify']; $c['notify_unraid']['docker'] = true; });
  dau_job_main('check'); dau_job_main('check');
  eq(count(calls_matching('update_container')), 0);
  eq(count(array_filter($GLOBALS['CALLS'], function ($a) { return strpos(implode(' ', $a), 'notify') !== false && strpos(implode(' ', $a), 'app') !== false; })), 1, 'one native notification');
});

t('jobs: manual update overrides policy/delay, shows in history as manual', function () {
  job_env(); job_set_update(); job_cfg(function (&$c) { $c['containers']['app'] = ['policy' => 'ignore']; $c['global']['update_delay_hours'] = 72; });
  dau_job_main('docker', ['names' => ['app']]);
  eq(count(calls_matching('update_container')), 1); eq(dau_history_load()[0]['trigger'], 'manual');
});

t('jobs: transient pull failure is retried (bounded), container untouched', function () {
  job_env(['pull_ok' => false, 'pull_err' => 'TLS handshake timeout']); job_set_update();
  job_cfg(function (&$c) { $c['global']['enabled'] = true; $c['global']['retry'] = ['max' => 1, 'interval_min' => 15]; $c['containers']['app'] = ['policy' => 'auto']; $c['global']['update_delay_hours'] = 0; });
  $st = dau_state_load(); dau_slots_register($st, ['d:global'], 1793000000 - 86400 * 2); dau_state_save($st);
  dau_job_main('tick'); eq(count(calls_matching('update_container')), 0);
  $s = dau_state_load(); eq($s['docker']['retry']['app']['attempts'], 1); eq(dau_history_load()[0]['result'], 'failed');
  $GLOBALS['DAU_NOW'] += 5 * 60; dau_job_main('tick'); eq(count(calls_matching(' pull ')), 1, 'not before the interval');
  $GLOBALS['DAU_NOW'] += 16 * 60; dau_job_main('tick'); eq(count(calls_matching(' pull ')), 2);
  $GLOBALS['DAU_NOW'] += 3600; dau_job_main('tick'); eq(count(calls_matching(' pull ')), 2, 'exhausted: no more attempts');
  ok(!empty(dau_state_load()['docker']['retry']['app']['exhausted']));
});

t('jobs: failed verification is reported, old image kept, no retry of an applied update', function () {
  job_env(['final' => ['Status' => 'running', 'Running' => true, 'Health' => 'unhealthy']]); job_set_update();
  job_cfg(function (&$c) { $c['containers']['app'] = ['policy' => 'auto']; });
  dau_job_main('docker', ['names' => ['app']]);
  $h = dau_history_load()[0]; eq($h['result'], 'failed'); eq($h['verification']['result'], 'unhealthy');
  eq(count(calls_matching('image rm')), 0); eq(count(calls_matching('update_container')), 1);
});

t('jobs: lock prevents concurrent jobs; stop flag halts remaining work', function () {
  job_env(); job_set_update();
  $l = dau_lock_try(); eq(dau_job_main('check'), 3); dau_lock_release($l);
  job_cfg(function (&$c) { $c['containers']['app'] = ['policy' => 'auto']; $c['containers']['db'] = ['policy' => 'auto']; });
  file_put_contents(DAU_UPDSTATUS, json_encode(['x/app:latest' => ['local' => 'a', 'remote' => 'b', 'status' => 'true'], 'x/db:latest' => ['local' => 'c', 'remote' => 'd', 'status' => 'true']]));
  $GLOBALS['DAU_SPAWN_MOCK'] = null;
  // stop flag is cleared at job end, so create it from inside the mocked first update
  $orig = $GLOBALS['DAU_EXEC_MOCK'];
  $GLOBALS['DAU_EXEC_MOCK'] = function ($a, $i) use ($orig) { $r = $orig($a, $i); if (strpos(implode(' ', $a), 'update_container') !== false) touch(DAU_STOPFLAG); return $r; };
  $GLOBALS['DAU_SLEPT'] = 0;
  dau_job_main('docker', ['names' => ['app', 'db']]);
  eq(count(calls_matching('update_container')), 1, 'second container not started after stop');
  ok(!is_file(DAU_STOPFLAG), 'flag cleared afterwards');
});

t('jobs: interrupted job is recorded after a crash', function () {
  job_env(); job_set_update(); $st = dau_state_load(); $st['current'] = ['type' => 'docker', 'name' => 'app', 'started' => 1]; dau_state_save($st);
  dau_job_main('check'); $h = dau_history_load(); eq($h[0]['result'], 'interrupted'); eq(dau_state_load()['current'], null);
});

t('jobs: plugin job - auto off by default means notify only', function () {
  plg_env('2'); job_cfg(function (&$c) {});
  dau_job_main('check'); eq(count(calls_matching('plugin update')), 0);
  eq(dau_state_load()['plugins']['available'], [DAU_SELF_PLG => '2', 'foo.plg' => '2']);
  $c = dau_load_config(); $c['plugins']['auto_update'] = true; $c['plugins']['default_policy'] = 'auto'; $c['plugins']['delay_hours'] = 0; dau_save_config($c);
  dau_job_main('plugins', ['names' => []]);   // manual with no names = nothing selected
  eq(count(calls_matching('plugin update')), 0);
  dau_job_main('plugins', ['names' => ['foo.plg']]); eq(count(calls_matching('plugin update')), 1);
});

t('jobs: cron is one dispatcher line, removed when nothing scheduled', function () {
  $c = dau_defaults(); $c['global']['enabled'] = false; $c['plugins']['check_enabled'] = false; $c['os']['check_enabled'] = false;
  dau_cron_apply($c); ok(!is_file(DAU_CRON));
  $c['global']['enabled'] = true; $c['containers']['a'] = ['policy' => 'auto']; dau_cron_apply($c);
  $t = file_get_contents(DAU_CRON); eq(substr_count($t, 'run.php --tick'), 1);
});

t('jobs: install migrates v1 config once and keeps a backup', function () {
  file_put_contents(DAU_CFG, json_encode(['global' => ['enabled' => true, 'frequency' => 'weekly', 'time' => '03:00', 'weekday' => 2, 'monthday' => 1, 'notify' => true, 'skip_stopped' => false, 'delay' => 0], 'containers' => ['app' => ['auto' => true, 'notify' => false]]]));
  eq(dau_install(), 'migrated'); $c = dau_load_config($s); eq($s, 'ok'); eq($c['containers']['app']['policy'], 'auto'); eq($c['containers']['app']['notify'], false);
  ok(is_file(dirname(DAU_CFG) . '/config.v1.backup.json'));
  eq(dau_install(), 'ok');
});
