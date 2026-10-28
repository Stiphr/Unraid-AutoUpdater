<?php
/*
 * Docker Auto Update - job orchestration. One lock, one dispatcher (the "tick"), events collected during a run and
 * announced once at the end (Unraid notifications + one combined Discord summary).
 */

function dau_ev($type, $name, $kind, array $x = []) { return ['type' => $type, 'name' => $name, 'kind' => $kind, 'ts' => dau_now()] + $x; }

function dau_job_new($trigger, array $cfg, array $st) {
  $z = ['updated' => 0, 'failed' => 0, 'skipped' => 0, 'available' => 0];
  return ['cfg' => $cfg, 'st' => $st, 'trigger' => $trigger, 'events' => [], 'sum' => ['docker' => $z, 'plugins' => $z, 'os' => ['available' => null, 'version' => '']], 'ran' => []];
}

function dau_phase($txt, array $x = []) { dau_progress(['running' => true, 'phase' => $txt, 'ts' => dau_now()] + $x); }

function dau_hist(array &$J, $type, $name, $action, $res, $msgTrigger = '') {
  $h = $J['cfg']['history']['max_entries'];
  dau_history_add([
    'type' => $type, 'name' => $name, 'action' => $action, 'result' => $res['result'], 'started' => $res['started'], 'finished' => $res['finished'] ?: dau_now(),
    'prev' => $res['prev'] ?? null, 'new' => $res['new'] ?? null, 'verification' => $res['verification'] ?? null,
    'attempts' => $res['attempts'] ?? 1, 'error' => $res['error'] ?? '', 'trigger' => $J['trigger'],
    'message' => $res['next'] ?? '', 'backup' => isset($res['backup']['kinds']) ? $res['backup']['kinds'] : null,
  ], $h);
}

/* ---------------- Docker ---------------- */

/**
 * opts: names (null = every container), update (bool, apply updates), manual (bool: user asked, policy/delay/window ignored),
 *       refresh (bool, default true).
 */
