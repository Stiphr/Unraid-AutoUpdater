<?php
/*
 * Unraid Swiss Manager - Unraid OS.
 * DEFAULT: check and notify only. Installing an OS update (mode "install") and rebooting afterwards (mode "install_reboot")
 * are opt-in, need an explicit confirmation in the UI, a maintenance window and a waiting period, and use only Unraid's own
 * `plugin update unRAIDServer.plg` and the stock reboot command, and only when Unraid's plugin feed offers that exact release (see
 * dau_os_feed_block). Nothing is rolled back automatically: Unraid keeps the previous release on the flash drive (Tools > Update OS > Restore).
 */

/** Unraid releases are compared as versions, so 7.3.10 is newer than 7.3.9. Plugins keep Unraid's text comparison (dau_version_newer). */
function dau_os_newer($latest, $installed) { return (string)$latest !== '' && version_compare((string)$latest, (string)$installed, '>'); }

/**
 * Current Unraid keeps its releases at releases.unraid.net; the update address inside unRAIDServer.plg stopped moving (it still offers 7.0.1).
 * So ask Unraid's own checker. json=true makes it print the answer and skip its own notification. Older Unraid has no such script and uses the plg.
 */
function dau_os_check() {
  $script = $GLOBALS['DAU_OSCHECK'] ?? DAU_OSCHECK;
  if (is_file($script)) {
    $r = dau_run(['/usr/bin/env', 'QUERY_STRING=json=true', $script], 120);
    if ($r['timeout']) return ['ok' => false, 'error' => 'check timed out'];
    $j = json_decode(trim($r['out']), true);
    $v = is_array($j) ? (string)($j['version'] ?? '') : '';
    if (!preg_match('/^[0-9][A-Za-z0-9._+-]{0,30}$/', $v)) return ['ok' => false, 'error' => dau_clip(dau_redact((string)($j['error'] ?? 'The OS update check returned an unexpected version.')), 200)];
    $notes = is_string($j['changelogPretty'] ?? null) ? 'Release notes: ' . dau_clip($j['changelogPretty'], 300) : '';
    return ['ok' => true, 'latest' => $v, 'notes' => $notes];
  }
  $res = dau_plugin_check('unRAIDServer.plg');
  if (!$res['ok']) return $res;
  if (!preg_match('/^[0-9][A-Za-z0-9._+-]{0,30}$/', $res['latest'])) return ['ok' => false, 'error' => 'The OS update check returned an unexpected version.'];
  return $res;
}

function dau_os_notes() {
  $r = dau_run([dau_plugin_bin(), 'changes', DAU_PLUGTMP . '/unRAIDServer.plg'], 60);
  return $r['code'] === 0 ? dau_clip(dau_redact(trim($r['out'])), 900) : '';
}

/**
 * Update the OS block of the state from a check result and return the events that should be announced (each only once).
 * Events: available, os_withdrawn, os_check_failed, os_installed.
 */
function dau_os_process(array &$st, array $check, $now) {
  $os =& $st['os'];
  $events = [];
  $installed = dau_os_version();
  $os['last_check'] = $now;
  $prev = $os['installed'];
  if ($prev !== null && $installed && $prev !== $installed && ($os['notified'] === $installed || $os['available'] === $installed)) {
    $events[] = ['kind' => 'os_installed', 'installed' => $prev, 'available' => $installed];
  }
  $os['installed'] = $installed;
  if (!$check['ok']) {
    $os['last_error'] = $check['error'];
    if (empty($os['error_notified'])) { $events[] = ['kind' => 'os_check_failed', 'installed' => $installed, 'error' => $check['error']]; $os['error_notified'] = true; }
    return $events;
  }
  $os['error_notified'] = false; $os['last_error'] = ''; $os['last_check_ok'] = $now;
  $latest = $check['latest'];
  if (dau_os_newer($latest, $installed)) {
    if ($os['available'] !== $latest) { $os['available'] = $latest; if (($os['skipped'] ?? '') !== $latest) $os['skipped'] = ''; $os['first_seen'] = $now; $os['withdrawn_notified'] = false; $os['notes'] = $check['notes'] ?? dau_os_notes(); }
    if ($os['notified'] !== $latest) {
      $events[] = ['kind' => 'available', 'installed' => $installed, 'available' => $latest, 'reason' => $os['notes']];
      $os['notified'] = $latest;
    }
  } else {
    if ($os['available'] !== null && $os['available'] !== $installed && $os['notified'] === $os['available'] && empty($os['withdrawn_notified'])) {
      $events[] = ['kind' => 'os_withdrawn', 'installed' => $installed, 'available' => $os['available']];
      $os['withdrawn_notified'] = true;
    }
    $os['available'] = null;
    $os['notes'] = '';
  }
  return $events;
}

