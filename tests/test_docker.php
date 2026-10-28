<?php
/* Docker module tests: all docker/Unraid commands are mocked. */

function fake_docker_env(array $o = []) {
  $o += ['image_id' => 'sha256:old', 'new_id' => 'sha256:new', 'pull_ok' => true, 'pull_err' => '', 'apply_changes' => true,
         'final' => ['Status' => 'running', 'Running' => true, 'Health' => null], 'running' => true];
  @mkdir(DAU_DM . '/scripts', 0755, true);
  file_put_contents(DAU_DM . '/scripts/update_container', "<?php\n");
  file_put_contents(DAU_TEMPLATES . '/my-app.xml', '<Container/>');
  $GLOBALS['CALLS'] = [];
  $S = ['id' => $o['image_id'], 'applied' => false];
  $GLOBALS['FD'] = &$S;
  mock_exec(function ($a) use (&$S, $o) {
    $j = implode(' ', $a);
    if (strpos($j, 'update_container') !== false) { if ($o['apply_changes']) $S['id'] = $o['new_id']; $S['applied'] = true; return ['code' => 0]; }
    if (preg_match('# pull #', $j)) return $o['pull_ok'] ? ['code' => 0, 'out' => 'Digest: sha256:abc'] : ['code' => 1, 'err' => $o['pull_err']];
    if (strpos($j, 'image inspect --format {{.Id}}') !== false) return ['out' => $o['new_id']];
    if (strpos($j, 'image inspect --format {{json .RepoDigests}}') !== false) return ['out' => '["x/app@sha256:aaaa"]'];
    if (strpos($j, 'image inspect --format {{.Size}}') !== false) return ['out' => '1000'];
    if (strpos($j, 'image rm') !== false) { $S['rm'][] = end($a); return ['code' => 0]; }
    if (preg_match('# inspect app$#', $j)) {
      $st = $S['applied'] ? $o['final'] : ['Status' => $o['running'] ? 'running' : 'exited', 'Running' => $o['running']];
      $d = ['Name' => '/app', 'Image' => $S['id'], 'RestartCount' => 0, 'Config' => ['Image' => 'x/app:latest', 'Env' => ['PASS=secret']],
            'State' => ['Status' => $st['Status'], 'Running' => $st['Running'], 'ExitCode' => 1, 'StartedAt' => 'x'] + (!empty($st['Health']) ? ['Health' => ['Status' => $st['Health']]] : []),
            'Mounts' => []];
      return ['out' => json_encode([$d])];
    }
    return ['code' => 0];
  });
  return $S;
}
function app_c() { return ['name' => 'app', 'image' => 'x/app:latest', 'image_id' => 'sha256:old', 'state' => 'running', 'managed' => true, 'update' => true, 'local' => 'sha256:l', 'remote' => 'sha256:r1']; }
function dcfg(array $over = []) { $c = dau_defaults(); $c['global']['cleanup'] = true; $c['containers']['app'] = ['policy' => 'auto'] ; foreach ($over as $k => $v) $c['global'][$k] = $v; return $c; }

t('docker: image key + update info', function () {
  eq(dau_image_key('nginx'), 'nginx:latest'); eq(dau_image_key('a/b:1'), 'a/b:1'); eq(dau_image_key('localhost:5000/x'), 'localhost:5000/x:latest');
  $m = ['x/app:latest' => ['local' => 'a', 'remote' => 'b', 'status' => 'false'], 'y:latest' => ['status' => 'undef']];
  eq(dau_update_info('x/app', $m)['flag'], true); eq(dau_update_info('y', $m)['flag'], null); eq(dau_update_info('z', $m)['flag'], null);
});

t('docker: delay clock starts once per digest and is not reset by repeated checks', function () {
  $st = dau_state_defaults(); $c = app_c();
  dau_docker_track($st, [$c], 1000); dau_docker_track($st, [$c], 5000);
  eq($st['docker']['seen']['app']['first'], 1000);
  $c['remote'] = 'sha256:r2'; dau_docker_track($st, [$c], 9000);
  eq($st['docker']['seen']['app']['first'], 9000, 'new digest restarts delay');
  $c['update'] = false; dau_docker_track($st, [$c], 9500);
  ok(!isset($st['docker']['seen']['app']), 'forgotten once up to date');
});

