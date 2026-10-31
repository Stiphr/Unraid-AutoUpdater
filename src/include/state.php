<?php
/* Unraid Swiss Manager - persistent state, history and statistics (all bounded). */

function dau_state_defaults() {
  $stat = ['ok' => 0, 'failed' => 0, 'dur' => 0];
  return [
    'version'      => 2,
    'slots'        => [],      // slot => ['seen'=>ts,'last'=>ts]
    'last_run'     => 0,
    'last_trigger' => '',
    'last_result'  => [],
    'current'      => null,    // component being changed right now (detects interrupted jobs after a reboot/kill)
    'docker'  => ['seen' => [], 'retry' => [], 'approved' => [], 'notified' => [], 'deferred' => [],
                  'skipped' => [],      // name => remote digest the person chose to skip
                  'rollback' => [],     // name => how to go back to the image before the last update
                  'pinned' => [],       // name => template Repository that was replaced by a rollback (restored by "unpin")
                  'major' => []],       // name => ['from','to','remote'] updates held back for a major version jump
    'plugins' => ['skipped' => [], 'notes' => [], 'deferred_at' => 0, 'seen' => [], 'notified' => [], 'blocked' => [], 'available' => [], 'last_check' => 0, 'last_check_ok' => 0, 'last_error' => ''],
    'os'      => ['installed' => null, 'available' => null, 'first_seen' => 0, 'notified' => null, 'withdrawn_notified' => false,
                  'last_check' => 0, 'last_check_ok' => 0, 'last_error' => '', 'notes' => '', 'error_notified' => false,
                  'pending' => null,        // ['from','to','at','reboot'] installed, waiting for a reboot
                  'attempted' => null,      // ['version','result','reason','at']: one automatic attempt per version
                  'rebooted_for' => '',     // target version an automatic reboot was already started for
                  'deferred_at' => 0, 'reboot_notified' => '', 'boot_result' => null,
                  'workflow' => null,       // pre-OS workflow in progress / waiting for a confirmation (see workflow.php)
                  'last_report' => null, 'skipped' => ''],
    'stats'   => ['docker' => $stat, 'plugin' => $stat, 'os' => $stat, 'daily' => [], 'per' => []],
    'summary' => ['containers' => 0, 'docker_updates' => 0, 'plugin_updates' => 0, 'at' => 0],     // numbers for the dashboard widget (no Docker call needed to show them)
    'queue'   => [],      // notifications held back by quiet hours
    'backups' => ['appdata' => ['at' => 0, 'ok' => null, 'file' => '', 'size' => 0, 'error' => '', 'reason' => '', 'kept' => 0], 'flash' => ['at' => 0, 'ok' => null, 'file' => '', 'size' => 0, 'error' => '', 'reason' => '', 'kept' => 0]],
    'cleanup' => ['at' => 0, 'appdata' => ['n' => 0, 'size' => 0], 'images' => ['n' => 0, 'size' => 0], 'volumes' => ['n' => 0, 'size' => 0], 'containers' => ['n' => 0, 'size' => 0], 'quarantine' => 0, 'reported' => '', 'reported_at' => 0],   // last cleanup scan: numbers for the tab badge and the report
    'discord' => ['last_ok' => 0, 'last_attempt' => 0, 'last_error' => '', 'invalid' => false],
  ];
}

function dau_state_load() {
  $d = dau_state_defaults();
  $s = dau_read_json_kept(DAU_STATE, [], 'state');
  foreach (['docker', 'plugins', 'os', 'stats', 'discord'] as $k) {
    if (isset($s[$k]) && is_array($s[$k])) $d[$k] = array_replace_recursive($d[$k], $s[$k]);
  }
  foreach (['slots', 'last_run', 'last_trigger', 'last_result', 'current', 'queue', 'summary', 'cleanup', 'backups'] as $k) {
    if (array_key_exists($k, $s) && (is_array($s[$k]) || !is_array($d[$k]))) $d[$k] = $s[$k];
  }
  if (!is_array($d['slots'])) $d['slots'] = [];
  return $d;
}

