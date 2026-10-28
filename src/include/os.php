<?php
/*
 * Docker Auto Update - Unraid OS monitoring. DETECT AND NOTIFY ONLY: this plugin never installs an OS update and
 * never reboots. The check is Unraid's own `plugin check unRAIDServer.plg`.
 */

function dau_os_check() {
  $r = dau_run([dau_plugin_bin(), 'check', 'unRAIDServer.plg'], 180);
  if ($r['timeout']) return ['ok' => false, 'error' => 'The OS update check timed out.'];
  $lines = array_values(array_filter(array_map('trim', explode("\n", $r['out']))));
  $v = $lines ? end($lines) : '';
  if ($r['code'] !== 0 || !preg_match('/^[0-9][A-Za-z0-9._+-]{0,30}$/', $v)) {
    return ['ok' => false, 'error' => dau_clip(dau_redact(trim($r['err'] . ' ' . $r['out'])), 200) ?: 'The OS update check returned no version.'];
  }
  return ['ok' => true, 'latest' => $v];
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
  if ($prev !== null && $installed !== '' && $prev !== $installed && ($os['notified'] === $installed || $os['available'] === $installed)) {
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
  if (dau_version_newer($latest, $installed)) {
    if ($os['available'] !== $latest) { $os['available'] = $latest; $os['first_seen'] = $now; $os['withdrawn_notified'] = false; $os['notes'] = dau_os_notes(); }
    if ($os['notified'] !== $latest) {
      $events[] = ['kind' => 'available', 'installed' => $installed, 'available' => $latest, 'reason' => $os['notes']];
      $os['notified'] = $latest;
    }
  } else {
    if ($os['available'] !== null && $os['available'] !== $installed && $os['notified'] === $os['available'] && empty($os['withdrawn_notified'])) {
      $events[] = ['kind' => 'os_withdrawn', 'installed' => $installed, 'available' => $os['available']];
      $os['withdrawn_notified'] = true;
    }
    if ($os['available'] === $installed || $os['available'] === null) { $os['available'] = null; }
    else $os['available'] = null;
    $os['notes'] = '';
  }
  return $events;
}

function dau_os_slots(array $cfg) {
  $o = $cfg['os'];
  if (empty($o['check_enabled'])) return [];
  $s = dau_clean_schedule($o);
  return $s ? ['os' => ['sched' => $s, 'names' => ['*']]] : [];
}