t('docker: eligibility', function () {
  $cfg = dcfg(['update_delay_hours' => 24]); $st = dau_state_defaults(); $c = app_c(); $now = 100000;
  eq(dau_docker_eval($cfg, $st, $c, $now)['state'], 'no_identity');
  dau_docker_track($st, [$c], $now);
  $r = dau_docker_eval($cfg, $st, $c, $now + 3600); eq($r['state'], 'waiting_delay'); eq($r['eta'], $now + 86400);
  eq(dau_docker_eval($cfg, $st, $c, $now + 86400)['state'], 'ready');
  eq(dau_docker_eval($cfg, $st, $c, $now + 10, true)['state'], 'ready', 'manual overrides delay');
  $e = $c; $e['managed'] = false; eq(dau_docker_eval($cfg, $st, $e, $now, true)['state'], 'external');
  $cfg['containers']['app']['policy'] = 'ignore'; eq(dau_docker_eval($cfg, $st, $c, $now + 86400)['state'], 'ignored');
  $cfg['containers']['app']['policy'] = 'notify'; eq(dau_docker_eval($cfg, $st, $c, $now + 86400)['state'], 'notify_only');
  $cfg['containers']['app'] = ['policy' => 'auto', 'critical' => true]; $cfg['global']['update_delay_hours'] = 0;
  eq(dau_docker_eval($cfg, $st, $c, $now)['state'], 'approval');
  $st['docker']['approved']['app'] = 'sha256:r1'; eq(dau_docker_eval($cfg, $st, $c, $now)['state'], 'ready');
  $st['docker']['approved']['app'] = 'sha256:OTHER'; eq(dau_docker_eval($cfg, $st, $c, $now)['state'], 'approval', 'approval is per image');
  $cfg['containers']['app'] = ['policy' => 'auto']; $s = $c; $s['state'] = 'exited'; $cfg['global']['skip_stopped'] = true;
  eq(dau_docker_eval($cfg, $st, $s, $now)['state'], 'stopped');
  $cfg['global']['window'] = ['enabled' => true, 'start' => '01:00', 'end' => '02:00', 'days' => [0,1,2,3,4,5,6]];
  eq(dau_docker_eval($cfg, $st, $c, 1793000000)['state'], 'window_closed');
});

t('docker: successful update with health, cleanup of the old image only', function () {
  $S = fake_docker_env(['final' => ['Status' => 'running', 'Running' => true, 'Health' => 'healthy']]);
  $r = dau_docker_update(dcfg(), 'app', app_c());
  eq($r['result'], 'updated'); eq($r['verification']['result'], 'healthy');
  eq($r['prev']['digest'], 'sha256:aaaa'); eq($GLOBALS['FD']['rm'], ['sha256:old']);
  eq(count(calls_matching('--force')), 0); eq(count(calls_matching('prune')), 0);
});

t('docker: no healthcheck is not a failure', function () {
  fake_docker_env(); $r = dau_docker_update(dcfg(), 'app', app_c());
  eq($r['result'], 'updated'); eq($r['verification']['result'], 'running_no_healthcheck'); ok($r['verification']['ok']);
});

t('docker: unhealthy / crashed / mismatch fail and keep the old image', function () {
  fake_docker_env(['final' => ['Status' => 'running', 'Running' => true, 'Health' => 'unhealthy']]);
  $r = dau_docker_update(dcfg(), 'app', app_c()); eq($r['result'], 'failed'); eq($r['verification']['result'], 'unhealthy'); ok(empty($GLOBALS['FD']['rm']), 'old image kept'); has($r['next'], 'sha256:aaaa');
  fake_docker_env(['final' => ['Status' => 'exited', 'Running' => false, 'Health' => null]]);
  $r = dau_docker_update(dcfg(), 'app', app_c()); eq($r['verification']['result'], 'failed_to_start');
  fake_docker_env(['apply_changes' => false]);
  $r = dau_docker_update(dcfg(), 'app', app_c()); eq($r['verification']['result'], 'image_mismatch'); eq($r['result'], 'failed');
});

t('docker: health still starting at timeout is reported as timeout, update counted as applied', function () {
  fake_docker_env(['final' => ['Status' => 'running', 'Running' => true, 'Health' => 'starting']]);
  $r = dau_docker_update(dcfg(['verify' => ['enabled' => true, 'timeout' => 20]]), 'app', app_c());
  eq($r['verification']['result'], 'timeout'); eq($r['result'], 'updated'); ok(empty($GLOBALS['FD']['rm']), 'no cleanup when unverified');
});

t('docker: stopped container stays stopped', function () {
  fake_docker_env(['running' => false, 'final' => ['Status' => 'exited', 'Running' => false, 'Health' => null]]);
  $r = dau_docker_update(dcfg(), 'app', app_c()); eq($r['verification']['result'], 'not_running_expected'); eq($r['result'], 'updated');
});