function dau_state_save(array $st) {
  /* keep the maps bounded */
  foreach (['seen', 'retry', 'approved', 'notified', 'deferred', 'skipped', 'rollback', 'pinned', 'major'] as $k) {
    if (count($st['docker'][$k]) > 1000) $st['docker'][$k] = array_slice($st['docker'][$k], -1000, null, true);
  }
  foreach (['seen', 'notified', 'blocked', 'available', 'skipped'] as $k) {
    if (count($st['plugins'][$k]) > 1000) $st['plugins'][$k] = array_slice($st['plugins'][$k], -1000, null, true);
  }
  if (count($st['queue']) > 60) $st['queue'] = array_slice($st['queue'], -60);
  return dau_write_json(DAU_STATE, $st);
}

/* ---------- schedule slots ---------- */

function dau_slot_rec(array $st, $slot) { return is_array($st['slots'][$slot] ?? null) ? $st['slots'][$slot] : ['seen' => 0, 'last' => 0]; }

/** A slot that is new starts counting now, so enabling a schedule at 10:00 never runs "this morning's" job retroactively. */
function dau_slots_register(array &$st, array $slots, $now) {
  $changed = false;
  foreach ($slots as $slot) {
    if (!isset($st['slots'][$slot])) { $st['slots'][$slot] = ['seen' => $now, 'last' => 0]; $changed = true; }
  }
  foreach (array_keys($st['slots']) as $k) if (!in_array($k, $slots, true)) { unset($st['slots'][$k]); $changed = true; }
  return $changed;
}

/* ---------- history ---------- */

function dau_history_normalize(array $e) {
  if (isset($e['container']) && !isset($e['name'])) {       // v1 entry
    $e['name'] = $e['container']; $e['type'] = 'docker';
    $e['action'] = 'update'; $e['started'] = $e['ts'] ?? 0; $e['finished'] = $e['ts'] ?? 0;
    $e['error'] = ($e['result'] ?? '') === 'failed' ? ($e['message'] ?? '') : '';
  }
  return $e + ['type' => 'docker', 'name' => '', 'action' => 'update', 'result' => '', 'started' => 0, 'finished' => 0,
               'duration' => 0, 'prev' => null, 'new' => null, 'verification' => null, 'attempts' => 1, 'error' => '',
               'trigger' => '', 'message' => '', 'ts' => $e['finished'] ?? 0];
}

function dau_history_load() {
  return array_map('dau_history_normalize', array_values(array_filter(dau_read_json_kept(DAU_HIST, [], 'history'), 'is_array')));
}

/** Failed actions that still matter: the newest entry of that item failed, within the last 3 days. A later success or an old failure does not count. */
function dau_recent_failures() {
  $n = 0; $seen = [];
  foreach (dau_history_load() as $e) {
    $k = $e['type'] . '|' . $e['name'];
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    if ($e['result'] === 'failed' && dau_now() - (int)($e['ts'] ?? $e['finished']) <= 3 * 86400) $n++;
  }
  return $n;
}

/**
 * $e: type, name, action, result, started, finished, prev{version,id,digest}, new{...}, verification{result,detail},
 *     attempts, error, trigger, message. Newest first; capped to history.max_entries.
 */
function dau_history_add(array $e, $max = 200) {
  $e = dau_history_normalize($e);
  $e['id'] = bin2hex(random_bytes(4));
  $e['ts'] = $e['finished'] ?: dau_now();
  $e['duration'] = max(0, (int)$e['finished'] - (int)$e['started']);
  $e['error'] = dau_clip(dau_redact($e['error']), 500);
  $e['message'] = dau_clip(dau_redact($e['message']), 300);
  $h = dau_history_load();
  array_unshift($h, $e);
  dau_write_json(DAU_HIST, array_slice($h, 0, max(20, (int)$max)));
  return $e;
}

/** Last entry per component, for the tables. Returns name => entry for entries that changed or tried to change something. */
function dau_history_last_by_name($type) {
  $out = [];
  foreach (dau_history_load() as $e) {
    if ($e['type'] !== $type || !in_array($e['result'], ['updated', 'failed', 'interrupted'], true)) continue;
    if (!isset($out[$e['name']])) $out[$e['name']] = $e;
  }
  return $out;
}

/* ---------- statistics (persistent counters; history alone is bounded) ---------- */

