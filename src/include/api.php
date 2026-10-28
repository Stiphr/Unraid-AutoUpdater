<?php
/* Docker Auto Update - JSON API. Reads are GET/POST; EVERY change requires POST + Unraid's CSRF token. */
require_once '/usr/local/emhttp/plugins/docker.autoupdate/include/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function fail($m, $code = 400) { out(['error' => $m], $code); }

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$READ = ['overview', 'docker', 'plugins', 'os', 'history', 'log', 'status', 'export', 'preview'];
if (!in_array($action, $READ, true)) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('This action requires POST.', 405);
  $tok = $GLOBALS['var']['csrf_token'] ?? (@parse_ini_file(DAU_VARINI)['csrf_token'] ?? '');
  if ($tok === '' || !hash_equals((string)$tok, (string)($_POST['csrf_token'] ?? ''))) fail('Invalid security token. Reload the page.', 403);
}
$json = function ($k) { $v = json_decode((string)($_POST[$k] ?? ''), true); return is_array($v) ? $v : null; };

/** Row data for the Docker table. */
function api_docker_rows($cfg, $st) {
  $cs = dau_containers(); if ($cs === null) return null;
  $now = dau_now(); $last = dau_history_last_by_name('docker'); $rows = [];
  foreach ($cs as $c) {
    $cc = dau_container_cfg($cfg, $c['name']); $ev = dau_docker_eval($cfg, $st, $c, $now, false);
    $sched = dau_clean_schedule($cc['schedule']);
    $rows[] = ['run_state' => $c['state']] + array_diff_key($c, ['state' => 1]) + ['cfg' => $cc, 'state' => $ev['state'], 'reason' => $ev['reason'], 'eta' => $ev['eta'], 'delay_hours' => $ev['delay_hours'],
      'schedule_text' => $sched ? dau_sched_text($sched) : null, 'last' => $last[$c['name']] ?? null,
      'first_seen' => $st['docker']['seen'][$c['name']]['first'] ?? null, 'approved' => ($st['docker']['approved'][$c['name']] ?? '') === (string)$c['remote'] && $c['remote'],
      'retry' => $st['docker']['retry'][$c['name']] ?? null];
  }
  return $rows;
}

function api_plugin_rows($cfg, $st) {
  $rows = []; $now = dau_now(); $last = dau_history_last_by_name('plugin');
  foreach (dau_plugins_installed() as $f => $p) {
    $latest = $st['plugins']['available'][$f] ?? null;
    $ev = $latest ? dau_plugin_eval($cfg, $st, $p, $latest, $now, false) : ['state' => 'up_to_date', 'reason' => '', 'eta' => null];
    $rows[] = ['file' => $f, 'name' => $p['name'], 'version' => $p['version'], 'latest' => $latest, 'self' => $p['self'], 'policy' => dau_plugin_policy($cfg, $f),
      'explicit' => $cfg['plugins']['items'][$f]['policy'] ?? null, 'state' => $ev['state'], 'reason' => $ev['reason'], 'eta' => $ev['eta'], 'last' => $last[$p['name']] ?? null,
      'first_seen' => $st['plugins']['seen'][$f]['first'] ?? null];
  }
  return $rows;
}

function api_next_runs($cfg, $st) {
  $now = dau_now(); $o = [];
  $add = function ($label, $slots) use (&$o, $st, $now) { foreach ($slots as $k => $s) $o[] = ['label' => $label, 'text' => dau_sched_text($s['sched']), 'next' => dau_next_run($s['sched'], dau_slot_rec($st, $k), $now), 'count' => count($s['names'])]; };
  $d = []; foreach (dau_docker_slots($cfg) as $k => $s) $d['d:' . $k] = $s;
  $add('Docker', $d);
  $p = []; foreach (dau_plugin_slots($cfg) as $k => $s) $p['p:' . $k] = $s; $add('Plugins', $p);
  $x = []; foreach (dau_os_slots($cfg) as $k => $s) $x['o:' . $k] = $s; $add('Unraid OS', $x);
  return $o;
}