function dau_job_docker(array &$J, array $opts) {
  $cfg = $J['cfg']; $g = $cfg['global'];
  $manual = !empty($opts['manual']); $names = $opts['names'] ?? null;
  dau_phase('Checking Docker images');
  if (($opts['refresh'] ?? true) && !dau_docker_refresh()) dau_log('warn', 'docker', '-', 'check', 'warn', 'Could not refresh update status with Unraid; using the last known status.');
  $cs = dau_containers();
  if ($cs === null) { dau_log('warn', 'docker', '-', 'check', 'skip', 'The Docker service is not running.'); return; }
  $now = dau_now();
  dau_docker_track($J['st'], $cs, $now);
  $todo = [];
  foreach ($cs as $c) {
    $n = $c['name'];
    if ($names !== null && !in_array($n, $names, true)) continue;
    $cc = dau_container_cfg($cfg, $n);
    $silent = empty($g['notify']) || empty($cc['notify']);
    $ev = dau_docker_eval($cfg, $J['st'], $c, $now, $manual);
    if ($c['update'] === true && $c['managed'] && ($cc['policy'] !== 'ignore' || $manual)) {
      $J['sum']['docker']['available']++;
      if (($J['st']['docker']['notified'][$n] ?? '') !== (string)$c['remote']) {
        $J['st']['docker']['notified'][$n] = (string)$c['remote'];
        $J['events'][] = dau_ev('docker', $n, 'available', ['installed' => dau_short_digest($c['local']), 'available' => dau_short_digest($c['remote']),
          'reason' => $ev['state'] === 'ready' ? '' : $ev['reason'], 'silent' => $silent]);
      }
    }
    if (empty($opts['update'])) continue;
    if ($ev['state'] === 'ready') { $todo[] = [$c, $ev, $silent]; continue; }
    if ($manual && $c['update'] !== false) { $J['sum']['docker']['skipped']++; $J['events'][] = dau_ev('docker', $n, 'skipped', ['reason' => $ev['reason'], 'silent' => $silent]); }
    elseif (in_array($ev['state'], ['waiting_delay', 'window_closed', 'retry_wait'], true)) {
      $at = $ev['state'] === 'window_closed' ? dau_window_next_open($g['window'], $now) : $ev['eta'];
      if ($at) $J['st']['docker']['deferred'][$n] = ['at' => (int)$at];
    } elseif ($ev['state'] === 'approval' || $ev['state'] === 'retry_exhausted') { $J['sum']['docker']['skipped']++; }
  }
  $i = 0; $total = count($todo);
  foreach ($todo as list($c, $ev, $silent)) {
    if (dau_stop_requested()) { dau_log('warn', 'job', '-', 'stop', 'info', 'Stopped on request; remaining containers were not updated.'); break; }
    if ($i++ > 0 && (int)$g['delay'] > 0) dau_sleep((int)$g['delay']);
    $n = $c['name'];
    dau_phase("Updating $n", ['done' => $i - 1, 'total' => $total, 'current' => $n]);
    dau_mark_current($J['st'], 'docker', $n, dau_now());
    $res = dau_docker_update($cfg, $n, $c);
    dau_clear_current($J['st']);
    unset($J['st']['docker']['deferred'][$n]);
    $J['ran'][] = $n;
    $x = ['installed' => dau_short_digest($res['prev']['digest']) ?: $res['prev']['id'], 'available' => dau_short_digest($res['new']['digest']) ?: $res['new']['id'],
          'duration' => $res['finished'] - $res['started'], 'verification' => $res['verification'], 'stage' => $res['stage'], 'error' => $res['error'], 'next' => $res['next'], 'silent' => $silent];
    if ($res['result'] === 'updated') {
      dau_stats_record($J['st'], 'docker', true, $x['duration']); unset($J['st']['docker']['retry'][$n], $J['st']['docker']['notified'][$n]);
      $J['sum']['docker']['updated']++; $J['events'][] = dau_ev('docker', $n, 'updated', $x);
      dau_hist($J, 'docker', $n, 'update', $res); dau_log('info', 'docker', $n, 'update', 'ok', ($res['verification']['result'] ?? '') . ' ' . implode(' | ', $res['warnings']));
    } elseif ($res['result'] === 'unchanged') {
      unset($J['st']['docker']['retry'][$n]); dau_log('info', 'docker', $n, 'update', 'skip', 'Already running the newest image.');
    } elseif ($res['result'] === 'blocked') {
      $J['sum']['docker']['skipped']++; $J['events'][] = dau_ev('docker', $n, 'blocked', ['reason' => $res['error']] + $x);
      dau_hist($J, 'docker', $n, 'update', $res); dau_log('warn', 'docker', $n, 'update', 'blocked', $res['error']);
    } else {
      $kind = 'failed'; $rn = 0;
      if (!$manual) {
        $r = dau_docker_retry_after_failure($cfg, $J['st'], $n, $c, $res, dau_now());
        $rn = (int)($J['st']['docker']['retry'][$n]['attempts'] ?? 0);
        if ($r === 'scheduled') { $kind = 'retrying'; $x['retry'] = $rn . '/' . (int)$g['retry']['max']; $x['next'] = 'Next attempt in ' . (int)$g['retry']['interval_min'] . ' min. ' . $res['next']; $J['st']['docker']['deferred'][$n] = ['at' => (int)$J['st']['docker']['retry'][$n]['next_at']]; }
        elseif ($r === 'exhausted') { $kind = 'retry_exhausted'; $x['retry'] = ($rn - 1) . ' retries used'; }
      }
      $res['attempts'] = max(1, $rn);
      dau_stats_record($J['st'], 'docker', false, 0);
      $J['sum']['docker']['failed']++; $J['events'][] = dau_ev('docker', $n, $kind, $x);
      dau_hist($J, 'docker', $n, 'update', $res); dau_log('error', 'docker', $n, 'update', 'fail', $res['stage'] . ': ' . $res['error'], $rn);
    }
    dau_state_save($J['st']);
  }
}

/* ---------------- Plugins ---------------- */