function dau_stats_record(array &$st, $type, $ok, $duration, $name = '') {
  if (!isset($st['stats'][$type])) $st['stats'][$type] = ['ok' => 0, 'failed' => 0, 'dur' => 0];
  $st['stats'][$type][$ok ? 'ok' : 'failed']++;
  if ($ok) $st['stats'][$type]['dur'] += max(0, (int)$duration);
  /* over time: one counter per day (kept 90 days) and per component (top 200) */
  $day = gmdate('Y-m-d', dau_now());
  $d =& $st['stats']['daily'][$day][$type];
  if (!is_array($d)) $d = ['ok' => 0, 'failed' => 0];
  $d[$ok ? 'ok' : 'failed']++;
  unset($d);
  if (count($st['stats']['daily']) > 90) { ksort($st['stats']['daily']); $st['stats']['daily'] = array_slice($st['stats']['daily'], -90, null, true); }
  if ($name !== '') {
    $k = $type . ':' . $name;
    $p = $st['stats']['per'][$k] ?? ['type' => $type, 'name' => $name, 'ok' => 0, 'failed' => 0, 'last' => 0];
    $p[$ok ? 'ok' : 'failed']++; $p['last'] = dau_now();
    $st['stats']['per'][$k] = $p;
    if (count($st['stats']['per']) > 200) { uasort($st['stats']['per'], function ($a, $b) { return $b['last'] <=> $a['last']; }); $st['stats']['per'] = array_slice($st['stats']['per'], 0, 200, true); }
  }
}

function dau_stats_summary(array $st) {
  $out = ['total_ok' => 0, 'total_failed' => 0, 'by_type' => [], 'avg_duration' => 0];
  $dur = 0; $n = 0;
  foreach ($st['stats'] as $t => $s) {
    if ($t === 'daily' || $t === 'per') continue;
    $out['by_type'][$t] = ['ok' => (int)$s['ok'], 'failed' => (int)$s['failed'], 'avg_duration' => $s['ok'] ? (int)round($s['dur'] / $s['ok']) : 0];
    $out['total_ok'] += (int)$s['ok']; $out['total_failed'] += (int)$s['failed'];
    $dur += (int)$s['dur']; $n += (int)$s['ok'];
  }
  $out['avg_duration'] = $n ? (int)round($dur / $n) : 0;
  /* the last 30 days, oldest first, and the most active / most failing components */
  $days = [];
  for ($i = 29; $i >= 0; $i--) {
    $k = gmdate('Y-m-d', dau_now() - $i * 86400); $r = $st['stats']['daily'][$k] ?? [];
    $days[] = ['day' => $k, 'ok' => (int)(($r['docker']['ok'] ?? 0) + ($r['plugin']['ok'] ?? 0) + ($r['os']['ok'] ?? 0)), 'failed' => (int)(($r['docker']['failed'] ?? 0) + ($r['plugin']['failed'] ?? 0) + ($r['os']['failed'] ?? 0))];
  }
  $out['daily'] = $days;
  $per = array_values($st['stats']['per'] ?? []);
  usort($per, function ($a, $b) { return ($b['ok'] + $b['failed']) <=> ($a['ok'] + $a['failed']); });
  $out['top'] = array_slice($per, 0, 8);
  $fail = array_values(array_filter($per, function ($p) { return $p['failed'] > 0; }));
  usort($fail, function ($a, $b) { return $b['failed'] <=> $a['failed']; });
  $out['top_failing'] = array_slice($fail, 0, 5);
  return $out;
}

/* ---------- interrupted jobs ---------- */

function dau_mark_current(array &$st, $type, $name, $started, array $x = []) {
  $st['current'] = ['type' => $type, 'name' => $name, 'started' => $started] + $x;
  dau_state_save($st);
}

function dau_clear_current(array &$st) {
  if ($st['current'] !== null) { $st['current'] = null; dau_state_save($st); }
}

/**
 * Called once we hold the job lock. If a previous job died while changing a component (power loss, kill),
 * record it. We never retry such a component blindly: the next normal run starts from a fresh status check.
 */
function dau_recover_interrupted(array &$st, $max) {
  $c = $st['current'];
  if (!is_array($c)) return null;
  dau_history_add(['type' => $c['type'], 'name' => $c['name'], 'action' => 'update', 'result' => 'interrupted',
                   'started' => (int)$c['started'], 'finished' => dau_now(), 'trigger' => 'recovery',
                   'error' => 'The previous run ended while this component was being updated. Check it manually; the next run starts from a fresh status check.'], $max);
  dau_log('warn', $c['type'], $c['name'], 'update', 'interrupted', 'previous run ended mid-update; no automatic retry, fresh check required');
  $st['current'] = null;
  dau_state_save($st);
  return $c;
}
