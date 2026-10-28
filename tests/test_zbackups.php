<?php
/* Scheduled backups of appdata and of the boot drive. Real folders under /mnt/dau-test, tar/du/gzip mocked (nothing is archived for real). */

function bkenv(array $bk = [], array $o = []) {
  $root = '/mnt/dau-test/appdata'; $out = '/mnt/dau-test/backups';
  if (is_dir('/mnt/dau-test')) dau_test_rm('/mnt/dau-test');
  @mkdir("$root/plex", 0755, true); @mkdir($out, 0755, true);
  $cfg = dau_defaults(); $cfg['appdata'] = ['roots' => [$root], 'safe_mode' => true];
  $cfg['backups']['appdata'] = array_replace($cfg['backups']['appdata'], ['dest' => $out, 'schedule' => 'weekly', 'keep' => 2], $bk['appdata'] ?? []);
  $cfg['backups']['flash'] = array_replace($cfg['backups']['flash'], ['dest' => $out, 'schedule' => 'monthly', 'keep' => 2], $bk['flash'] ?? []);
  dau_save_config($cfg);
  $GLOBALS['TARS'] = []; $GLOBALS['BK'] = $o;
  mock_exec(function ($a) {
    $b = basename($a[0]);
    if ($b === 'du') return ['out' => ($GLOBALS['BK']['du'] ?? 4096) . "\t" . end($a) . "\n"];
    if ($b === 'gzip') return ['code' => $GLOBALS['BK']['gzip'] ?? 0];
    if ($b === 'tar') {
      $GLOBALS['TARS'][] = $a;
      $code = $GLOBALS['BK']['tar'] ?? 0;
      if ($code === 0 || $code === 1) file_put_contents($a[2], 'archive');
      return ['code' => $code, 'err' => $code > 1 ? 'tar: Cannot write: No space left on device' : ''];
    }
    return null;
  });
  return dau_load_config();
}
function bk_files($kind) { return array_map('basename', array_column(dau_bk_list($kind, '/mnt/dau-test/backups'), 'file')); }

t('backups: settings are validated (keep 1-3, schedule list, destination under /mnt)', function () {
  $c = dau_defaults(); eq($c['backups']['appdata']['schedule'], 'off'); eq($c['backups']['flash']['keep'], 2);
  $n = dau_sanitize_config(['backups' => ['appdata' => ['keep' => 9, 'schedule' => 'hourly', 'dest' => '/etc/x', 'time' => '25:00'], 'flash' => ['keep' => 0, 'schedule' => 'monthly', 'monthday' => 40]]], $c);
  eq($n['backups']['appdata']['keep'], 3); eq($n['backups']['appdata']['schedule'], 'off', 'unknown schedule ignored'); eq($n['backups']['appdata']['dest'], '', 'outside /mnt refused'); eq($n['backups']['appdata']['time'], '03:00');
  eq($n['backups']['flash']['keep'], 1); eq($n['backups']['flash']['schedule'], 'monthly'); eq($n['backups']['flash']['monthday'], 28);
  $n = dau_sanitize_config(['backups' => ['appdata' => ['before_update' => true, 'required' => true, 'exclude' => ['plex-cache', '../etc', 'a/b']]]], $c);
  ok($n['backups']['appdata']['before_update'] && $n['backups']['appdata']['required']); eq($n['backups']['appdata']['exclude'], ['plex-cache'], 'folder names only');
});

t('backups: destination checks', function () {
  $cfg = bkenv(); eq(dau_bk_check_dest('appdata', $cfg), '');
  $cfg['backups']['appdata']['dest'] = ''; has(dau_bk_check_dest('appdata', $cfg), 'Choose where');
  $cfg['backups']['appdata']['dest'] = '/mnt/dau-test/appdata/plex/b'; has(dau_bk_check_dest('appdata', $cfg), 'inside the folder that is backed up');
  $cfg['backups']['appdata']['dest'] = '/mnt/dau-test/appdata'; has(dau_bk_check_dest('appdata', $cfg), 'inside the folder that is backed up');
  $cfg['backups']['appdata']['dest'] = '/mnt/not-mounted/x/y'; has(dau_bk_check_dest('appdata', $cfg), 'does not exist');
  $cfg['backups']['appdata']['dest'] = '/boot/config'; has(dau_bk_check_dest('appdata', $cfg), 'under /mnt');
});