function dau_job_plugins(array &$J, array $opts) {
  $cfg = $J['cfg']; $pc = $cfg['plugins']; $now = dau_now();
  $manual = !empty($opts['manual']); $names = $opts['names'] ?? null;
  dau_phase('Checking plugins');
  $inst = dau_plugins_installed();
  $chk = dau_plugins_check_all($cfg, $inst);
  $J['st']['plugins']['last_check'] = $now;
  if (count($chk['errors']) < count($inst) || !$inst) $J['st']['plugins']['last_check_ok'] = $now;
  $J['st']['plugins']['last_error'] = $chk['errors'] ? count($chk['errors']) . ' plugin(s) could not be checked' : '';
  foreach ($chk['errors'] as $f => $e) dau_log('warn', 'plugin', $f, 'check', 'fail', $e);
  dau_plugins_track($J['st'], $chk['avail'], $now);
  $J['st']['plugins']['available'] = $chk['avail'];
  $next = 0; $todo = [];
  foreach ($chk['avail'] as $f => $latest) {
    if ($names !== null && !in_array($f, $names, true)) continue;
    $p = $inst[$f]; $policy = dau_plugin_policy($cfg, $f);
    $ev = dau_plugin_eval($cfg, $J['st'], $p, $latest, $now, $manual);
    if ($ev['state'] === 'self' && !$manual) { /* still announce */ }
    if ($policy !== 'ignore' || $manual) {
      $J['sum']['plugins']['available']++;
      if (($J['st']['plugins']['notified'][$f] ?? '') !== $latest) {
        $J['st']['plugins']['notified'][$f] = $latest;
        $J['events'][] = dau_ev('plugin', $p['name'], 'available', ['installed' => $p['version'], 'available' => $latest, 'reason' => in_array($ev['state'], ['ready', 'notify_only', 'self'], true) ? '' : $ev['reason']]);
      }
    }
    if (empty($opts['update'])) continue;
    if ($ev['state'] === 'ready') $todo[] = [$p, $latest];
    elseif ($manual) { $J['sum']['plugins']['skipped']++; $J['events'][] = dau_ev('plugin', $p['name'], 'skipped', ['reason' => $ev['reason']]); }
    elseif (in_array($ev['state'], ['waiting_delay', 'window_closed'], true)) {
      $at = $ev['state'] === 'window_closed' ? dau_window_next_open($pc['window'], $now) : $ev['eta'];
      if ($at && (!$next || $at < $next)) $next = (int)$at;
    }
  }
  $J['st']['plugins']['deferred_at'] = $next;
  $i = 0;
  foreach ($todo as list($p, $latest)) {
    if (dau_stop_requested()) break;
    if ($i++ > 0 && (int)$pc['delay'] > 0) dau_sleep((int)$pc['delay']);
    dau_phase('Updating plugin ' . $p['name'], ['current' => $p['name']]);
    dau_mark_current($J['st'], 'plugin', $p['name'], dau_now());
    $res = dau_plugin_update($cfg, $p, $latest);
    dau_clear_current($J['st']);
    $x = ['installed' => $p['version'], 'available' => $latest, 'duration' => $res['finished'] - $res['started'], 'verification' => $res['verification'], 'stage' => $res['stage'], 'error' => $res['error'], 'next' => $res['next']];
    if ($res['result'] === 'updated') { dau_stats_record($J['st'], 'plugin', true, $x['duration']); $J['sum']['plugins']['updated']++; $J['events'][] = dau_ev('plugin', $p['name'], 'updated', $x); unset($J['st']['plugins']['notified'][$p['file']]); }
    elseif ($res['result'] === 'blocked') {
      $J['sum']['plugins']['skipped']++; $J['st']['plugins']['blocked'][$p['file']] = ['version' => $latest, 'reason' => $res['error']];
      $J['events'][] = dau_ev('plugin', $p['name'], 'blocked', ['reason' => $res['error']] + $x);
    } else { dau_stats_record($J['st'], 'plugin', false, 0); $J['sum']['plugins']['failed']++; $J['events'][] = dau_ev('plugin', $p['name'], 'failed', $x); }
    dau_hist($J, 'plugin', $p['name'], 'update', $res);
    dau_log($res['result'] === 'updated' ? 'info' : 'error', 'plugin', $p['name'], 'update', $res['result'], $res['error']);
    dau_state_save($J['st']);
  }
}

/* ---------------- OS ---------------- */

function dau_job_os(array &$J) {
  dau_phase('Checking for an Unraid OS update');
  $chk = dau_os_check();
  $ev = dau_os_process($J['st'], $chk, dau_now());
  foreach ($ev as $e) $J['events'][] = dau_ev('os', 'Unraid OS', $e['kind'], $e);
  $os = $J['st']['os'];
  $J['sum']['os'] = ['available' => $chk['ok'] ? ($os['available'] !== null) : null, 'version' => (string)$os['available']];
  dau_log($chk['ok'] ? 'info' : 'warn', 'os', '-', 'check', $chk['ok'] ? 'ok' : 'fail', $chk['ok'] ? ('latest ' . $chk['latest']) : $chk['error']);
}

