<?php
/* Unraid Swiss Manager - JSON API. Reads are GET/POST; EVERY change requires POST (Unraid validates the CSRF token of every POST itself). */
require_once '/usr/local/emhttp/plugins/unraid-swiss-manager/include/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function fail($m, $code = 400) { out(['error' => $m], $code); }

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$READ = ['overview', 'docker', 'plugins', 'os', 'history', 'log', 'status', 'export', 'preview', 'notes', 'cleanup_scan', 'widget', 'appdata_scan', 'quarantine_list', 'tpl_scan', 'appdata_peek', 'channel_check'];
if (!in_array($action, $READ, true)) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('This action requires POST.', 405);
  /* The CSRF token is checked (and then removed from $_POST) by Unraid's own auto-prepended local_prepend.php for every POST,
     so it must not be re-checked here: it is already gone. Mutations are POST-only. */
}
$json = function ($k) { $v = json_decode((string)($_POST[$k] ?? ''), true); return is_array($v) ? $v : null; };

/** One line in the Activity tab for a cleanup action (the details are in the log). */
function api_clean_hist($what, $result, $message = '') {
  dau_history_add(['type' => 'cleanup', 'name' => $what, 'action' => 'cleanup', 'result' => $result, 'started' => dau_now(), 'finished' => dau_now(), 'trigger' => 'manual', 'message' => $message, 'error' => $result === 'failed' ? $message : ''], dau_load_config()['history']['max_entries']);
}

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
      'retry' => $st['docker']['retry'][$c['name']] ?? null,
      'skipped' => $c['remote'] && ($st['docker']['skipped'][$c['name']] ?? '') === (string)$c['remote'],
      'pinned' => $st['docker']['pinned'][$c['name']] ?? null,
      'rollback' => isset($st['docker']['rollback'][$c['name']]) ? ['at' => $st['docker']['rollback'][$c['name']]['at'], 'from' => $st['docker']['rollback'][$c['name']]['from'] ?? '', 'to' => $st['docker']['rollback'][$c['name']]['to'] ?? '', 'ref' => $st['docker']['rollback'][$c['name']]['ref'] ?? ''] : null,
      'major' => ($st['docker']['major'][$c['name']]['remote'] ?? '') === (string)$c['remote'] && $c['remote'] ? $st['docker']['major'][$c['name']] : null];
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
      'first_seen' => $st['plugins']['seen'][$f]['first'] ?? null, 'skipped' => $latest && ($st['plugins']['skipped'][$f] ?? '') === $latest];
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
  foreach (dau_bk_slots($cfg) as $k => $s) $o[] = ['label' => $k === 'flash' ? 'Boot drive backup' : 'Appdata backup', 'text' => dau_sched_text($s['sched']), 'next' => dau_next_run($s['sched'], dau_slot_rec($st, 'b:' . $k), $now), 'count' => 1];
  return $o;
}

function api_config_view($cfg) {
  $v = $cfg; $v['discord']['webhook_set'] = dau_webhook_valid(dau_secret_get('discord_url')); $v['discord']['icon_default'] = DAU_DEFAULT_ICON;   // the URL itself is never sent to the browser
  return $v;
}

function api_save($cfg) { dau_save_config($cfg); dau_cron_apply($cfg); }

