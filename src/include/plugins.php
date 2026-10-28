<?php
/*
 * Unraid Auto Updater - Unraid plugins.
 * Only Unraid's own `plugin` tool ever installs anything: `plugin check` downloads the plg the plugin itself points
 * to, `plugin update` installs that downloaded file. This module adds the safety net around it (opt-in, policies,
 * delay, validation, pinning, verification). It never disables a check Unraid does and never auto-rolls back.
 */

const DAU_PLG_MAX_BYTES = 2097152;

function dau_plugin_bin() { return dau_bin('plugin'); }

function dau_plg_attr($path, $attr) {
  $r = dau_run([dau_plugin_bin(), $attr, $path], 30);
  return $r['code'] === 0 ? trim($r['out']) : '';
}

/** Installed plugins (never Unraid itself). */
function dau_plugins_installed() {
  $out = [];
  foreach (glob(DAU_PLUGLINKS . '/*.plg') ?: [] as $link) {
    $f = basename($link);
    if (!dau_valid_plg($f) || $f === 'unRAIDServer.plg' || strpos($f, 'unRAIDServer-') === 0) continue;
    $path = DAU_PLUGBOOT . '/' . $f;
    if (!is_file($path)) continue;
    $out[$f] = [
      'file' => $f, 'path' => $path, 'self' => $f === DAU_SELF_PLG,
      'name' => preg_replace('/\.plg$/', '', $f),
      'version' => dau_plg_attr($path, 'version'),
      'url' => dau_plg_attr($path, 'pluginURL'),
      'min' => dau_plg_attr($path, 'min'), 'max' => dau_plg_attr($path, 'max'),
    ];
  }
  ksort($out);
  return $out;
}

/**
 * Ask the plugin's own server for its current version. `plugin check` downloads the new plg to /tmp/plugins and prints
 * progress and hook-script lines around the version, so stdout is NOT parsed: the version is read from the downloaded file.
 */
function dau_plugin_check($file) {
  if (!dau_valid_plg($file)) return ['ok' => false, 'error' => 'invalid plugin name'];
  $tmp = DAU_PLUGTMP . '/' . $file;
  if (is_file($tmp)) @unlink($tmp);                      // never trust a stale download
  $r = dau_run([dau_plugin_bin(), 'check', $file], 120);
  if ($r['timeout']) return ['ok' => false, 'error' => 'check timed out'];
  $err = function () use ($r) { return dau_clip(dau_redact(trim(preg_replace('/[\r\n]+/', ' ', $r['err'] . ' ' . $r['out']))), 200) ?: 'no version returned'; };
  if ($r['code'] !== 0 || !is_file($tmp)) return ['ok' => false, 'error' => $err()];
  $v = dau_plg_attr($tmp, 'version');
  if ($v === '' || !preg_match('/^[A-Za-z0-9._+-]{1,40}$/', $v)) return ['ok' => false, 'error' => 'the downloaded plugin file has no valid version'];
  return ['ok' => true, 'latest' => $v];
}

/** Mirrors Unraid's own comparison (string compare of the version names). */
function dau_version_newer($latest, $installed) { return $latest !== '' && strcmp($latest, $installed) > 0; }

function dau_url_hosts($text) {
  $h = [];
  if (preg_match_all('#https?://([^/\s"\'<>:]+)#i', $text, $m)) foreach ($m[1] as $x) $h[strtolower($x)] = true;
  return array_keys($h);
}

/** Parse a plg safely: refuse external entities, no network. Returns SimpleXMLElement|null. */
function dau_plg_parse($text) {
  if (preg_match('/<!ENTITY\s+\S+\s+(SYSTEM|PUBLIC)\b/i', $text)) return null;
  $prev = libxml_use_internal_errors(true);
  if (PHP_VERSION_ID < 80000) libxml_disable_entity_loader(true);
  $x = @simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
  libxml_clear_errors(); libxml_use_internal_errors($prev);
  return $x instanceof SimpleXMLElement && $x->getName() === 'PLUGIN' ? $x : null;
}

function dau_unraid_version_ok($min, $max) {
  $cur = dau_os_version();
  if ($cur === '') return true;
  if ($min !== '' && version_compare($cur, $min, '<')) return false;
  if ($max !== '' && version_compare($cur, $max, '>')) return false;
  return true;
}

/**
 * Validate the downloaded update before it may be applied automatically.
 * @return array ['ok'=>bool,'reason'=>string,'sha'=>string]
 */