function api_config_view($cfg) {
  $v = $cfg; $v['discord']['webhook_set'] = dau_webhook_valid(dau_secret_get('discord_url'));   // the URL itself is never sent to the browser
  return $v;
}

function api_save($cfg) { dau_save_config($cfg); dau_cron_apply($cfg); }

switch ($action) {

  case 'overview': {
    $cfg = dau_load_config($s); $st = dau_state_load();
    $rows = api_docker_rows($cfg, $st); $prow = api_plugin_rows($cfg, $st);
    $cnt = function ($r, $f) { return count(array_filter($r ?? [], $f)); };
    out([
      'config' => api_config_view($cfg), 'config_status' => $s, 'run' => dau_run_info(), 'now' => dau_now(), 'version' => DAU_VERSION,
      'docker_up' => $rows !== null,
      'cards' => [
        'containers' => count($rows ?? []), 'docker_updates' => $cnt($rows, function ($r) { return $r['update'] === true; }),
        'docker_auto' => $cnt($rows, function ($r) { return $r['cfg']['policy'] === 'auto'; }),
        'plugins' => count($prow), 'plugin_updates' => $cnt($prow, function ($r) { return !empty($r['latest']); }),
        'os_installed' => dau_os_version(), 'os_available' => $st['os']['available'], 'os_error' => $st['os']['last_error'],
        'last_run' => $st['last_run'], 'last_result' => $st['last_result'], 'next' => api_next_runs($cfg, $st),
        'failures' => count(array_filter(array_slice(dau_history_load(), 0, 20), function ($e) { return $e['result'] === 'failed'; })),
      ],
      'stats' => dau_stats_summary($st), 'discord' => ['last_ok' => $st['discord']['last_ok'], 'last_error' => $st['discord']['last_error'], 'invalid' => $st['discord']['invalid']],
      'checked' => ['docker' => is_file(DAU_UPDSTATUS) ? filemtime(DAU_UPDSTATUS) : 0, 'plugins' => $st['plugins']['last_check'], 'os' => $st['os']['last_check']],
      'tick_installed' => is_file(DAU_CRON),
    ]);
  }

  case 'docker': { $cfg = dau_load_config(); $st = dau_state_load(); $r = api_docker_rows($cfg, $st); out(['docker_up' => $r !== null, 'rows' => $r ?? []]); }
  case 'plugins': { $cfg = dau_load_config(); $st = dau_state_load(); out(['rows' => api_plugin_rows($cfg, $st), 'auto_update' => !empty($cfg['plugins']['auto_update'])]); }
  case 'os': { $st = dau_state_load(); out(['os' => $st['os'], 'installed' => dau_os_version()]); }
  case 'history': { out(['rows' => array_slice(dau_history_load(), 0, 200)]); }
  case 'log': { out(['log' => dau_log_tail(300)]); }
  case 'status': { out(['run' => dau_run_info()]); }

  /* what a bulk update WILL do, shown before the user confirms */
  case 'preview': {
    $cfg = dau_load_config(); $st = dau_state_load();
    $names = array_filter(explode(',', (string)($_POST['names'] ?? $_GET['names'] ?? '')), 'dau_valid_name');
    $rows = api_docker_rows($cfg, $st) ?? []; $out = [];
    foreach ($rows as $r) {
      if ($names && !in_array($r['name'], $names, true)) continue;
      if ($r['update'] !== true) continue;
      $out[] = ['name' => $r['name'], 'image' => $r['image'], 'current' => dau_short_digest($r['local']), 'new' => dau_short_digest($r['remote']), 'state' => $r['state'], 'was_running' => $r['run_state'] === 'running',
        'managed' => $r['managed'], 'critical' => !empty($r['cfg']['critical']),
        'backup' => !empty($cfg['global']['backup']['enabled']) && !empty($r['cfg']['backup'])];
    }
    out(['rows' => $out, 'verify' => !empty($cfg['global']['verify']['enabled'])]);
  }

  case 'export': {
    header('Content-Disposition: attachment; filename="docker-autoupdate-config.json"');
    echo json_encode(dau_export_config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); exit;
  }

  /* ---- changes (POST + CSRF) ---- */

  case 'save_config': {
    $in = $json('config'); if ($in === null) fail('Invalid settings payload');
    unset($in['containers']);
    $cfg = dau_sanitize_config($in, dau_load_config());
    if (!empty($in['discord']['webhook_url'])) {
      $u = (string)$in['discord']['webhook_url'];
      if (!dau_webhook_valid($u)) fail('That is not a valid Discord webhook URL.');
      dau_secret_set('discord_url', $u);
      $st = dau_state_load(); $st['discord']['invalid'] = false; dau_state_save($st);
    }
    api_save($cfg);
    out(['ok' => true, 'config' => api_config_view($cfg)]);
  }

  case 'set_container': {
    $in = $json('data'); $names = $json('names');
    if ($in === null || !$names) fail('Invalid request');
    $cfg = dau_load_config();
    foreach ($names as $n) {
      if (!dau_valid_name($n)) continue;
      $cfg = dau_sanitize_config(['containers' => [$n => $in]], $cfg);
    }
    api_save($cfg); out(['ok' => true]);
  }

  case 'set_plugin': {
    $in = $json('data'); $files = $json('files');
    if ($in === null || !$files) fail('Invalid request');
    $cfg = dau_load_config();
    $items = [];
    foreach ($files as $f) if (dau_valid_plg($f)) $items[$f] = ['policy' => $in['policy'] ?? null];
    $cfg = dau_sanitize_config(['plugin_items' => $items], $cfg);
    api_save($cfg); out(['ok' => true]);
  }

  case 'approve': {
    $n = (string)($_POST['name'] ?? ''); if (!dau_valid_name($n)) fail('Invalid name');
    $cs = dau_containers() ?? []; $st = dau_state_load();
    foreach ($cs as $c) if ($c['name'] === $n && $c['remote']) { $st['docker']['approved'][$n] = (string)$c['remote']; dau_state_save($st); out(['ok' => true]); }
    fail('No pending update with a known image to approve for this container.');
  }

  case 'run': {
    $what = (string)($_POST['what'] ?? '');
    if ($what === 'check') $args = ['--check'];
    elseif ($what === 'os') $args = ['--os-check'];
    elseif ($what === 'docker' || $what === 'plugins') {
      $names = $json('names'); if (!$names) fail('Nothing selected');
      $ok = array_values(array_filter($names, $what === 'docker' ? 'dau_valid_name' : 'dau_valid_plg'));
      if (!$ok) fail('Invalid names');
      $args = ['--update-' . $what . '=' . implode(',', $ok)];
    } else fail('Unknown job');
    if (!dau_spawn($args)) fail('Another job is already running.', 409);
    out(['ok' => true]);
  }

  case 'stop': { @touch(DAU_STOPFLAG); out(['ok' => true]); }

  case 'test_discord': {
    $st = dau_state_load(); $u = (string)($_POST['url'] ?? '');
    $r = dau_discord_test($st, $u !== '' ? $u : null); dau_state_save($st); out($r);
  }

  case 'clear_webhook': { dau_secret_set('discord_url', ''); $cfg = dau_load_config(); $cfg['discord']['enabled'] = false; api_save($cfg); out(['ok' => true]); }

  case 'import': {
    $in = $json('config'); if ($in === null) fail('Invalid file');
    if (isset($in['config']) && is_array($in['config'])) $in = $in['config'];
    $cur = dau_load_config();
    $cfg = dau_sanitize_config($in, dau_defaults());
    dau_write_json(DAU_DIR . '/config.before-import.json', $cur);
    api_save($cfg); out(['ok' => true]);
  }

  case 'clear_history': { dau_write_json(DAU_HIST, []); out(['ok' => true]); }

  default: fail('Unknown action', 404);
}