switch ($action) {

  case 'overview': {
    $cfg = dau_load_config($s); $st = dau_state_load();
    $rows = api_docker_rows($cfg, $st); $prow = api_plugin_rows($cfg, $st);
    $cnt = function ($r, $f) { return count(array_filter($r ?? [], $f)); };
    out([
      'config' => api_config_view($cfg), 'config_status' => $s, 'run' => dau_run_info(), 'now' => dau_now(), 'version' => DAU_VERSION, 'name' => DAU_NAME,
      'docker_up' => $rows !== null,
      'cards' => [
        'containers' => count($rows ?? []), 'docker_updates' => $cnt($rows, function ($r) { return $r['update'] === true; }),
        'docker_auto' => $cnt($rows, function ($r) { return $r['cfg']['policy'] === 'auto'; }),
        'plugins' => count($prow), 'plugin_updates' => $cnt($prow, function ($r) { return !empty($r['latest']); }),
        'os_installed' => dau_os_version(), 'os_available' => $st['os']['available'], 'os_error' => $st['os']['last_error'],
        'last_run' => $st['last_run'], 'last_result' => $st['last_result'], 'next' => api_next_runs($cfg, $st), 'queued' => count($st['queue']), 'quiet_now' => dau_quiet_now($cfg),
        'cleanup' => array_merge($st['cleanup'], ['quarantine' => count(dau_quarantine_load())]), 'failures' => count(array_filter(array_slice(dau_history_load(), 0, 20), function ($e) { return $e['result'] === 'failed'; })),
      ],
      'stats' => dau_stats_summary($st), 'discord' => ['last_ok' => $st['discord']['last_ok'], 'last_error' => $st['discord']['last_error'], 'invalid' => $st['discord']['invalid']],
      'checked' => ['docker' => is_file(DAU_UPDSTATUS) ? filemtime(DAU_UPDSTATUS) : 0, 'plugins' => $st['plugins']['last_check'], 'os' => $st['os']['last_check']],
      'tick_installed' => is_file(DAU_CRON), 'channel' => dau_channel_current(),
    ]);
  }

  case 'docker': { $cfg = dau_load_config(); $st = dau_state_load(); $r = api_docker_rows($cfg, $st); out(['docker_up' => $r !== null, 'rows' => $r ?? []]); }
  case 'plugins': { $cfg = dau_load_config(); $st = dau_state_load(); out(['rows' => api_plugin_rows($cfg, $st), 'auto_update' => !empty($cfg['plugins']['auto_update'])]); }
  case 'os': {
    $cfg = dau_load_config(); $st = dau_state_load(); $now = dau_now();
    $ev = dau_os_eval($cfg, $st, $now, false); $man = dau_os_eval($cfg, $st, $now, true);
    $av = $st['os']['available'];
    out(['os' => $st['os'], 'installed' => dau_os_version(), 'mode' => $cfg['os']['mode'], 'eval' => $ev, 'manual' => $man, 'stable' => $av ? dau_os_is_stable($av) : null,
         'reboot' => dau_os_reboot_safe(), 'array' => dau_os_array_state(), 'free_mb' => dau_os_boot_free_mb(), 'window_text' => dau_window_text($cfg['os']['window']),
         'skipped' => $av && ($st['os']['skipped'] ?? '') === $av, 'pending' => $st['os']['pending'], 'workflow' => $st['os']['workflow'], 'report' => $st['os']['last_report'], 'pre' => $cfg['os']['pre'],
         'pre_containers' => !empty($cfg['os']['pre']['docker']) ? count(dau_os_pre_containers($cfg)) : 0]);
  }
  case 'notes': {
    $type = (string)($_GET['type'] ?? $_POST['type'] ?? ''); $n = (string)($_GET['name'] ?? $_POST['name'] ?? ''); $st = dau_state_load();
    if ($type === 'os') out(['text' => (string)$st['os']['notes'], 'version' => (string)$st['os']['available']]);
    if ($type === 'plugin') {
      foreach (dau_plugins_installed() as $f => $p) if ($f === $n || $p['name'] === $n) out(['text' => $st['plugins']['notes'][$f] ?? dau_plugin_notes($f, $p['version']), 'version' => (string)($st['plugins']['available'][$f] ?? '')]);
      out(['text' => '']);
    }
    if ($type === 'docker' && dau_valid_name($n)) { foreach (dau_containers() ?? [] as $c) if ($c['name'] === $n) out(dau_docker_notes($c)); out(['text' => '']); }
    fail('Unknown type');
  }
  case 'widget': {       // light: state files only, no Docker call (used by the Dashboard tile)
    $st = dau_state_load(); $cfg = dau_load_config();
    out(['containers' => (int)$st['summary']['containers'], 'docker' => (int)$st['summary']['docker_updates'], 'plugins' => (int)$st['summary']['plugin_updates'], 'at' => (int)$st['summary']['at'],
         'os_update' => !empty($st['os']['available']) && ($st['os']['skipped'] ?? '') !== (string)$st['os']['available'],   // only whether one is waiting: the tile never shows a version number
         'failures' => count(array_filter(array_slice(dau_history_load(), 0, 20), function ($e) { return $e['result'] === 'failed'; })),
         'next' => (function () use ($cfg, $st) { $t = array_filter(array_column(api_next_runs($cfg, $st), 'next')); return $t ? min($t) : null; })(),
         'last_run' => $st['last_run'], 'last_status' => $st['last_result']['status'] ?? '',
         'queued' => count($st['queue']), 'pinned' => count($st['docker']['pinned']), 'running' => dau_run_info()['running'], 'now' => dau_now(), 'name' => DAU_NAME, 'version' => DAU_VERSION]);
  }
  case 'cleanup_scan': { out(dau_cleanup_scan()); }
  case 'appdata_scan': { out(dau_appdata_scan()); }
  case 'quarantine_list': { out(dau_quarantine_list()); }
  case 'tpl_scan': { out(dau_tpl_scan()); }
  case 'channel_check': { out(['ok' => true, 'current' => dau_channel_current(), 'versions' => dau_channel_versions(), 'installed' => DAU_VERSION]); }
  case 'appdata_peek': { out(dau_appdata_peek((string)($_POST['id'] ?? $_GET['id'] ?? ''))); }
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
    header('Content-Disposition: attachment; filename="unraid-swiss-manager-config.json"');
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

  /* backups: settings, last result, next run and the archives that exist (reads only) */
  case 'backups': {
    $st = dau_state_load(); $cfg = dau_load_config(); $now = dau_now(); $o = [];
    foreach (DAU_BK_KINDS as $k) {
      $b = $cfg['backups'][$k]; $why = dau_bk_check_dest($k, $cfg); $sched = dau_bk_sched($b);
      $next = ($b['schedule'] !== 'off' && $why === '' && $sched) ? dau_next_run($sched, dau_slot_rec($st, 'b:' . $k), $now) : null;
      $dir = dau_bk_dir($k, (string)$b['dest']);
      $o[$k] = ['label' => dau_bk_label($k), 'cfg' => $b, 'problem' => $why, 'sources' => dau_bk_sources($k, $cfg), 'last' => $st['backups'][$k] ?? null, 'next' => $next,
        'text' => $b['schedule'] === 'off' ? '' : dau_sched_text($sched), 'files' => $b['dest'] !== '' && dau_valid_dest($b['dest']) ? dau_bk_list($k, $b['dest']) : [],
        'folder' => $b['dest'] !== '' ? $dir : '', 'free' => $why === '' && is_dir($b['dest']) ? dau_backup_free($b['dest']) : null];
    }
    out(['ok' => true, 'backups' => $o, 'running' => (bool)(dau_read_json(DAU_PROGRESS, [])['running'] ?? false), 'now' => $now]);
  }

  case 'run': {
    $what = (string)($_POST['what'] ?? '');
    if ($what === 'check') $args = ['--check'];
    elseif ($what === 'os') $args = ['--os-check'];
    elseif ($what === 'os_install') {
      if (($_POST['confirm'] ?? '') !== '1') fail('Installing an OS update needs an explicit confirmation.');
      $args = [!empty($_POST['reboot']) && $_POST['reboot'] === '1' ? '--os-install-reboot' : '--os-install'];
      foreach (['pre_plugins' => '--pre-plugins=', 'pre_docker' => '--pre-docker='] as $k => $flag) if (isset($_POST[$k]) && in_array($_POST[$k], ['0', '1'], true)) $args[] = $flag . $_POST[$k];
    }
    elseif ($what === 'os_confirm') {
      if (($_POST['confirm'] ?? '') !== '1') fail('Installing an OS update needs an explicit confirmation.');
      $st = dau_state_load(); $wf = $st['os']['workflow'] ?? null;
      if (!is_array($wf) || ($wf['state'] ?? '') !== 'awaiting_confirmation') fail('No OS update is waiting for a confirmation.');
      $args = ['--os-confirm'];
    }
    elseif ($what === 'docker' || $what === 'plugins') {
      $names = $json('names'); if (!$names) fail('Nothing selected');
      $ok = array_values(array_filter($names, $what === 'docker' ? 'dau_valid_name' : 'dau_valid_plg'));
      if (!$ok) fail('Invalid names');
      $args = ['--update-' . $what . '=' . implode(',', $ok)];
      if ($what === 'docker' && ($_POST['allow_major'] ?? '') === '1') $args[] = '--allow-major=' . implode(',', $ok);
    } elseif ($what === 'backup') {
      $k = (string)($_POST['kind'] ?? ''); $kinds = $k === 'all' ? DAU_BK_KINDS : (in_array($k, DAU_BK_KINDS, true) ? [$k] : []);
      if (!$kinds) fail('Unknown backup type');
      $cfg = dau_load_config();
      foreach ($kinds as $kk) { $why = dau_bk_check_dest($kk, $cfg); if ($why !== '') fail(dau_bk_label($kk) . ': ' . $why); }
      $args = ['--backup=' . $k];
    } elseif ($what === 'channel') {
      $ch = (string)($_POST['channel'] ?? ''); if (!in_array($ch, ['stable', 'beta'], true)) fail('Unknown channel');
      if (($_POST['confirm'] ?? '') !== '1') fail('Changing the plugin branch needs an explicit confirmation.');
      if ($ch === 'beta' && ($_POST['beta_ok'] ?? '') !== '1') fail('The beta branch needs the test-server warning to be accepted.');
      $force = ($_POST['force'] ?? '') === '1';
      if ($ch === dau_channel_current() && !$force) fail('This server already follows that channel. Use Force update to reinstall it.');
      $args = ['--channel=' . $ch]; if ($force) $args[] = '--force';
    } elseif ($what === 'rollback') {
      $n = (string)($_POST['name'] ?? ''); if (!dau_valid_name($n)) fail('Invalid name');
      if (($_POST['confirm'] ?? '') !== '1') fail('A rollback needs an explicit confirmation.');
      $st = dau_state_load(); if (empty($st['docker']['rollback'][$n])) fail('There is no earlier image recorded for this container.');
      $args = ['--rollback=' . $n];
    } else fail('Unknown job');
    if (!dau_spawn($args)) fail('Another job is already running.', 409);
    out(['ok' => true]);
  }

  /* skip one specific version (a newer one is announced again) */
  case 'skip': case 'unskip': {
    $type = (string)($_POST['type'] ?? ''); $n = (string)($_POST['name'] ?? ''); $st = dau_state_load(); $skip = $action === 'skip';
    if ($type === 'docker') {
      if (!dau_valid_name($n)) fail('Invalid name');
      $rem = ''; foreach (dau_containers() ?? [] as $c) if ($c['name'] === $n) $rem = (string)$c['remote'];
      if ($skip && $rem === '') fail('No pending update with a known image to skip for this container.');
      if ($skip) $st['docker']['skipped'][$n] = $rem; else unset($st['docker']['skipped'][$n]);
    } elseif ($type === 'plugin') {
      $f = $n; if (!dau_valid_plg($f)) { $f = ''; foreach (dau_plugins_installed() as $pf => $p) if ($p['name'] === $n) $f = $pf; }
      $latest = $f !== '' ? ($st['plugins']['available'][$f] ?? null) : null;
      if ($skip && !$latest) fail('No pending update to skip for this plugin.');
      if ($f === '') fail('Unknown plugin');
      if ($skip) $st['plugins']['skipped'][$f] = $latest; else unset($st['plugins']['skipped'][$f]);
    } elseif ($type === 'os') {
      if ($skip && empty($st['os']['available'])) fail('No OS update to skip.');
      $st['os']['skipped'] = $skip ? (string)$st['os']['available'] : '';
    } else fail('Unknown type');
    dau_state_save($st); out(['ok' => true]);
  }

  case 'unpin': {
    $n = (string)($_POST['name'] ?? ''); if (!dau_valid_name($n)) fail('Invalid name');
    $lock = dau_lock_try(); if (!$lock) fail('Another job is running.', 409);
    $st = dau_state_load(); $r = dau_docker_unpin($st, $n); if ($r['ok']) dau_state_save($st);
    dau_lock_release($lock);
    if (!$r['ok']) fail($r['error']);
    out(['ok' => true]);
  }

  case 'cleanup_run': {
    if (($_POST['confirm'] ?? '') !== '1' && ($_POST['dry'] ?? '') !== '1') fail('Cleanup needs an explicit confirmation.');
    $imgs = $json('images') ?? []; $cons = $json('containers') ?? []; $vols = array_values(array_filter($json('volumes') ?? [], 'dau_valid_name'));
    $imgs = array_values(array_filter($imgs, function ($i) { return is_string($i) && preg_match('/^sha256:[0-9a-f]{64}$/', $i); }));
    $cons = array_values(array_filter($cons, 'dau_valid_name'));
    if (!$imgs && !$cons && !$vols) fail('Nothing selected');
    $dry = ($_POST['dry'] ?? '') === '1';
    $lock = dau_lock_try(); if (!$lock) fail('Another job is running.', 409);
    try { $r = dau_cleanup_run($imgs, $cons, $vols, $dry); } finally { dau_lock_release($lock); }
    if (!$dry) dau_log('info', 'cleanup', '-', 'remove', empty($r['failed']) ? 'ok' : 'partial', count($r['removed'] ?? []) . ' removed, ' . ($r['freed_text'] ?? '0'));
    if (!$dry && !empty($r['removed'])) api_clean_hist(count($r['removed']) . ' image / container / volume item(s) removed', 'cleaned', 'Freed about ' . ($r['freed_text'] ?? ''));
    out($r);
  }

  case 'cleanup_all': {
    /* one scan of everything: Docker images / containers / volumes and appdata; the counts are stored for the tab badge */
    $lock = dau_lock_try(); if (!$lock) fail('Another job is running.', 409);
    try {
      $a = dau_appdata_scan(); $d = dau_cleanup_scan(); $st = dau_state_load();
      $prev = $st['cleanup']; $st['cleanup'] = dau_cleanup_summary($a, $d); $st['cleanup']['reported'] = $prev['reported'] ?? ''; $st['cleanup']['reported_at'] = (int)($prev['reported_at'] ?? 0);
      dau_state_save($st);
    } finally { dau_lock_release($lock); }
    out(['appdata' => $a, 'docker' => $d, 'summary' => $st['cleanup']]);
  }

  case 'appdata_ignore': {
    $lock = dau_lock_try(); if (!$lock) fail('Another job is running.', 409);
    try { $r = dau_appdata_ignore((string)($_POST['name'] ?? ''), ($_POST['add'] ?? '1') === '1'); } finally { dau_lock_release($lock); }
    out($r);
  }

  case 'appdata_run': {
    $ids = array_values(array_filter($json('ids') ?? [], function ($i) { return is_string($i) && preg_match('/^\d:[^\/\x00-\x1F]{1,255}$/', $i); }));
    $dry = ($_POST['dry'] ?? '') === '1';
    if (!$dry && ($_POST['confirm'] ?? '') !== '1') fail('This needs an explicit confirmation.');
    if (!$ids) fail('Nothing selected');
    $lock = dau_lock_try(); if (!$lock) fail('Another job is running.', 409);
    try { $r = dau_appdata_run($ids, (string)($_POST['mode'] ?? 'quarantine'), $dry, ($_POST['confirm_delete'] ?? '') === '1'); } finally { dau_lock_release($lock); }
    if (!$dry && !empty($r['ok'])) dau_log('info', 'cleanup', '-', $r['mode'] === 'delete' ? 'appdata delete' : 'appdata quarantine', empty($r['failed']) ? 'ok' : 'partial', count($r['done']) . ' folder(s)' . ($r['mode'] === 'delete' ? ', ' . $r['freed_text'] : ''));
    if (!$dry && !empty($r['ok']) && !empty($r['done'])) api_clean_hist(count($r['done']) . ' appdata folder(s) ' . ($r['mode'] === 'delete' ? 'deleted' : 'moved to quarantine'), 'cleaned', implode(', ', array_slice($r['done'], 0, 10)));
    out($r);
  }

  case 'quarantine_act': {
    $act = (string)($_POST['act'] ?? ''); $id = (string)($_POST['id'] ?? '');
    if (!in_array($act, ['restore', 'purge'], true) || !preg_match('/^[0-9a-f]{12}$/', $id)) fail('Bad request');
    if (($_POST['confirm'] ?? '') !== '1') fail('This needs an explicit confirmation.');
    $lock = dau_lock_try(); if (!$lock) fail('Another job is running.', 409);
    try { $r = dau_quarantine_act($id, $act, ($_POST['suffix'] ?? '') === '1'); } finally { dau_lock_release($lock); }
    if (!empty($r['ok'])) { dau_log('info', 'cleanup', '-', 'quarantine ' . $act, 'ok', $id); api_clean_hist('Quarantine: folder ' . ($act === 'purge' ? 'deleted' : 'restored'), 'cleaned', $id); }
    out($r);
  }

  case 'tpl_act': {
    $act = (string)($_POST['act'] ?? '');
    if (!in_array($act, ['archive', 'restore', 'drop'], true)) fail('Bad request');
    if (($_POST['confirm'] ?? '') !== '1') fail('This needs an explicit confirmation.');
    $name = (string)($_POST['name'] ?? ''); if ($act === 'archive' && !dau_valid_name($name)) fail('Bad name');
    $lock = dau_lock_try(); if (!$lock) fail('Another job is running.', 409);
    try { $r = dau_tpl_act($act, $name, (string)($_POST['file'] ?? '')); } finally { dau_lock_release($lock); }
    if (!empty($r['ok'])) { dau_log('info', 'cleanup', $name, 'template ' . $act, 'ok', (string)($_POST['file'] ?? '')); api_clean_hist('Saved template ' . ($act === 'archive' ? 'archived' : ($act === 'restore' ? 'restored' : 'backup deleted')), 'cleaned', $name); }
    out($r);
  }

  case 'os_abort': {
    /* abort the pre-OS workflow: a running job stops after its current step, a paused one is cancelled here */
    if (dau_run_info()['running']) { @touch(DAU_STOPFLAG); out(['ok' => true, 'running' => true]); }
    $st = dau_state_load(); $wf = $st['os']['workflow'] ?? null;
    if (is_array($wf)) {
      dau_history_add(['type' => 'os', 'name' => 'Unraid OS', 'action' => 'prerequisites', 'result' => 'aborted', 'started' => (int)$wf['started'], 'finished' => dau_now(), 'trigger' => 'manual', 'error' => 'Aborted by the user. Nothing was installed.'], dau_load_config()['history']['max_entries']);
      $st['os']['workflow'] = null; dau_state_save($st);
    }
    out(['ok' => true, 'running' => false]);
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
