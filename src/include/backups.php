<?php
/*
 * Unraid Swiss Manager - scheduled backups of the appdata folders and of the boot drive (flash).
 * One archive per run: <destination>/Unraid-Swiss-Manager/<appdata|flash>/<kind>-YYYYmmdd-HHMMSS.tar.gz.
 *  - An archive is written under a temporary name and renamed only when it is complete, so a half-written file is never
 *    mistaken for a backup. Old archives are removed only AFTER the new one succeeded, and the newest is never removed.
 *  - Appdata is NOT application-consistent unless "stop_containers" is on: then the running containers that use the appdata folders
 *    are stopped (dependants first) for the archive and started again afterwards (dependencies first), also when the archive fails.
 *  - The archives hold your own data and keys, so they are created private (0600). Keep them off the flash drive.
 * Separate from backup.php, which keeps small per-container safety copies right before a container update.
 */

const DAU_BK_KINDS = ['appdata', 'flash'];
const DAU_BK_RECOVER_TTL = 86400;     // after this long a stopped container may be stopped on purpose: it is reported, not started

function dau_bk_label($kind) { return $kind === 'flash' ? 'Boot drive (flash)' : 'Appdata'; }

function dau_bk_spec() {
  $one = ['schedule' => ['enum', ['off', 'weekly', 'monthly']], 'weekday' => ['int', 0, 6], 'monthday' => ['int', 1, 28], 'time' => ['time'],
          'dest' => ['path'], 'keep' => ['int', 1, 3]];
  return ['appdata' => ['obj', $one + ['before_update' => ['bool'], 'required' => ['bool'], 'stop_containers' => ['bool'], 'exclude' => ['folders']]], 'flash' => ['obj', $one]];
}

function dau_bk_defaults() {
  $one = ['schedule' => 'off', 'weekday' => 0, 'monthday' => 1, 'dest' => '', 'keep' => 2];
  return ['appdata' => $one + ['time' => '03:00', 'before_update' => false, 'required' => false, 'stop_containers' => false, 'exclude' => []], 'flash' => $one + ['time' => '03:30']];
}

/**
 * Running containers that map something inside the appdata folders, in the order they should be started again ("update after" first).
 * A container that uses another one's network ("--net=container:vpn") starts after it, and stops with it: it would lose its network otherwise.
 * null = Docker could not be asked. [] = nothing needs stopping.
 */
function dau_bk_users(array $cfg, array $srcs) {
  $m = dau_appdata_mounts(); if ($m === null) return null;
  $ids = []; foreach ($m as $c) $ids[$c['id']] = $c['name'];
  $hit = [];
  foreach ($m as $c) {
    if ($c['state'] !== 'running' || !dau_valid_name($c['name'])) continue;
    foreach ($c['sources'] as $s) {
      $sr = @realpath($s) ?: $s;
      foreach ($srcs as $root) { $rr = @realpath($root) ?: $root; if (dau_path_in($sr, $rr) || dau_path_in(dau_path_tail($sr), dau_path_tail($rr))) { $hit[$c['name']] = true; break 2; } }
    }
  }
  $prov = [];
  foreach ($m as $c) { $p = dau_net_provider($c['net'], $ids); if ($p !== '') $prov[$c['name']] = $p; }
  do {
    $more = false;
    foreach ($m as $c) if (!isset($hit[$c['name']]) && $c['state'] === 'running' && dau_valid_name($c['name']) && isset($hit[$prov[$c['name']] ?? ''])) { $hit[$c['name']] = true; $more = true; }
  } while ($more);
  $todo = [];
  foreach ($m as $c) if (isset($hit[$c['name']])) {
    $todo[] = [['name' => $c['name']]];
    if (isset($prov[$c['name']])) $cfg['containers'][$c['name']]['after'] = array_merge((array)($cfg['containers'][$c['name']]['after'] ?? []), [$prov[$c['name']]]);
  }
  return array_map(function ($t) { return $t[0]['name']; }, dau_docker_order($todo, $cfg));
}