/* ---------------- policy ---------------- */

function dau_os_is_stable($v) { return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string)$v) === 1; }

/** Array state from Unraid's own var.ini. */
function dau_os_array_state() {
  $v = @parse_ini_file(DAU_VARINI);
  if (!is_array($v) || empty($v['mdState'])) return ['ok' => false, 'state' => '', 'resync' => 0];
  return ['ok' => true, 'state' => strtoupper((string)$v['mdState']), 'resync' => (int)($v['mdResync'] ?? 0)];
}

/** May the server be rebooted right now without interrupting something important? */
function dau_os_reboot_safe() {
  $a = dau_os_array_state();
  if (!$a['ok']) return ['ok' => false, 'reason' => 'The array state could not be read.'];
  if (!in_array($a['state'], ['STARTED', 'STOPPED'], true)) return ['ok' => false, 'reason' => 'The array is in state ' . $a['state'] . ' (not a plain started or stopped array).'];
  if ($a['resync'] > 0) return ['ok' => false, 'reason' => 'A parity check, sync or rebuild is running.'];
  if (dau_pid_alive(DAU_MOVERPID)) return ['ok' => false, 'reason' => 'The mover is running (files are being moved between the cache and the array).'];
  return ['ok' => true, 'reason' => ''];
}

function dau_os_boot_free_mb() {
  if (isset($GLOBALS['DAU_BOOTFREE'])) return (int)$GLOBALS['DAU_BOOTFREE'];
  $f = @disk_free_space(DAU_BOOT);
  return $f === false ? 0 : (int)($f / 1048576);
}

/** The installed OS plugin file, shaped like a plugin entry so the plugin safety checks can be reused. */
function dau_os_plugin_entry() {
  $path = DAU_PLUGBOOT . '/unRAIDServer.plg';
  if (!is_file($path)) $path = DAU_PLUGLINKS . '/unRAIDServer.plg';
  return ['file' => 'unRAIDServer.plg', 'path' => $path, 'self' => false, 'name' => 'unRAIDServer', 'version' => dau_os_version() ?: '',
          'url' => dau_plg_attr($path, 'pluginURL'), 'min' => dau_plg_attr($path, 'min'), 'max' => dau_plg_attr($path, 'max')];
}

/**
 * What would happen to the available OS version right now?
 * state: up_to_date | awaiting_confirmation | notify_only | not_confirmed | prerelease | blocked | waiting_delay | window_closed | staged | ready
 */