function dau_plugin_validate(array $p, $latest) {
  $no = function ($why) { return ['ok' => false, 'reason' => $why, 'sha' => '']; };
  if ($p['self']) return $no('This plugin never updates itself automatically.');
  $url = $p['url'];
  if ($url === '' || !preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?/[A-Za-z0-9._%/+@:=?&#-]*$~', $url) || strlen($url) > 400) {
    return $no('Plugin source URL is not a plain https:// address (' . dau_clip($url, 80) . ').');
  }
  $tmp = DAU_PLUGTMP . '/' . $p['file'];
  if (!is_file($tmp)) return $no('The downloaded update file is missing.');
  $size = filesize($tmp);
  if ($size === false || $size < 50 || $size > DAU_PLG_MAX_BYTES) return $no('The downloaded update file has an unexpected size.');
  $text = (string)file_get_contents($tmp);
  $x = dau_plg_parse($text);
  if ($x === null) return $no('The downloaded update is not a valid plugin file (or uses external XML entities).');
  if ((string)$x['pluginURL'] !== '' && (string)$x['pluginURL'] !== $url) return $no('The update changes the plugin\'s source URL; install it manually after reviewing.');
  if ((string)$x['name'] !== '' && (string)$x['name'] !== $p['name']) return $no('The update changes the plugin name.');
  $ver = (string)$x['version'];
  if ($ver !== $latest) return $no("Downloaded version ($ver) differs from the announced version ($latest).");
  if (!dau_version_newer($ver, $p['version'])) return $no('Not newer than the installed version.');
  if (!dau_unraid_version_ok((string)$x['min'], (string)$x['max'])) return $no('Not compatible with this Unraid version (min ' . (string)$x['min'] . ', max ' . (string)$x['max'] . ').');
  $oldText = (string)@file_get_contents($p['path']);
  $allowed = array_merge(dau_url_hosts($oldText), dau_url_hosts($url));
  $new = array_diff(dau_url_hosts($text), $allowed);
  if ($new) return $no('The update downloads from a new host (' . implode(', ', array_slice($new, 0, 3)) . '); review and install it manually.');
  return ['ok' => true, 'reason' => '', 'sha' => hash('sha256', $text)];
}

/** Eligibility (same vocabulary as containers). */
function dau_plugin_eval(array $cfg, array $st, array $p, $latest, $now, $manual = false) {
  $pc = $cfg['plugins'];
  if ($p['self'] && $manual) return ['state' => 'self', 'reason' => 'Update this plugin from Plugins > Unraid Auto Updater.', 'eta' => null];
  if ($p['self']) return ['state' => 'self', 'reason' => 'This plugin never updates itself automatically.', 'eta' => null];
  if (!dau_version_newer($latest, $p['version'])) return ['state' => 'up_to_date', 'reason' => '', 'eta' => null];
  if ($manual) return ['state' => 'ready', 'reason' => '', 'eta' => null];
  $policy = dau_plugin_policy($cfg, $p['file']);
  if (empty($pc['auto_update'])) return ['state' => $policy === 'ignore' ? 'ignored' : 'notify_only', 'reason' => 'Automatic plugin updates are switched off globally.', 'eta' => null];
  if ($policy === 'ignore') return ['state' => 'ignored', 'reason' => 'Policy is "ignore".', 'eta' => null];
  if ($policy === 'notify') return ['state' => 'notify_only', 'reason' => 'Policy is "notify only".', 'eta' => null];
  $seen = $st['plugins']['seen'][$p['file']] ?? null;
  $hours = (int)$pc['delay_hours'];
  if ($hours > 0) {
    if (!is_array($seen) || ($seen['version'] ?? '') !== $latest) return ['state' => 'no_identity', 'reason' => 'Version not tracked yet.', 'eta' => null];
    $eta = (int)$seen['first'] + $hours * 3600;
    if ($now < $eta) return ['state' => 'waiting_delay', 'reason' => "Waiting for the $hours h delay.", 'eta' => $eta];
  }
  $bl = $st['plugins']['blocked'][$p['file']] ?? null;
  if (is_array($bl) && ($bl['version'] ?? '') === $latest) return ['state' => 'blocked', 'reason' => $bl['reason'] ?? 'Blocked by a safety check.', 'eta' => null];
  if (!dau_window_open($pc['window'], $now)) return ['state' => 'window_closed', 'reason' => 'Outside the maintenance window.', 'eta' => null];
  return ['state' => 'ready', 'reason' => '', 'eta' => null];
}

/** Record when each available version was first seen. */
function dau_plugins_track(array &$st, array $avail, $now) {
  $seen = [];
  foreach ($avail as $f => $latest) {
    $r = $st['plugins']['seen'][$f] ?? null;
    $seen[$f] = (is_array($r) && ($r['version'] ?? '') === $latest) ? ['version' => $latest, 'first' => $r['first'], 'last' => $now] : ['version' => $latest, 'first' => $now, 'last' => $now];
  }
  $st['plugins']['seen'] = $seen;
  foreach (['notified', 'blocked'] as $k) foreach (array_keys($st['plugins'][$k]) as $f) if (!isset($avail[$f])) unset($st['plugins'][$k][$f]);
}

function dau_plugin_recovery_copy($p) {
  if (!is_dir(DAU_RECOVERY)) @mkdir(DAU_RECOVERY, 0755, true);
  $dst = DAU_RECOVERY . '/' . $p['name'] . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $p['version']) . '.plg';
  if (!@copy($p['path'], $dst)) return null;
  $all = glob(DAU_RECOVERY . '/' . $p['name'] . '-*.plg') ?: [];
  usort($all, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
  foreach (array_slice($all, 3) as $old) @unlink($old);
  return $dst;
}

/**
 * Apply one plugin update. Result: updated | failed | blocked, with stage and recovery guidance (no rollback is attempted).
 */
function dau_plugin_update(array $cfg, array $p, $latest) {
  $res = ['result' => 'failed', 'stage' => 'validate', 'error' => '', 'next' => '', 'prev' => ['version' => $p['version']], 'new' => ['version' => $latest], 'started' => dau_now(), 'finished' => 0, 'verification' => null, 'warnings' => []];
  $done = function () use (&$res) { $res['finished'] = dau_now(); return $res; };
  $v = dau_plugin_validate($p, $latest);
  if (!$v['ok']) { $res['result'] = 'blocked'; $res['error'] = $v['reason']; $res['next'] = 'Review the change and update from the Plugins page if you trust it.'; return $done(); }
  $rec = dau_plugin_recovery_copy($p);
  if ($rec === null) $res['warnings'][] = 'Could not save a recovery copy of the current plugin file.';
  // re-check the file has not changed between validation and install
  $tmp = DAU_PLUGTMP . '/' . $p['file'];
  if (hash('sha256', (string)@file_get_contents($tmp)) !== $v['sha']) { $res['result'] = 'blocked'; $res['error'] = 'The downloaded file changed after validation.'; return $done(); }
  $res['stage'] = 'install';
  $r = dau_run([dau_plugin_bin(), 'update', $p['file']], 900);
  $res['stage'] = 'verify';
  $now = dau_plg_attr($p['path'], 'version');
  if ($now === $latest) {
    $res['result'] = 'updated'; $res['stage'] = '';
    $res['verification'] = ['result' => 'version_matches', 'ok' => true, 'detail' => "Installed plugin file now reports version $latest."];
    return $done();
  }
  $res['error'] = $r['timeout'] ? 'The install did not finish in time.' : 'After the install the plugin still reports version ' . ($now ?: 'unknown') . ' (expected ' . $latest . '). ' . dau_clip(dau_redact(trim($r['err'] . ' ' . $r['out'])), 200);
  $res['next'] = 'Not rolled back automatically. Check Plugins for the plugin\'s state' . ($rec ? '; the previous plugin file is saved in ' . $rec . ' (copy it over /boot/config/plugins/' . $p['file'] . ' and reboot to restore it)' : '') . '.';
  return $done();
}

/** Check all installed plugins. Returns ['avail'=>[file=>latest], 'errors'=>[file=>msg]]. */
function dau_plugins_check_all(array $cfg, array $installed) {
  $avail = []; $errors = [];
  foreach ($installed as $f => $p) {
    if (dau_stop_requested()) break;
    $c = dau_plugin_check($f);
    if (!$c['ok']) { $errors[$f] = $c['error']; continue; }
    if (dau_version_newer($c['latest'], $p['version'])) $avail[$f] = $c['latest'];
  }
  return ['avail' => $avail, 'errors' => $errors];
}

function dau_plugin_slots(array $cfg) {
  $pc = $cfg['plugins'];
  if (empty($pc['check_enabled']) && empty($pc['auto_update'])) return [];
  $s = dau_clean_schedule($pc);
  return $s ? ['plugins' => ['sched' => $s, 'names' => ['*']]] : [];
}