/** Stop these containers (dependants first). Returns the ones that were stopped; stops early and reports when one refuses. */
function dau_bk_stop(array $names, array &$res) {
  $stopped = [];
  foreach (array_reverse($names) as $n) {
    dau_phase('Stopping ' . $n . ' for the backup', ['current' => $n, 'stage' => 'stop']);
    $r = dau_docker(['stop', '-t', '60', $n], 120);
    if ($r['code'] !== 0 || $r['timeout']) { $res['error'] = $n . ' could not be stopped, so the backup was not made.'; break; }
    $stopped[] = $n;
  }
  return $stopped;
}

/** Start them again in dependency order. Never throws; each failure is reported. */
function dau_bk_start(array $stopped, array &$res) {
  $failed = [];
  foreach (array_reverse($stopped) as $n) {
    dau_phase('Starting ' . $n . ' again', ['current' => $n, 'stage' => 'start']);
    $r = dau_docker(['start', $n], 120);
    if ($r['code'] !== 0 || $r['timeout']) $failed[] = $n;
  }
  if ($failed) {
    $res['restart_failed'] = $failed;
    $msg = 'These containers did not start again after the backup: ' . implode(', ', $failed) . '. Start them on the Docker tab.';
    $res['error'] = $res['error'] !== '' ? $res['error'] . ' ' . $msg : $msg;
  }
}

/**
 * A backup that was killed (power loss, reboot, out of memory) cannot run its "start them again" step, so it leaves a list on the flash drive.
 * The next job finds it here. Docker not answering yet (for example right after a boot) keeps the list for the run after that.
 * Returns true when there is something to announce.
 */
function dau_bk_recover(array &$J) {
  if (!is_file(DAU_STOPPED)) return false;
  $j = dau_read_json(DAU_STOPPED, []);
  if (dau_inspect_all() === null) return false;
  @unlink(DAU_STOPPED);
  $names = array_values(array_filter((array)($j['names'] ?? []), 'dau_valid_name'));
  if (!$names) return false;
  $late = dau_now() - (int)($j['at'] ?? 0) > DAU_BK_RECOVER_TTL;
  $started = []; $failed = [];
  if (!$late) foreach (array_reverse($names) as $n) { $s = dau_docker_restore($n); if ($s === 'started') $started[] = $n; elseif ($s === 'failed') $failed[] = $n; }
  if (!$late && !$started && !$failed) return false;
  $msg = 'A backup was interrupted while containers were stopped. ' . ($late ? 'Check that these run: ' . implode(', ', $names) . '.' : ($started ? 'Started again: ' . implode(', ', $started) . '. ' : '') . ($failed ? 'Did not start: ' . implode(', ', $failed) . '. Start them on the Docker tab.' : ''));
  dau_history_add(['type' => 'backup', 'name' => dau_bk_label('appdata'), 'action' => 'backup', 'result' => 'interrupted', 'started' => (int)($j['at'] ?? dau_now()), 'finished' => dau_now(), 'trigger' => 'recovery', 'error' => $msg], (int)$J['cfg']['history']['max_entries']);
  dau_log($failed || $late ? 'error' : 'warn', 'backup', 'appdata', 'recover', $failed || $late ? 'fail' : 'ok', $msg);
  $J['events'][] = dau_ev('backup', dau_bk_label('appdata'), 'recovered', ['error' => $failed || $late ? $msg : '', 'reason' => $failed || $late ? '' : $msg]);
  return true;
}

/** Folders that will be archived: the appdata roots that exist (flash is always /boot). */
function dau_bk_sources($kind, array $cfg) {
  if ($kind === 'flash') return is_dir('/boot') ? ['/boot'] : [];
  $o = [];
  foreach ((array)($cfg['appdata']['roots'] ?? []) as $r) { $r = rtrim((string)$r, '/'); if ($r !== '' && dau_valid_dest($r) && is_dir($r) && !in_array($r, $o, true)) $o[] = $r; }
  return $o;
}

function dau_bk_dir($kind, $dest) { return rtrim($dest, '/') . '/Unraid-Swiss-Manager/' . $kind; }

