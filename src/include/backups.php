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

/** Running containers that map something inside the appdata folders, in the order they should be started again ("update after" first). */
function dau_bk_users(array $cfg, array $srcs) {
  $m = dau_appdata_mounts(); if (!$m) return [];
  $todo = [];
  foreach ($m as $c) {
    if ($c['state'] !== 'running' || !dau_valid_name($c['name'])) continue;
    foreach ($c['sources'] as $s) {
      $sr = @realpath($s) ?: $s; $hit = false;
      foreach ($srcs as $root) { $rr = @realpath($root) ?: $root; if (dau_path_in($sr, $rr) || dau_path_in(dau_path_tail($sr), dau_path_tail($rr))) { $hit = true; break; } }
      if ($hit) { $todo[] = [['name' => $c['name']]]; break; }
    }
  }
  return array_map(function ($t) { return $t[0]['name']; }, dau_docker_order($todo, $cfg));
}

/** Stop these containers (dependants first). Returns the ones that were stopped; stops early and reports when one refuses. */
function dau_bk_stop(array $names, array &$res) {
  $stopped = [];
  foreach (array_reverse($names) as $n) {
    dau_phase('Stopping ' . $n . ' for the backup', ['current' => $n]);
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
    dau_phase('Starting ' . $n . ' again', ['current' => $n]);
    $r = dau_docker(['start', $n], 120);
    if ($r['code'] !== 0 || $r['timeout']) $failed[] = $n;
  }
  if ($failed) {
    $res['restart_failed'] = $failed;
    $msg = 'These containers did not start again after the backup: ' . implode(', ', $failed) . '. Start them on the Docker tab.';
    $res['error'] = $res['error'] !== '' ? $res['error'] . ' ' . $msg : $msg;
  }
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
    if ($real === $rs || strpos($real . '/', rtrim($rs, '/') . '/') === 0) return 'The destination is inside the folder that is backed up (' . $s . '). Choose a folder outside it.';
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
  foreach ($srcs as $s) { $r = dau_run([dau_bin('du'), '-sb', $s], 900); $size += (float)preg_replace('/\s.*/s', '', trim($r['out'])); }
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
    $stopped = dau_bk_stop(dau_bk_users($cfg, $srcs), $res);
    if ($res['error'] !== '') { dau_bk_start($stopped, $res); return $res; }
    if ($stopped) $res['notes'][] = 'Stopped during the backup: ' . implode(', ', $stopped) . '.';
  }
  $old = umask(0077);
  try { $r = dau_run($argv, 6 * 3600, null, 4096); }
  finally { umask($old); dau_bk_start($stopped, $res); }      // containers come back even when tar fails
  if (!empty($res['restart_failed'])) { $err = $res['error']; $res['error'] = ''; }
  $okCode = $r['code'] === 0 || ($r['code'] === 1 && $kind === 'appdata');       // 1 = a file changed while it was read (the containers run)
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
  if ($r['code'] === 1) $res['notes'][] = 'Some files changed while they were read (normal while containers run).';
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
  dau_phase('Backing up ' . strtolower(dau_bk_label($kind)));
  $t0 = dau_now();
  try { $r = dau_bk_run($kind, $cfg); }
  catch (Throwable $e) { $r = ['ok' => false, 'error' => 'Unexpected error: ' . $e->getMessage(), 'file' => '', 'size' => 0, 'pruned' => 0, 'kept' => 0, 'seconds' => 0, 'notes' => []]; }
  $J['st']['backups'][$kind] = ['at' => dau_now(), 'ok' => $r['ok'], 'file' => $r['ok'] ? basename($r['file']) : '', 'size' => $r['size'], 'error' => $r['error'], 'reason' => $reason, 'kept' => $r['kept']];
  $msg = $r['ok'] ? basename($r['file']) . ', ' . dau_bytes_text($r['size']) . ', ' . $r['kept'] . ' kept' . ($r['pruned'] ? ', ' . $r['pruned'] . ' older removed' : '') : '';
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
