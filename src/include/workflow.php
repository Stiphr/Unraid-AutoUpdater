<?php
/*
 * Unraid Auto Updater - pre-OS maintenance workflow.
 * Before an Unraid OS update is installed (automatically or from the UI) the plugins - and, only if enabled, the Docker
 * containers - are brought up to date, the result is verified and the system is re-checked. The OS install runs only when
 * every REQUIRED step succeeded. It reuses dau_job_plugins() / dau_job_docker() (no second updater) and never reboots by itself.
 *
 * state.os.workflow: null | {state: running|awaiting_confirmation, version, started, reboot, manual}
 * state.os.last_report: the outcome of the latest run (shown in the UI, sent to Discord).
 */

const DAU_WORKFLOW_TTL = 86400;   // a paused workflow waits this long for a confirmation

function dau_os_pre_defaults() {
  return ['plugins' => true, 'docker' => false, 'require_plugins' => true, 'require_docker' => true, 'confirm' => false, 'notify' => true, 'scope' => 'auto'];
}

function dau_os_pre_spec() {
  return ['plugins' => ['bool'], 'docker' => ['bool'], 'require_plugins' => ['bool'], 'require_docker' => ['bool'], 'confirm' => ['bool'], 'notify' => ['bool'], 'scope' => ['enum', ['auto', 'all']]];
}

/** Containers the workflow may update: policy "auto", has an update, not "critical" (those always need an explicit approval). */
function dau_os_pre_containers(array $cfg) {
  $out = [];
  foreach ((dau_containers() ?? []) as $c) {
    $cc = dau_container_cfg($cfg, $c['name']);
    if ($c['update'] !== true || !$c['managed'] || $cc['policy'] !== 'auto' || !empty($cc['critical'])) continue;
    if (!empty($cfg['global']['skip_stopped']) && $c['state'] !== 'running') continue;
    $out[] = $c['name'];
  }
  return $out;
}

/** Re-check the system after the updates. Returns ['ok'=>bool,'issues'=>[string]]. */
function dau_os_health(array $cfg, $needDocker) {
  $issues = [];
  $a = dau_os_array_state();
  if (!$a['ok']) $issues[] = 'The array state could not be read.';
  elseif (!in_array($a['state'], ['STARTED', 'STOPPED'], true)) $issues[] = 'The array is in state ' . $a['state'] . '.';
  $free = dau_os_boot_free_mb();
  if ($free < 600) $issues[] = 'Only ' . $free . ' MB free on the flash drive (600 MB needed).';
  foreach (dau_plugins_installed() as $f => $p) if ($p['version'] === '') $issues[] = "Plugin $f could not be read after the updates.";
  if ($needDocker && dau_containers() === null) $issues[] = 'The Docker service is not responding.';
  if (dau_stop_requested()) $issues[] = 'Stopped by the user.';
  return ['ok' => !$issues, 'issues' => $issues];
}

function dau_os_wf_history(array $J, $result, $detail, $started) {
  dau_history_add(['type' => 'os', 'name' => 'Unraid OS', 'action' => 'prerequisites', 'result' => $result, 'started' => $started, 'finished' => dau_now(), 'trigger' => $J['trigger'],
    'error' => $result === 'failed' || $result === 'aborted' ? $detail : '', 'message' => $result === 'ok' || $result === 'waiting' ? $detail : ''], $J['cfg']['history']['max_entries']);
}

/**
 * Run the prerequisites. opt: manual, reboot, plugins/docker (null = use settings), skip_pre (already done, only re-check).
 * Returns 'proceed' (install now), 'wait' (paused for a confirmation) or 'stop' (the OS update must not continue).
 */