t('backups: an archive is written privately, atomically and listed', function () {
  $cfg = bkenv(['appdata' => ['exclude' => ['Cache']]]);
  $r = dau_bk_run('appdata', $cfg);
  ok($r['ok'], $r['error']); eq($r['kept'], 1); ok(is_file($r['file'])); eq(substr(sprintf('%o', fileperms($r['file'])), -4), '0600');
  ok((bool)preg_match('#/Unraid-Swiss-Manager/appdata/appdata-\d{8}-\d{6}\.tar\.gz$#', $r['file']));
  eq(glob('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/.*.partial'), [], 'no temporary file left');
  $a = $GLOBALS['TARS'][0]; has(implode(' ', $a), '-C / '); has(implode(' ', $a), '--exclude=mnt/dau-test/appdata/Cache'); has(implode(' ', $a), ' -- mnt/dau-test/appdata');
  $f = dau_bk_list('appdata', '/mnt/dau-test/backups'); eq(count($f), 1); ok($f[0]['size'] > 0);
});

t('backups: only the newest N are kept; the fresh copy is never removed', function () {
  foreach ([1, 2, 3] as $keep) {
    $cfg = bkenv(['appdata' => ['keep' => $keep]]);
    $last = '';
    for ($i = 0; $i < 5; $i++) { $GLOBALS['DAU_NOW'] += 3600; $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']); $last = basename($r['file']); }
    $f = bk_files('appdata'); eq(count($f), $keep, "keep $keep"); eq($f[0], $last, 'newest survives');
  }
  $cfg = bkenv(); $GLOBALS['DAU_NOW'] += 10; dau_bk_run('appdata', $cfg); $GLOBALS['DAU_NOW'] += 10; dau_bk_run('appdata', $cfg); $GLOBALS['DAU_NOW'] += 10; $r = dau_bk_run('appdata', $cfg);
  eq($r['pruned'], 1); eq($r['kept'], 2);
  file_put_contents('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/notes.txt', 'mine'); file_put_contents('/mnt/dau-test/backups/Unraid-Swiss-Manager/flash-x.tar.gz', 'x');
  dau_bk_prune('appdata', '/mnt/dau-test/backups', 1); ok(is_file('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/notes.txt'), 'foreign files are left alone'); eq(count(bk_files('appdata')), 1);
  eq(dau_bk_prune('appdata', '/mnt/dau-test/backups', 0), 0, 'keep is at least 1');
});

t('backups: a failed run changes nothing (older archives stay) and says why', function () {
  $cfg = bkenv(); dau_bk_run('appdata', $cfg); $GLOBALS['DAU_NOW'] += 60; dau_bk_run('appdata', $cfg); $before = bk_files('appdata');
  $GLOBALS['DAU_NOW'] += 60; $GLOBALS['BK']['tar'] = 2; $r = dau_bk_run('appdata', $cfg);
  ok(!$r['ok']); has($r['error'], 'No space left'); eq(bk_files('appdata'), $before, 'nothing removed'); eq(glob('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/.*.partial'), []);
  $GLOBALS['BK']['tar'] = 0; $GLOBALS['BK']['du'] = '9000000000000000'; $r = dau_bk_run('appdata', $cfg); ok(!$r['ok']); has($r['error'], 'Not enough free space'); eq(bk_files('appdata'), $before);
  $GLOBALS['BK']['du'] = 4096; $GLOBALS['BK']['tar'] = 1; $GLOBALS['DAU_NOW'] += 60; $r = dau_bk_run('appdata', $cfg); ok($r['ok'], 'exit 1 = files changed while reading, still a backup'); has(implode(' ', $r['notes']), 'changed');
});

t('backups: the boot drive archive is checked and /boot is the source', function () {
  $cfg = bkenv(); $r = dau_bk_run('flash', $cfg); ok($r['ok'], $r['error']);
  has(implode(' ', $GLOBALS['TARS'][0]), ' -- boot'); ok((bool)preg_match('#/flash/flash-\d{8}-\d{6}\.tar\.gz$#', $r['file']));
  $GLOBALS['BK']['gzip'] = 1; $GLOBALS['DAU_NOW'] += 60; $r = dau_bk_run('flash', $cfg); ok(!$r['ok']); has($r['error'], 'integrity'); eq(count(bk_files('flash')), 1, 'the discarded archive is gone, the earlier one stays');
});

t('backups: weekly and monthly slots are due at the right time, only with a usable destination', function () {
  $cfg = bkenv(['appdata' => ['schedule' => 'weekly', 'weekday' => 0, 'time' => '03:00'], 'flash' => ['schedule' => 'off']]);
  $s = dau_bk_slots($cfg); eq(array_keys($s), ['appdata']);
  $now = 1793000000;                                                    // Monday 2026-10-26 07:33 UTC
  $sun = dau_occ_before($s['appdata']['sched'], $now); eq(date('D H:i', $sun), 'Sun 03:00');
  ok(dau_slot_due($s['appdata']['sched'], ['seen' => $sun - 86400, 'last' => 0], $sun + 600), 'due just after');
  ok(!dau_slot_due($s['appdata']['sched'], ['seen' => $sun - 86400, 'last' => $sun + 5], $sun + 600), 'not twice');
  $cfg['backups']['appdata']['dest'] = ''; eq(dau_bk_slots($cfg), [], 'no destination = no schedule');
  $cfg = bkenv(['flash' => ['schedule' => 'monthly', 'monthday' => 1, 'time' => '03:30']]); $s = dau_bk_slots($cfg); ok(isset($s['flash']));
  eq(date('Y-m-d H:i', dau_next_run($s['flash']['sched'], ['seen' => $now, 'last' => 0], $now)), '2026-11-01 03:30');
});

t('backups: a scheduled tick makes the backup, records it, and a failure is reported', function () {
  $cfg = bkenv(['appdata' => ['schedule' => 'weekly', 'weekday' => (int)date('w', 1793000000 - 86400), 'time' => '03:00'], 'flash' => ['schedule' => 'off']]);
  $st = dau_state_load(); $st['slots']['b:appdata'] = ['seen' => 1793000000 - 3 * 86400, 'last' => 0]; dau_state_save($st);
  $GLOBALS['DAU_NOW'] = (int)(strtotime('2026-10-25 03:10:00 UTC')); $GLOBALS['NOTIFY'] = [];
  eq(dau_job_main('tick'), 0);
  eq(count(bk_files('appdata')), 1, 'tick ran the backup'); $st = dau_state_load(); ok($st['backups']['appdata']['ok']); eq($st['backups']['appdata']['reason'], 'scheduled');
  $h = dau_history_load(); eq($h[0]['type'], 'backup'); eq($h[0]['result'], 'completed'); has($h[0]['message'], '1 kept');
  $GLOBALS['BK']['tar'] = 2; $r = dau_job_main('backup', ['kinds' => ['appdata']]); $h = dau_history_load(); eq($h[0]['result'], 'failed'); has($h[0]['error'], 'No space'); eq(dau_state_load()['backups']['appdata']['ok'], false);
  eq(count(bk_files('appdata')), 1, 'the earlier backup is still there');
});

t('backups: before an update, once per job; "required" stops the update when the backup fails', function () {
  $cfg = bkenv(['appdata' => ['before_update' => true, 'schedule' => 'off']]);
  $st = dau_state_load(); $J = dau_job_new('scheduled', $cfg, $st);
  ok(dau_bk_before_update($J, 'Docker')); ok(dau_bk_before_update($J, 'plugin')); eq(count(bk_files('appdata')), 1, 'one backup for the whole job');
  $cfg = bkenv(['appdata' => ['before_update' => false]]); $J = dau_job_new('scheduled', $cfg, dau_state_load()); ok(dau_bk_before_update($J, 'Docker')); eq(count(bk_files('appdata')), 0, 'off = nothing');
  $GLOBALS['BK']['tar'] = 2;
  $cfg = bkenv(['appdata' => ['before_update' => true, 'required' => false]]); $GLOBALS['BK']['tar'] = 2; $J = dau_job_new('scheduled', $cfg, dau_state_load()); ok(dau_bk_before_update($J, 'Docker'), 'not required: updates go on');
  $cfg = bkenv(['appdata' => ['before_update' => true, 'required' => true]]); $GLOBALS['BK']['tar'] = 2; $J = dau_job_new('scheduled', $cfg, dau_state_load()); ok(!dau_bk_before_update($J, 'Docker'), 'required: updates are held back');
  $k = array_column($J['events'], 'kind'); ok(in_array('backup_failed', $k, true));
});

t('backups: api shows settings and refuses a run without a usable destination', function () {
  bkenv(['flash' => ['dest' => '']]);
  $r = api_call('backups'); ok($r['ok'] ?? false); eq($r['backups']['appdata']['problem'], ''); has($r['backups']['flash']['problem'], 'Choose where'); eq($r['backups']['appdata']['next'] !== null, true);
  has(api_call('run', ['what' => 'backup', 'kind' => 'flash'])['error'] ?? '', 'Choose where'); has(api_call('run', ['what' => 'backup', 'kind' => 'all'])['error'] ?? '', 'Choose where');
  has(api_call('run', ['what' => 'backup', 'kind' => 'x'])['error'] ?? '', 'Unknown');
});

t('backups: the dispatcher cron line exists when only a backup is scheduled', function () {
  $cfg = bkenv(['appdata' => ['schedule' => 'weekly'], 'flash' => ['schedule' => 'off']]);
  $cfg['global']['enabled'] = false; $cfg['plugins']['check_enabled'] = false; $cfg['os']['check_enabled'] = false;
  @unlink(DAU_CRON); dau_cron_apply($cfg); ok(is_file(DAU_CRON), 'cron line written for a backup-only schedule');
  $cfg['backups']['appdata']['schedule'] = 'off'; dau_cron_apply($cfg); ok(!is_file(DAU_CRON), 'removed when nothing is scheduled');
});