/* ---------------- notifications ---------------- */

function dau_job_finish(array &$J) {
  $cfg = $J['cfg']; $sum = $J['sum']; $ev = $J['events'];
  $failed = $sum['docker']['failed'] + $sum['plugins']['failed'];
  $sum['status'] = $failed ? 'failed' : (($sum['docker']['skipped'] + $sum['plugins']['skipped']) ? 'warnings' : 'ok');
  $sum['trigger'] = $J['trigger'];
  /* Unraid's own notification center */
  $nu = $cfg['notify_unraid']; $sent = 0; $more = 0;
  foreach ($ev as $e) {
    if (!empty($e['silent']) || empty($nu[['docker' => 'docker', 'plugin' => 'plugins', 'os' => 'os'][$e['type']]])) continue;
    if ($sent >= 8) { $more++; continue; }
    $label = dau_type_label($e['type']) . ' ' . $e['name'];
    $map = ['available' => ['update available', 'normal'], 'updated' => ['updated', 'normal'], 'failed' => ['update FAILED', 'alert'], 'retrying' => ['update failed, will retry', 'warning'],
            'retry_exhausted' => ['update failed, retries used up', 'alert'], 'blocked' => ['update not applied', 'warning'], 'skipped' => ['update skipped', 'normal'],
            'os_withdrawn' => ['update withdrawn', 'normal'], 'os_check_failed' => ['update check failed', 'warning'], 'os_installed' => ['now installed', 'normal']];
    list($t, $imp) = $map[$e['kind']] ?? [$e['kind'], 'normal'];
    $d = trim(($e['installed'] ?? '') && ($e['available'] ?? '') ? $e['installed'] . ' -> ' . $e['available'] : '');
    $d .= ' ' . ($e['error'] ?? $e['reason'] ?? '');
    dau_unraid_notify("$label: $t", trim($d), $imp, $e['type'] === 'os' ? '/Tools' : ($e['type'] === 'plugin' ? '/Plugins' : '/Docker'));
    $sent++;
  }
  if ($more) dau_unraid_notify("Docker Auto Update: $more more notification(s)", 'See the plugin\'s Activity tab.', 'normal', '/Settings/DockerAutoUpdate');
  /* Discord (never fails the job) */
  try { $d = dau_discord_dispatch($cfg, $J['st'], array_values($ev), $sum); }
  catch (Throwable $e) { dau_log('warn', 'discord', '-', 'send', 'fail', $e->getMessage()); }
  $J['st']['last_run'] = dau_now(); $J['st']['last_trigger'] = $J['trigger'];
  $J['st']['last_result'] = ['docker' => $sum['docker'], 'plugins' => $sum['plugins'], 'status' => $sum['status'], 'ts' => dau_now()];
  dau_state_save($J['st']);
}

/* ---------------- entry points ---------------- */

/**
 * action: tick | check | docker | plugins | os
 * Returns exit code: 0 ok, 3 another job is running.
 */