/** Empty string = destination is usable, otherwise a sentence that says why not. */
function dau_bk_check_dest($kind, array $cfg) {
  $b = $cfg['backups'][$kind] ?? null; if (!$b) return 'Unknown backup type.';
  $d = rtrim((string)$b['dest'], '/');
  if ($d === '') return 'Choose where the backups go.';
  if (!dau_valid_dest($d)) return 'The destination must be a folder under /mnt, for example /mnt/user/backups.';
  if (!is_dir($d) && !is_dir(dirname($d))) return 'The destination folder (' . dirname($d) . ') does not exist. Is the disk or share mounted?';
  $real = is_dir($d) ? (realpath($d) ?: $d) : $d;
  foreach (dau_bk_sources($kind, $cfg) as $s) {
    $rs = realpath($s) ?: $s;
    /* the tails also catch one share seen through two names: /mnt/cache/appdata/x is inside /mnt/user/appdata */
    if (dau_path_in($real, $rs) || dau_path_in(dau_path_tail($real), dau_path_tail($rs))) return 'The destination is inside the folder that is backed up (' . $s . '). Choose a folder outside it.';
  }
  if (!dau_bk_sources($kind, $cfg)) return $kind === 'flash' ? 'The boot drive (/boot) was not found.' : 'No appdata folder was found. Check Settings > Cleanup > appdata folders.';
  return '';
}

/** Existing archives of this kind, newest first. */
function dau_bk_list($kind, $dest) {
  $out = [];
  if (!is_string($dest) || !dau_valid_dest($dest)) return $out;
  foreach (glob(dau_bk_dir($kind, $dest) . '/' . $kind . '-*.tar.gz') ?: [] as $f) {
    if (!preg_match('#/' . $kind . '-(\d{8})-(\d{6})\.tar\.gz$#', $f, $m)) continue;
    $t = DateTime::createFromFormat('Ymd-His', $m[1] . '-' . $m[2], new DateTimeZone(date_default_timezone_get()));
    $out[] = ['file' => $f, 'name' => basename($f), 'size' => (int)@filesize($f), 'ts' => $t ? $t->getTimestamp() : (int)@filemtime($f)];
  }
  usort($out, function ($a, $b) { return $b['ts'] <=> $a['ts'] ?: strcmp($b['name'], $a['name']); });
  return $out;
}

/** Keep the newest $keep archives (at least 1: the fresh copy is never removed). Returns how many were removed. */
function dau_bk_prune($kind, $dest, $keep) {
  $keep = max(1, (int)$keep); $n = 0;
  foreach (array_slice(dau_bk_list($kind, $dest), $keep) as $e) if (@unlink($e['file'])) $n++;
  foreach (glob(dau_bk_dir($kind, $dest) . '/.' . $kind . '-*.partial') ?: [] as $p) if (@filemtime($p) < dau_now() - 86400) @unlink($p);   // leftovers of an interrupted run
  return $n;
}

/**
 * Make one backup now.
 * @return array ['ok','error','file','size','pruned','kept','seconds','skipped'=>[...]]
 */