t('docker: pull failures leave the container untouched; transient vs permanent', function () {
  fake_docker_env(['pull_ok' => false, 'pull_err' => 'net/http: TLS handshake timeout']);
  $r = dau_docker_update(dcfg(), 'app', app_c()); eq($r['result'], 'failed'); eq($r['stage'], 'pull'); ok($r['transient']); eq(count(calls_matching('update_container')), 0);
  fake_docker_env(['pull_ok' => false, 'pull_err' => 'pull access denied, repository does not exist']);
  $r = dau_docker_update(dcfg(), 'app', app_c()); ok(!$r['transient']); has($r['next'], 'image name');
});

t('docker: already on newest image is not restarted', function () {
  fake_docker_env(['new_id' => 'sha256:old']); $r = dau_docker_update(dcfg(), 'app', app_c());
  eq($r['result'], 'unchanged'); eq(count(calls_matching('update_container')), 0);
});

t('docker: retry is bounded, only for transient, and per digest', function () {
  $cfg = dcfg(['retry' => ['max' => 2, 'interval_min' => 30]]); $st = dau_state_defaults(); $c = app_c(); $res = ['transient' => true, 'error' => 'x'];
  eq(dau_docker_retry_after_failure($cfg, $st, 'app', $c, $res, 1000), 'scheduled'); eq($st['docker']['retry']['app']['next_at'], 1000 + 1800);
  eq(dau_docker_eval($cfg, $st, $c, 1100)['state'], 'retry_wait');
  eq(dau_docker_retry_after_failure($cfg, $st, 'app', $c, $res, 3000), 'scheduled');
  eq(dau_docker_retry_after_failure($cfg, $st, 'app', $c, $res, 6000), 'exhausted');
  eq(dau_docker_eval($cfg, $st, $c, 99999)['state'], 'retry_exhausted');
  $c2 = $c; $c2['remote'] = 'sha256:new2'; eq(dau_docker_eval($cfg, $st, $c2, 99999)['state'], 'ready', 'new image resets retry');
  $st2 = dau_state_defaults(); eq(dau_docker_retry_after_failure($cfg, $st2, 'app', $c, ['transient' => false, 'error' => ''], 1), 'none');
});

t('docker: disk check blocks, backups required/optional', function () {
  fake_docker_env(); $cfg = dcfg();
  $cfg['global']['backup'] = ['enabled' => true, 'dest' => '/mnt/dau-test-bk', 'retention' => 2, 'config' => true, 'image' => false, 'appdata' => false, 'required' => false];
  $cfg['containers']['app']['backup'] = true;
  @mkdir('/mnt/dau-test-bk', 0755, true);
  $r = dau_docker_update($cfg, 'app', app_c());
  eq($r['result'], 'updated'); eq($r['backup']['kinds'], ['config']);
  $f = glob('/mnt/dau-test-bk/app/*-config.json')[0]; $j = json_decode(file_get_contents($f), true);
  has(json_encode($j), '<omitted>'); lacks(file_get_contents($f), 'secret', 'env values never stored');
  fake_docker_env(); $cfg['global']['backup']['dest'] = '/etc/nope'; $cfg['global']['backup']['required'] = true;
  $r = dau_docker_update($cfg, 'app', app_c()); eq($r['result'], 'blocked'); eq($r['stage'], 'backup'); eq(count(calls_matching('pull')), 0);
  fake_docker_env(); $cfg['global']['backup']['required'] = false;
  $r = dau_docker_update($cfg, 'app', app_c()); eq($r['result'], 'updated'); ok(count($r['warnings']) === 1);
  dau_test_rm('/mnt/dau-test-bk');
});

t('docker: backup retention keeps newest sets', function () {
  @mkdir('/mnt/dau-test-bk2/app', 0755, true);
  foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $s) touch("/mnt/dau-test-bk2/app/$s-config.json");
  dau_backup_prune('/mnt/dau-test-bk2/app', 2);
  eq(count(glob('/mnt/dau-test-bk2/app/*')), 2); ok(!is_file('/mnt/dau-test-bk2/app/20260101-000000-config.json'));
  dau_test_rm('/mnt/dau-test-bk2');
});

t('docker: slots', function () {
  $cfg = dau_defaults(); $cfg['global']['enabled'] = true;
  $cfg['containers'] = ['a' => ['policy' => 'auto'], 'b' => ['policy' => 'ignore'], 'c' => ['policy' => 'auto', 'schedule' => ['frequency' => 'weekly', 'time' => '05:00', 'weekday' => 2, 'monthday' => 1]]];
  $s = dau_docker_slots($cfg); eq(count($s), 2); eq($s['global']['names'], ['a']);
  $cfg['global']['enabled'] = false; eq(count(dau_docker_slots($cfg)), 1);
});