function dau_os_eval(array $cfg, array $st, $now, $manual = false) {
  $o = $cfg['os']; $os = $st['os'];
  $latest = $os['available'];
  if ($latest === null || $latest === '') return ['state' => 'up_to_date', 'reason' => '', 'eta' => null];
  $pend = $os['pending'] ?? null;
  if (is_array($pend) && ($pend['to'] ?? '') === $latest) return ['state' => 'staged', 'reason' => 'Installed, waiting for a reboot.', 'eta' => null];
  $wf = $os['workflow'] ?? null;
  if (is_array($wf) && ($wf['state'] ?? '') === 'awaiting_confirmation' && ($wf['version'] ?? '') === $latest) return ['state' => 'awaiting_confirmation', 'reason' => 'Prerequisites passed; waiting for your confirmation.', 'eta' => null];
  if ($manual) return ['state' => 'ready', 'reason' => '', 'eta' => null];
  if (($os['skipped'] ?? '') === (string)$latest) return ['state' => 'skipped', 'reason' => 'You chose to skip this release.', 'eta' => null];
  if ($o['mode'] === 'notify') return ['state' => 'notify_only', 'reason' => 'Automatic installation is off (notify only).', 'eta' => null];
  if ($o['confirmed'] !== $o['mode']) return ['state' => 'not_confirmed', 'reason' => 'The automatic mode was not confirmed.', 'eta' => null];
  if (!empty($o['stable_only']) && !dau_os_is_stable($latest)) return ['state' => 'prerelease', 'reason' => 'Only stable releases are installed automatically.', 'eta' => null];
  $at = $os['attempted'] ?? null;
  if (is_array($at) && ($at['version'] ?? '') === $latest) return ['state' => 'blocked', 'reason' => 'The last attempt for this version did not succeed: ' . ($at['reason'] ?? ''), 'eta' => null];
  if (empty($o['window']['enabled'])) return ['state' => 'blocked', 'reason' => 'Automatic OS installs need a maintenance window.', 'eta' => null];
  $eta = (int)$os['first_seen'] + (int)$o['delay_days'] * 86400;
  if ($now < $eta) return ['state' => 'waiting_delay', 'reason' => 'Waiting ' . (int)$o['delay_days'] . ' day(s) after the release was first seen.', 'eta' => $eta];
  if (!dau_window_open($o['window'], $now)) return ['state' => 'window_closed', 'reason' => 'Outside the maintenance window.', 'eta' => null];
  return ['state' => 'ready', 'reason' => '', 'eta' => null];
}

/* ---------------- install + reboot ---------------- */

/**
 * The installer here is `plugin update unRAIDServer.plg`, which installs whatever the plg's feed offers. That may be an old release or nothing, so it is
 * only used when the feed offers exactly $latest. Returns why not, or ''.
 */
function dau_os_feed_block($latest) {
  $p = dau_plugin_check('unRAIDServer.plg');
  if ($p['ok'] && $p['latest'] === (string)$latest) return '';
  return 'Unraid ' . $latest . ' can only be installed from Tools > Update OS here: Unraid\'s plugin feed ' . ($p['ok'] ? 'offers ' . $p['latest'] : 'did not answer') . '. Nothing was changed.';
}

/**
 * Install the OS update through `plugin update unRAIDServer.plg` after a fresh check and the standard plugin validation.
 * The new release only becomes active after a reboot. Result: staged | failed | blocked.
 */