function dau_bk_run($kind, array $cfg) {
  $res = ['ok' => false, 'error' => '', 'file' => '', 'size' => 0, 'pruned' => 0, 'kept' => 0, 'seconds' => 0, 'notes' => []];
  if (!in_array($kind, DAU_BK_KINDS, true)) { $res['error'] = 'Unknown backup type.'; return $res; }
  $b = $cfg['backups'][$kind];
  $why = dau_bk_check_dest($kind, $cfg); if ($why !== '') { $res['error'] = $why; return $res; }
  $t0 = dau_now(); $srcs = dau_bk_sources($kind, $cfg); $dir = dau_bk_dir($kind, $b['dest']);
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { $res['error'] = 'Cannot create ' . $dir . '.'; return $res; }
  /* enough room? (the archive is compressed, so this is a safe upper bound; old archives are only removed afterwards) */
  $size = 0.0;
  foreach ($srcs as $s) {
    $r = dau_run([dau_bin('du'), '-sb', $s], 900);
    if ($r['timeout'] || trim($r['out']) === '') $res['notes'][] = 'The size of ' . $s . ' could not be measured, so free space was not checked for it.';
    $size += (float)preg_replace('/\s.*/s', '', trim($r['out']));
  }
  $free = dau_backup_free($dir);
  if ($free !== null && $size > 0 && $size > $free) { $res['error'] = 'Not enough free space at the destination: about ' . dau_bytes_text($size) . ' to back up, ' . dau_bytes_text($free) . ' free. Nothing was changed.'; return $res; }
  $stamp = date('Ymd-His', $t0);
  $final = "$dir/$kind-$stamp.tar.gz"; $tmp = "$dir/.$kind-$stamp.tar.gz.partial";
  $argv = [dau_bin('tar'), '-czf', $tmp, '--warning=no-file-changed', '-C', '/'];
  if ($kind === 'appdata') foreach ($srcs as $s) {
    foreach ((array)$b['exclude'] as $x) $argv[] = '--exclude=' . ltrim($s, '/') . '/' . $x;
    $argv[] = '--exclude=' . ltrim($s, '/') . '/' . DAU_QDIR_NAME;    // folders already set aside by Cleanup are not worth backing up again
  }
  $argv[] = '--';
  foreach ($srcs as $s) $argv[] = ltrim($s, '/');
  $stopped = [];
  if ($kind === 'appdata' && !empty($b['stop_containers'])) {
    $users = dau_bk_users($cfg, $srcs);
    if ($users === null) { $res['error'] = 'Docker did not say which containers use the appdata folders, so they could not be stopped and the backup was not made.'; return $res; }
    if ($users) dau_write_json(DAU_STOPPED, ['at' => dau_now(), 'names' => array_reverse($users)]);      // written first: a kill can come at any moment from here on
    $stopped = dau_bk_stop($users, $res);
    if ($res['error'] !== '') { dau_bk_start($stopped, $res); @unlink(DAU_STOPPED); return $res; }
    if ($stopped) $res['notes'][] = 'Stopped during the backup: ' . implode(', ', $stopped) . '.';
  }
  dau_phase('Writing the archive', ['stage' => 'archive']);
  $old = umask(0077);
  try { $r = dau_run($argv, 6 * 3600, null, 4096); }
  finally { umask($old); dau_bk_start($stopped, $res); @unlink(DAU_STOPPED); }      // containers come back even when tar fails
  if (!empty($res['restart_failed'])) { $err = $res['error']; $res['error'] = ''; }
  $okCode = $r['code'] === 0 || $r['code'] === 1;       // 1 = a file changed while it was read (containers running; a syslog mirrored to the flash drive)
  if (!$okCode || $r['timeout'] || !is_file($tmp) || (int)@filesize($tmp) < 1) {
    @unlink($tmp);
    $res['error'] = trim(($r['timeout'] ? 'The backup took longer than 6 hours and was stopped.' : 'The archive could not be written' . (trim($r['err']) !== '' ? ': ' . dau_clip(dau_redact(trim($r['err'])), 160) : '.')) . ' ' . ($err ?? ''));
    return $res;
  }
  if ($kind === 'flash') {      // the boot drive is small: prove the archive can be read back
    $g = dau_run([dau_bin('gzip'), '-t', $tmp], 600);
    if ($g['code'] !== 0) { @unlink($tmp); $res['error'] = 'The archive failed its integrity check and was discarded.'; return $res; }
  }
  if (!@rename($tmp, $final)) { @unlink($tmp); $res['error'] = 'The finished archive could not be moved into place.'; return $res; }
  @chmod($final, 0600);
  $res['ok'] = true; $res['file'] = $final; $res['size'] = (int)@filesize($final); $res['seconds'] = max(0, dau_now() - $t0);
  if ($r['code'] === 1) $res['notes'][] = 'Some files changed while they were read (normal while anything writes to them).';
  $res['pruned'] = dau_bk_prune($kind, $b['dest'], $b['keep']);
  $res['kept'] = count(dau_bk_list($kind, $b['dest']));
  if (isset($err)) { $res['ok'] = false; $res['error'] = $err; }    // the archive is good, but a container is down: that must not look like success
  return $res;
}

/* ---------- schedule ---------- */