function dau_os_workflow(array &$J, $latest, array $opt) {
  $cfg = $J['cfg']; $pre = $cfg['os']['pre']; $started = dau_now();
  $manual = !empty($opt['manual']);
  $wantP = !empty($opt['skip_pre']) ? false : ($opt['plugins'] ?? $pre['plugins']);
  $wantD = !empty($opt['skip_pre']) ? false : ($opt['docker'] ?? $pre['docker']);
  $needConfirm = !$manual && !empty($pre['confirm']) && empty($opt['skip_pre']);
  if (!$wantP && !$wantD && !$needConfirm && empty($opt['skip_pre'])) return 'proceed';       // nothing configured: classic behaviour

  $inst = $J['st']['os']['installed'] ?? dau_os_version();
  $os =& $J['st']['os'];
  $os['workflow'] = ['state' => 'running', 'version' => $latest, 'started' => $started, 'reboot' => !empty($opt['reboot']), 'manual' => $manual];
  dau_state_save($J['st']);
  if ($pre['notify'] && empty($opt['skip_pre'])) {
    $steps = array_filter([$wantP ? 'plugins' : null, $wantD ? 'Docker containers' : null]);
    $J['events'][] = dau_ev('os', 'Unraid OS', 'os_workflow_started', ['installed' => $inst, 'available' => $latest, 'reason' => 'Updating ' . ($steps ? implode(' and then ', $steps) : 'nothing') . ' first, then re-checking the system.']);
  }
  $rep = ['version' => $latest, 'at' => $started, 'result' => 'ok', 'plugins' => null, 'docker' => null, 'health' => null, 'issues' => []];
  $fail = function ($result, $why) use (&$J, &$rep, $latest, $manual, $started, $inst) {
    $J['st']['os']['workflow'] = null;
    $rep['result'] = $result; $rep['issues'][] = $why; $J['st']['os']['last_report'] = $rep;
    if ($result === 'failed' && !$manual) $J['st']['os']['attempted'] = ['version' => $latest, 'result' => 'failed', 'reason' => dau_clip($why, 200), 'at' => dau_now()];
    if ($result === 'failed') dau_stats_record($J['st'], 'os', false, 0);
    $J['events'][] = dau_ev('os', 'Unraid OS', $result === 'aborted' ? 'os_aborted' : 'os_prereq_failed', ['installed' => $inst, 'available' => $latest, 'error' => $result === 'failed' ? $why : '', 'reason' => $result === 'aborted' ? $why : '',
      'detail' => dau_os_report_text($rep), 'next' => 'The Unraid OS update was NOT installed. Fix the problem and start it again from the Unraid OS tab' . ($manual ? '.' : ' (an automatic attempt is made once per version).')]);
    dau_os_wf_history($J, $result, $why, $started);
    dau_log($result === 'failed' ? 'error' : 'warn', 'os', 'Unraid OS', 'prerequisites', $result, $why);
    dau_state_save($J['st']);
    return 'stop';
  };

  if ($wantP) {
    dau_phase('Before the OS update: updating plugins');
    $b = $J['sum']['plugins'];
    dau_job_plugins($J, ['update' => true, 'pre' => $pre['scope']]);
    $a = $J['sum']['plugins'];
    $rep['plugins'] = ['updated' => $a['updated'] - $b['updated'], 'failed' => $a['failed'] - $b['failed'], 'skipped' => $a['skipped'] - $b['skipped']];
    if (dau_stop_requested()) return $fail('aborted', 'Aborted by the user during the plugin updates.');
    if ($rep['plugins']['failed'] > 0 && !empty($pre['require_plugins'])) return $fail('failed', $rep['plugins']['failed'] . ' plugin update(s) failed and successful plugin updates are required.');
  }
  if ($wantD) {
    dau_phase('Before the OS update: updating Docker containers');
    $names = dau_os_pre_containers($cfg);
    $rep['docker'] = ['updated' => 0, 'failed' => 0, 'skipped' => 0, 'selected' => count($names)];
    if ($names) {
      $b = $J['sum']['docker'];
      dau_job_docker($J, ['names' => $names, 'update' => true, 'manual' => true]);
      $a = $J['sum']['docker'];
      $rep['docker'] = ['updated' => $a['updated'] - $b['updated'], 'failed' => $a['failed'] - $b['failed'], 'skipped' => $a['skipped'] - $b['skipped'], 'selected' => count($names)];
    }
    if (dau_stop_requested()) return $fail('aborted', 'Aborted by the user during the Docker updates.');
    if ($rep['docker']['failed'] > 0 && !empty($pre['require_docker'])) return $fail('failed', $rep['docker']['failed'] . ' container update(s) failed and successful Docker updates are required.');
  }
  dau_phase('Before the OS update: checking the system');
  $h = dau_os_health($cfg, $wantD);
  $rep['health'] = $h['ok'] ? 'ok' : 'failed'; $rep['issues'] = $h['issues'];
  if (!$h['ok']) {
    if (dau_stop_requested()) return $fail('aborted', 'Aborted by the user.');
    return $fail('failed', 'System check failed: ' . implode(' ', $h['issues']));
  }
  if ($needConfirm) {
    $os['workflow'] = ['state' => 'awaiting_confirmation', 'version' => $latest, 'started' => dau_now(), 'reboot' => !empty($opt['reboot']), 'manual' => false];
    $rep['result'] = 'waiting'; $os['last_report'] = $rep;
    $J['events'][] = dau_ev('os', 'Unraid OS', 'os_awaiting', ['installed' => $inst, 'available' => $latest, 'detail' => dau_os_report_text($rep), 'next' => 'Open Unraid Auto Updater > Unraid OS and choose Confirm or Abort. Nothing has been installed.']);
    dau_os_wf_history($J, 'waiting', 'Prerequisites passed; waiting for the confirmation to install ' . $latest . '.', $started);
    dau_log('info', 'os', 'Unraid OS', 'prerequisites', 'waiting', 'waiting for confirmation');
    dau_state_save($J['st']);
    return 'wait';
  }
  $os['workflow'] = null; $os['last_report'] = $rep;
  if (empty($opt['skip_pre'])) {
    $J['events'][] = dau_ev('os', 'Unraid OS', 'os_workflow_done', ['installed' => $inst, 'available' => $latest, 'detail' => dau_os_report_text($rep), 'reason' => 'All required steps passed; installing the OS update now.']);
    dau_os_wf_history($J, 'ok', dau_os_report_text($rep), $started);
  }
  dau_log('info', 'os', 'Unraid OS', 'prerequisites', 'ok', dau_os_report_text($rep));
  dau_state_save($J['st']);
  return 'proceed';
}

/** One readable block: what was updated, skipped or failed. */
function dau_os_report_text(array $r) {
  $line = function ($label, $x) { return $x === null ? "$label: not part of this workflow" : "$label: " . (int)$x['updated'] . ' updated, ' . (int)$x['failed'] . ' failed, ' . (int)$x['skipped'] . ' skipped'; };
  $t = $line('Plugins', $r['plugins']) . "\n" . $line('Docker', $r['docker']);
  if ($r['health'] !== null) $t .= "\nSystem check: " . $r['health'];
  if (!empty($r['issues'])) $t .= "\nIssues: " . implode(' ', $r['issues']);
  return $t;
}