function dau_os_install(array $cfg, $latest) {
  $installed = dau_os_version() ?: '';
  $res = ['result' => 'failed', 'stage' => 'check', 'error' => '', 'next' => '', 'prev' => ['version' => $installed], 'new' => ['version' => $latest], 'started' => dau_now(), 'finished' => 0, 'verification' => null];
  $done = function () use (&$res) { $res['finished'] = dau_now(); return $res; };
  $res['next'] = 'Nothing was changed.';
  $chk = dau_os_check();
  if (!$chk['ok']) { $res['error'] = 'Fresh update check failed: ' . $chk['error']; return $done(); }
  if ($chk['latest'] !== $latest) { $res['result'] = 'blocked'; $res['error'] = 'The offered version changed from ' . $latest . ' to ' . $chk['latest'] . ' during the check.'; return $done(); }
  $why = dau_os_feed_block($latest);
  if ($why !== '') { $res['result'] = 'blocked'; $res['error'] = $why; $res['next'] = 'Install it yourself from Tools > Update OS after reading the release notes.'; return $done(); }
  $res['stage'] = 'validate';
  $p = dau_os_plugin_entry();
  $v = dau_plugin_validate($p, $latest);
  if (!$v['ok']) { $res['result'] = 'blocked'; $res['error'] = $v['reason']; $res['next'] = 'Install it yourself from Tools > Update OS after reading the release notes.'; return $done(); }
  $res['stage'] = 'space';
  $free = dau_os_boot_free_mb();
  if ($free < 600) { $res['result'] = 'blocked'; $res['error'] = 'Only ' . $free . ' MB free on the flash drive (600 MB needed).'; $res['next'] = 'Free up space on the flash drive and try again.'; return $done(); }
  $tmp = DAU_PLUGTMP . '/unRAIDServer.plg';
  if (hash('sha256', (string)@file_get_contents($tmp)) !== $v['sha']) { $res['result'] = 'blocked'; $res['error'] = 'The downloaded file changed after validation.'; return $done(); }
  $res['stage'] = 'install';
  $r = dau_run([dau_plugin_bin(), 'update', 'unRAIDServer.plg'], 1800);
  $res['stage'] = 'verify';
  $now = dau_plg_attr(DAU_PLUGBOOT . '/unRAIDServer.plg', 'version');
  if ($now === '') $now = dau_plg_attr(DAU_PLUGLINKS . '/unRAIDServer.plg', 'version');
  $files = is_file(DAU_BOOT . '/bzroot') && is_file(DAU_BOOT . '/bzimage');
  if ($now === $latest && $files) {
    $res['result'] = 'staged'; $res['stage'] = '';
    $res['verification'] = ['result' => 'staged', 'ok' => true, 'detail' => "Unraid $latest is installed on the flash drive and becomes active after a reboot."];
    $res['next'] = 'Reboot to finish. If anything goes wrong, Tools > Update OS > Restore returns to ' . ($installed ?: 'the previous release') . '.';
    return $done();
  }
  $res['error'] = $r['timeout'] ? 'The install did not finish in time.' : 'After the install Unraid reports version ' . ($now ?: 'unknown') . ($files ? '' : ' and the boot files are incomplete') . ' (expected ' . $latest . '). ' . dau_clip(dau_redact(trim($r['err'] . ' ' . $r['out'])), 200);
  $res['next'] = 'Do NOT reboot until you have checked Tools > Update OS. The previous release is kept on the flash drive and can be restored there.';
  return $done();
}

/** Reboot with Unraid's stock command (the same one the Reboot button runs). */
function dau_os_reboot() {
  $r = dau_run([DAU_REBOOT, '-n'], 30);
  return $r['code'] === 0 || $r['timeout'];
}

/**
 * Called at every boot (install script): if an OS update was waiting for this reboot, record whether it is now running.
 * The announcement itself is sent by the next job (the network may not be up yet).
 */
function dau_os_boot_verify(array &$st) {
  $pend = $st['os']['pending'] ?? null;
  $installed = dau_os_version();
  if ($installed !== null && $installed !== '') $st['os']['installed'] = $installed;
  if (!is_array($pend) || empty($pend['to'])) return null;
  $ok = ($installed === $pend['to']);
  $st['os']['pending'] = null;
  $st['os']['boot_result'] = ['kind' => $ok ? 'os_verified' : 'os_unverified', 'installed' => (string)($pend['from'] ?? ''), 'available' => (string)$pend['to'], 'running' => (string)$installed, 'at' => dau_now(), 'auto' => !empty($pend['reboot'])];
  if ($ok) { $st['os']['available'] = null; $st['os']['notified'] = null; $st['os']['rebooted_for'] = ''; }
  return $st['os']['boot_result'];
}

function dau_os_slots(array $cfg) {
  $o = $cfg['os'];
  if (empty($o['check_enabled'])) return [];
  $s = dau_clean_schedule($o);
  return $s ? ['os' => ['sched' => $s, 'names' => ['*']]] : [];
}