function dau_bk_sched(array $b) {
  return dau_clean_schedule(['frequency' => $b['schedule'], 'time' => $b['time'], 'weekday' => $b['weekday'], 'monthday' => $b['monthday']]);
}

/** Slots for the dispatcher: 'b:appdata' / 'b:flash' (only kinds that are scheduled and have a usable destination). */
function dau_bk_slots(array $cfg) {
  $o = [];
  foreach (DAU_BK_KINDS as $k) {
    $b = $cfg['backups'][$k];
    if ($b['schedule'] === 'off' || dau_bk_check_dest($k, $cfg) !== '') continue;
    $s = dau_bk_sched($b); if ($s) $o[$k] = ['sched' => $s];
  }
  return $o;
}

/* ---------- jobs ---------- */

/** Run one backup as part of a job: history entry, state, notification event. Returns the result. */
function dau_job_backup(array &$J, $kind, $reason) {
  $cfg = $J['cfg'];
  $stop = $kind === 'appdata' && !empty($cfg['backups']['appdata']['stop_containers']);
  $prev = $GLOBALS['DAU_STICKY'] ?? [];
  dau_sticky(['steps' => array_merge($stop ? [['stop', 'Stop containers']] : [], [['archive', 'Write archive']], $stop ? [['start', 'Start containers']] : [])]);
  dau_phase('Backing up ' . strtolower(dau_bk_label($kind)), ['stage' => $stop ? 'stop' : 'archive']);
  $t0 = dau_now();
  try { $r = dau_bk_run($kind, $cfg); }
  catch (Throwable $e) { $r = ['ok' => false, 'error' => 'Unexpected error: ' . $e->getMessage(), 'file' => '', 'size' => 0, 'pruned' => 0, 'kept' => 0, 'seconds' => 0, 'notes' => []]; }
  $GLOBALS['DAU_STICKY'] = $prev;
  $J['st']['backups'][$kind] = ['at' => dau_now(), 'ok' => $r['ok'], 'file' => $r['ok'] ? basename($r['file']) : '', 'size' => $r['size'], 'error' => $r['error'], 'reason' => $reason, 'kept' => $r['kept']];
  $msg = $r['ok'] ? basename($r['file']) . ', ' . dau_bytes_text($r['size']) . ', ' . $r['kept'] . ' kept' . ($r['pruned'] ? ', ' . $r['pruned'] . ' older removed' : '') : '';
  if (!empty($r['notes'])) $msg = trim($msg . ' ' . implode(' ', $r['notes']));
  dau_history_add(['type' => 'backup', 'name' => dau_bk_label($kind), 'action' => 'backup', 'result' => $r['ok'] ? 'completed' : 'failed', 'started' => $t0, 'finished' => dau_now(),
    'trigger' => $J['trigger'], 'error' => $r['error'], 'message' => $msg . ($reason ? ' (' . $reason . ')' : '')], (int)$cfg['history']['max_entries']);
  dau_log($r['ok'] ? 'info' : 'error', 'backup', $kind, 'backup', $r['ok'] ? 'ok' : 'fail', $r['ok'] ? $msg : $r['error']);
  $J['events'][] = dau_ev('backup', dau_bk_label($kind), $r['ok'] ? 'backup_done' : 'backup_failed',
    ['detail' => $msg, 'error' => $r['error'], 'reason' => $reason, 'silent' => $r['ok'], 'next' => $r['ok'] ? '' : 'Open the Backups tab to check the destination, then run it again.']);
  return $r;
}

/**
 * Right before updates are installed. Returns false when the backup is "required" and failed: the caller must then install nothing.
 * Runs at most once per job, and only when there really is something to install.
 */
function dau_bk_before_update(array &$J, $what) {
  $b = $J['cfg']['backups']['appdata'];
  if (!empty($J['bk_pre']) || empty($b['before_update'])) return true;
  $J['bk_pre'] = true;
  $r = dau_job_backup($J, 'appdata', 'before ' . $what . ' updates');
  if (!$r['ok'] && !empty($b['required'])) {
    dau_log('warn', 'backup', 'appdata', 'before-update', 'blocked', 'Updates were not installed because the required backup failed.');
    return false;
  }
  return true;
}