function dau_job_main($action, array $o = []) {
  $lock = dau_lock_try();
  if (!$lock) { if ($action !== 'tick') dau_log('info', 'job', '-', $action, 'skip', 'Another job is running.'); return 3; }
  try {
    $status = 'ok';
    $cfg = dau_load_config($status);
    $st = dau_state_load();
    $max = $cfg['history']['max_entries'];
    $rec = dau_recover_interrupted($st, $max);
    $now = dau_now();
    $J = dau_job_new($action === 'tick' ? 'scheduled' : 'manual', $cfg, $st);

    if ($action === 'tick') {
      $slots = []; $names = [];
      foreach (dau_docker_slots($cfg) as $k => $s) $slots['d:' . $k] = $s;
      foreach (dau_plugin_slots($cfg) as $k => $s) $slots['p:' . $k] = $s;
      foreach (dau_os_slots($cfg) as $k => $s) $slots['o:' . $k] = $s;
      dau_slots_register($J['st'], array_keys($slots), $now);
      $doDocker = []; $doPlug = false; $doOs = false;
      foreach ($slots as $k => $s) {
        if (!dau_slot_due($s['sched'], dau_slot_rec($J['st'], $k), $now)) continue;
        $J['st']['slots'][$k]['last'] = $now;
        if ($k[0] === 'd') $doDocker = array_merge($doDocker, $s['names']); elseif ($k[0] === 'p') $doPlug = true; else $doOs = true;
      }
      foreach ($J['st']['docker']['deferred'] as $n => $d) if ((int)($d['at'] ?? 0) <= $now) { $doDocker[] = $n; unset($J['st']['docker']['deferred'][$n]); }
      if (!$doPlug && (int)$J['st']['plugins']['deferred_at'] > 0 && (int)$J['st']['plugins']['deferred_at'] <= $now) $doPlug = true;
      $doDocker = array_values(array_unique($doDocker));
      if ($doDocker) dau_job_docker($J, ['names' => $doDocker, 'update' => true, 'manual' => false]);
      if ($doPlug) dau_job_plugins($J, ['update' => true]);
      if ($doOs) dau_job_os($J);
      if (!$doDocker && !$doPlug && !$doOs) { dau_state_save($J['st']); dau_lock_release($lock); return 0; }
    } elseif ($action === 'check') {
      dau_job_docker($J, ['update' => false]); dau_job_plugins($J, ['update' => false]); dau_job_os($J);
    } elseif ($action === 'docker') {
      dau_job_docker($J, ['names' => $o['names'] ?? [], 'update' => true, 'manual' => true]);
    } elseif ($action === 'plugins') {
      dau_job_plugins($J, ['names' => $o['names'] ?? [], 'update' => true, 'manual' => true]);
    } elseif ($action === 'os') {
      dau_job_os($J);
    }
    dau_job_finish($J);
    dau_progress(['running' => false, 'ts' => dau_now(), 'phase' => 'Done']);
    dau_log('info', 'job', '-', $action, 'done', json_encode($J['sum']['docker']) . ' ' . json_encode($J['sum']['plugins']));
    return 0;
  } catch (Throwable $e) {
    dau_log('error', 'job', '-', $action, 'crash', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    dau_progress(['running' => false, 'ts' => dau_now(), 'phase' => 'Failed']);
    return 1;
  } finally {
    dau_lock_release($lock);
    @unlink(DAU_STOPFLAG);
  }
}

/* ---------------- cron / install ---------------- */

/** One line, every 5 minutes, only when something is scheduled. */
function dau_cron_apply(array $cfg) {
  $any = dau_docker_slots($cfg) || dau_plugin_slots($cfg) || dau_os_slots($cfg);
  if ($any) {
    $line = "# Docker Auto Update dispatcher (generated; do not edit)\n*/" . DAU_TICK_MIN . ' * * * * ' . DAU_PHP . ' ' . DAU_ROOT . "/scripts/run.php --tick > /dev/null 2>&1\n";
    if (!is_dir(DAU_DIR)) @mkdir(DAU_DIR, 0755, true);
    if (@file_get_contents(DAU_CRON) !== $line) file_put_contents(DAU_CRON, $line);
  } elseif (is_file(DAU_CRON)) @unlink(DAU_CRON);
  if (is_executable('/usr/local/sbin/update_cron')) dau_run(['/usr/local/sbin/update_cron'], 30);
  return true;
}

/** Runs at every boot (install script): migrate config, rebuild cron, tidy leftovers. */
function dau_install() {
  if (!is_dir(DAU_DIR)) @mkdir(DAU_DIR, 0755, true);
  $status = 'ok'; $cfg = dau_load_config($status);
  if ($status !== 'ok' || !is_file(DAU_CFG)) dau_save_config($cfg);
  dau_cron_apply($cfg);
  @unlink(DAU_STOPFLAG); @unlink(DAU_PROGRESS);
  dau_log('info', 'job', '-', 'install', $status, 'Config ' . $status . '; version ' . DAU_VERSION);
  return $status;
}

function dau_uninstall() {
  if (is_file(DAU_CRON)) @unlink(DAU_CRON);
  if (is_executable('/usr/local/sbin/update_cron')) dau_run(['/usr/local/sbin/update_cron'], 30);
}
