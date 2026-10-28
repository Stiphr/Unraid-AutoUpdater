<?php
/*
 * Docker Auto Update - Docker containers.
 *
 * Update flow (per container), every step verified on its own; exit codes are never trusted alone:
 *   disk check -> optional backups -> docker pull (classified) -> compare image ids -> Unraid's update_container
 *   -> verify (image id, state, health) -> targeted removal of the one replaced image.
 * A failed pull leaves the container untouched, so only pull failures are ever retried automatically.
 */

const DAU_PULL_TIMEOUT   = 1800;
const DAU_APPLY_TIMEOUT  = 900;
const DAU_MIN_FREE_BYTES = 536870912;   // below 512 MB free we do not even try to pull

function dau_docker(array $args, $timeout = 60) {
  return dau_run(array_merge([dau_bin('docker')], $args), $timeout);
}

/* ---------- update status (Unraid's own data) ---------- */

function dau_image_key($img) {
  if (strpos($img, '@') !== false) return $img;
  $pos = strrpos($img, '/');
  $last = $pos === false ? $img : substr($img, $pos + 1);
  return strpos($last, ':') === false ? $img . ':latest' : $img;
}

/** ['flag'=>true (update available)|false (up to date)|null (unknown), 'local'=>digest|null, 'remote'=>digest|null] */
function dau_update_info($img, array $map) {
  $k = dau_image_key($img);
  $nodocker = preg_replace('#^docker\.io/#', '', $k);
  foreach (array_unique([$k, $nodocker, preg_replace('#^library/#', '', $nodocker)]) as $cand) {
    if (!isset($map[$cand]) || !is_array($map[$cand])) continue;
    $e = $map[$cand];
    $local = !empty($e['local']) ? (string)$e['local'] : null;
    $remote = !empty($e['remote']) ? (string)$e['remote'] : null;
    $status = $e['status'] ?? null;
    if ($status === 'undef') return ['flag' => null, 'local' => $local, 'remote' => $remote];
    if ($local && $remote) return ['flag' => $local !== $remote, 'local' => $local, 'remote' => $remote];
    return ['flag' => null, 'local' => $local, 'remote' => $remote];
  }
  return ['flag' => null, 'local' => null, 'remote' => null];
}

/** Ask Unraid to re-check every image against its registry. nonotify: our own notifications replace the native ones. */
function dau_docker_refresh() {
  $p = DAU_DM . '/scripts/dockerupdate';
  if (!is_file($p)) return false;
  $r = dau_run([DAU_PHP, $p, 'check', 'nonotify'], 900);
  return !$r['timeout'] && $r['code'] === 0;
}

/* ---------- containers ---------- */

/** Returns the list of containers, or null when the Docker service is not running. */
function dau_containers() {
  $r = dau_docker(['ps', '-aq', '--no-trunc'], 30);
  if ($r['code'] !== 0) return null;
  $ids = array_values(array_filter(preg_split('/\s+/', trim($r['out']))));
  if (!$ids) return [];
  $r = dau_docker(array_merge(['inspect'], $ids), 90);
  $data = json_decode($r['out'], true);
  if (!is_array($data)) return [];
  $map = dau_read_json(DAU_UPDSTATUS, []);
  $out = [];
  foreach ($data as $c) {
    $name = ltrim($c['Name'] ?? '', '/');
    if ($name === '') continue;
    $labels = $c['Config']['Labels'] ?? [];
    $img = $c['Config']['Image'] ?? '';
    $u = dau_update_info($img, $map);
    $out[] = [
      'name'     => $name,
      'image'    => $img,
      'image_id' => (string)($c['Image'] ?? ''),
      'state'    => $c['State']['Status'] ?? 'unknown',
      'health'   => $c['State']['Health']['Status'] ?? null,
      'icon'     => $labels['net.unraid.docker.icon'] ?? '',
      'managed'  => dau_valid_name($name) && is_file(DAU_TEMPLATES . "/my-$name.xml") && (($labels['net.unraid.docker.managed'] ?? '') !== 'composeman'),
      'update'   => $u['flag'], 'local' => $u['local'], 'remote' => $u['remote'],
    ];
  }
  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return $out;
}

function dau_inspect_one($name) {
  if (!dau_valid_name($name)) return null;
  $r = dau_docker(['inspect', $name], 30);
  if ($r['code'] !== 0) return null;
  $d = json_decode($r['out'], true);
  return is_array($d) && isset($d[0]) ? $d[0] : null;
}

function dau_short_id($id) { return substr(preg_replace('/^sha256:/', '', (string)$id), 0, 12); }
function dau_short_digest($d) { return $d ? 'sha256:' . substr(preg_replace('/^sha256:/', '', (string)$d), 0, 12) : ''; }

/* ---------- delay tracking ---------- */

/**
 * Remember WHEN each distinct remote image was first seen. The same digest never restarts its delay; a new digest does.
 * Containers whose update is gone (updated by hand, image removed) are forgotten so the maps stay small.
 */
function dau_docker_track(array &$st, array $containers, $now) {
  $names = [];
  foreach ($containers as $c) {
    $names[$c['name']] = true;
    $n = $c['name'];
    if ($c['update'] === true && $c['remote']) {
      $rec = $st['docker']['seen'][$n] ?? null;
      if (!is_array($rec) || ($rec['remote'] ?? '') !== $c['remote']) {
        $st['docker']['seen'][$n] = ['remote' => $c['remote'], 'local' => $c['local'], 'first' => $now, 'last' => $now];
        unset($st['docker']['retry'][$n], $st['docker']['deferred'][$n]);       // a different image: old retry/approval state is void
        if (($st['docker']['approved'][$n] ?? '') !== $c['remote']) unset($st['docker']['approved'][$n]);
      } else {
        $st['docker']['seen'][$n]['last'] = $now;
      }
    } elseif ($c['update'] === false) {
      foreach (['seen', 'retry', 'approved', 'notified', 'deferred'] as $k) unset($st['docker'][$k][$n]);
    }
  }
  foreach (['seen', 'retry', 'approved', 'notified', 'deferred'] as $k) {
    foreach (array_keys($st['docker'][$k]) as $n) if (!isset($names[$n])) unset($st['docker'][$k][$n]);
  }
}

/* ---------- eligibility ---------- */

/**
 * Decide what may happen to one container right now.
 * state: external | unknown | up_to_date | ignored | notify_only | stopped | approval | no_identity |
 *        waiting_delay | retry_wait | retry_exhausted | window_closed | ready
 * $manual = the user asked for it explicitly: policy, delay, approval and window are overridden (never "external").
 */
function dau_docker_eval(array $cfg, array $st, array $c, $now, $manual = false) {
  $cc = dau_container_cfg($cfg, $c['name']);
  $g = $cfg['global'];
  $r = ['state' => 'ready', 'reason' => '', 'eta' => null, 'delay_hours' => dau_container_delay_hours($cfg, $c['name'])];
  if (!$c['managed']) return ['state' => 'external', 'reason' => 'Not created from an Unraid template, so Unraid cannot update it from here.'] + $r;
  if ($c['update'] === null) return ['state' => 'unknown', 'reason' => 'Update status not available yet. Run a check.'] + $r;
  if ($c['update'] === false) return ['state' => 'up_to_date', 'reason' => ''] + $r;
  if ($manual) return $r;
  if ($cc['policy'] === 'ignore') return ['state' => 'ignored', 'reason' => 'Policy is "ignore": no automatic action.'] + $r;
  if ($cc['policy'] === 'notify') return ['state' => 'notify_only', 'reason' => 'Policy is "notify only".'] + $r;
  if (!empty($g['skip_stopped']) && $c['state'] !== 'running') return ['state' => 'stopped', 'reason' => 'Container is not running and "skip stopped containers" is on.'] + $r;
  if (!empty($cc['critical']) && ($st['docker']['approved'][$c['name']] ?? '') !== (string)$c['remote']) {
    return ['state' => 'approval', 'reason' => 'Marked critical: waiting for your approval of this specific image.'] + $r;
  }
  $hours = $r['delay_hours'];
  if ($hours > 0) {
    $seen = $st['docker']['seen'][$c['name']] ?? null;
    if (!$c['remote'] || !is_array($seen) || ($seen['remote'] ?? '') !== $c['remote']) {
      return ['state' => 'no_identity', 'reason' => 'An update delay is set but this image\'s digest is not available, so the update is not applied automatically.'] + $r;
    }
    $eta = (int)$seen['first'] + $hours * 3600;
    if ($now < $eta) return ['state' => 'waiting_delay', 'reason' => "Waiting for the $hours h update delay.", 'eta' => $eta] + $r;
  }
  $rt = $st['docker']['retry'][$c['name']] ?? null;
  if (is_array($rt) && ($rt['digest'] ?? '') === (string)$c['remote']) {
    if (!empty($rt['exhausted'])) return ['state' => 'retry_exhausted', 'reason' => 'Automatic retries are used up for this image. Update manually or change the policy.'] + $r;
    if ($now < (int)($rt['next_at'] ?? 0)) return ['state' => 'retry_wait', 'reason' => 'A retry is scheduled.', 'eta' => (int)$rt['next_at']] + $r;
  }
  if (!dau_window_open($g['window'], $now)) return ['state' => 'window_closed', 'reason' => 'Outside the maintenance window (' . dau_window_text($g['window']) . ').'] + $r;
  return $r;
}

/** Slots (distinct schedules) of the Docker category: slot => ['sched'=>..., 'names'=>[...]]. Containers with policy ignore are not scheduled. */
function dau_docker_slots(array $cfg) {
  $slots = [];
  $def = dau_clean_schedule($cfg['global']);
  foreach ($cfg['containers'] as $name => $c) {
    $name = (string)$name;
    $cc = dau_container_cfg($cfg, $name);
    if ($cc['policy'] === 'ignore') continue;
    $own = dau_clean_schedule($cc['schedule']);
    if ($own) { $k = dau_slot_key($own); $s = $own; }
    elseif (!empty($cfg['global']['enabled'])) { $k = 'global'; $s = $def; }
    else continue;
    if (!isset($slots[$k])) $slots[$k] = ['sched' => $s, 'names' => []];
    $slots[$k]['names'][] = $name;
  }
  return $slots;
}

/* ---------- pull error classification ---------- */

/** @return array [class('transient'|'permanent'), friendly text, suggested next step] */
function dau_classify_pull_error($text) {
  $t = strtolower($text);
  foreach (['unauthorized', 'denied', 'manifest unknown', 'not found', 'no such image', 'invalid reference', 'repository does not exist', 'no matching manifest', 'authentication required'] as $p) {
    if (strpos($t, $p) !== false) {
      return ['permanent', 'The registry rejected the pull (' . $p . ').', 'Check the image name and tag in the container\'s settings, and registry credentials if the image is private. The container was not touched.'];
    }
  }
  foreach (['toomanyrequests', 'rate limit'] as $p) if (strpos($t, $p) !== false) return ['transient', 'The registry rate limit was reached.', 'Wait, or sign in to the registry. The container was not touched.'];
  foreach (['timeout', 'timed out', 'tls handshake', 'connection refused', 'connection reset', 'no such host', 'temporary failure', 'eof', 'network is unreachable', 'context deadline', '502', '503', '504', 'unexpected status'] as $p) {
    if (strpos($t, $p) !== false) return ['transient', 'Network or registry problem while pulling (' . $p . ').', 'Usually temporary. The container was not touched.'];
  }
  return ['transient', 'The image could not be pulled.', 'The container was not touched. Check the log for details.'];
}

/* ---------- verification ---------- */

/**
 * Verify a container after the update. Distinguishes: healthy, running_no_healthcheck (no health check configured -
 * NOT a failure), not_running_expected (it was stopped before), timeout (still starting), and the failures
 * image_mismatch, not_found, failed_to_start, restart_loop, unhealthy.
 * @return array ['result'=>string,'ok'=>bool,'detail'=>string]
 */
function dau_docker_verify($name, $expectedImageId, $expectRunning, $timeout) {
  $start = dau_now(); $deadline = $start + max(5, (int)$timeout);
  $stable = min(10, max(3, (int)($timeout / 6)));
  $runningSince = null; $firstRestarts = null; $startedAt = null; $lastHealth = null; $lastStatus = '';
  while (true) {
    $i = dau_inspect_one($name);
    if ($i === null) {
      if (dau_now() >= $deadline) return ['result' => 'not_found', 'ok' => false, 'detail' => 'The container does not exist after the update.'];
      dau_sleep(2); continue;
    }
    $now = dau_now();
    if ($expectedImageId && (string)($i['Image'] ?? '') !== $expectedImageId) {
      return ['result' => 'image_mismatch', 'ok' => false, 'detail' => 'The container is not using the new image (' . dau_short_id($i['Image'] ?? '') . ' instead of ' . dau_short_id($expectedImageId) . ').'];
    }
    $status = $i['State']['Status'] ?? 'unknown';
    $health = $i['State']['Health']['Status'] ?? null;
    $restarts = (int)($i['RestartCount'] ?? 0);
    $lastHealth = $health; $lastStatus = $status;
    if (!$expectRunning) {
      if (in_array($status, ['created', 'exited'], true)) return ['result' => 'not_running_expected', 'ok' => true, 'detail' => 'The container was stopped before the update and was left stopped.'];
      if ($status === 'running') return ['result' => 'running_no_healthcheck', 'ok' => true, 'detail' => 'The container is running.'];
    }
    if (in_array($status, ['exited', 'dead'], true)) {
      return ['result' => 'failed_to_start', 'ok' => false, 'detail' => "The container stopped right after the update (status $status, exit code " . (int)($i['State']['ExitCode'] ?? 0) . ').'];
    }
    if ($status === 'restarting') {
      if ($firstRestarts === null) $firstRestarts = $restarts;
      if ($restarts - $firstRestarts >= 2) return ['result' => 'restart_loop', 'ok' => false, 'detail' => 'The container keeps restarting.'];
      $runningSince = null;
    } elseif ($status === 'running') {
      if ($health === 'healthy') return ['result' => 'healthy', 'ok' => true, 'detail' => 'The container reports healthy.'];
      if ($health === 'unhealthy') return ['result' => 'unhealthy', 'ok' => false, 'detail' => 'The container health check reports unhealthy.'];
      $sa = $i['State']['StartedAt'] ?? '';
      if ($startedAt !== $sa) { $startedAt = $sa; $runningSince = $now; if ($firstRestarts === null) $firstRestarts = $restarts; }
      if ($health === null && $runningSince !== null && $now - $runningSince >= $stable && $restarts === $firstRestarts) {
        return ['result' => 'running_no_healthcheck', 'ok' => true, 'detail' => 'Running and stable for ' . $stable . ' s. This container has no health check, so health could not be confirmed.'];
      }
    }
    if ($now >= $deadline) {
      $d = $health === 'starting' ? 'The health check is still "starting" after ' . (int)$timeout . ' s.' : "Status \"$lastStatus\" after " . (int)$timeout . ' s.';
      return ['result' => 'timeout', 'ok' => false, 'detail' => $d . ' The update was applied; verification could not finish in time.'];
    }
    dau_sleep(2);
  }
}

/* ---------- the update ---------- */

function dau_docker_free_bytes() {
  $p = is_dir('/var/lib/docker') ? '/var/lib/docker' : '/';
  $f = @disk_free_space($p);
  return $f === false ? null : (float)$f;
}

/**
 * Update one container. Returns:
 *  result: updated | unchanged | failed | blocked      stage: precheck|disk|backup|pull|apply|verify
 *  transient: bool (safe to retry automatically; only ever true when the container was not touched)
 *  verification: ['result','ok','detail'] | null      prev/new: ['id','digest']      warnings: [...]
 */
function dau_docker_update(array $cfg, $name, array $c) {
  $g = $cfg['global'];
  $out = ['result' => 'failed', 'stage' => 'precheck', 'error' => '', 'transient' => false, 'verification' => null, 'next' => '', 'warnings' => [],
          'prev' => ['id' => dau_short_id($c['image_id']), 'digest' => $c['local']], 'new' => ['id' => '', 'digest' => $c['remote']],
          'backup' => null, 'cleanup' => null, 'started' => dau_now(), 'finished' => 0];
  $done = function () use (&$out) { $out['finished'] = dau_now(); return $out; };

  if (!dau_valid_name($name) || empty($c['managed'])) { $out['error'] = 'Not an Unraid-managed container.'; $out['result'] = 'blocked'; return $done(); }
  $script = DAU_DM . '/scripts/update_container';
  if (!is_file($script)) { $out['error'] = 'Unraid\'s update_container script was not found.'; $out['result'] = 'blocked'; return $done(); }
  $before = dau_inspect_one($name);
  if ($before === null) { $out['error'] = 'The container does not exist.'; $out['result'] = 'blocked'; return $done(); }
  $wasRunning = !empty($before['State']['Running']);
  $oldId = (string)($before['Image'] ?? '');
  $image = (string)($before['Config']['Image'] ?? $c['image']);
  $out['prev']['id'] = dau_short_id($oldId);
  $r = dau_docker(['image', 'inspect', '--format', '{{json .RepoDigests}}', $oldId], 30);
  $rd = json_decode($r['out'], true);
  if (is_array($rd) && $rd) $out['prev']['digest'] = preg_replace('/^.*@/', '', $rd[0]);

  /* disk space */
  if (!empty($g['disk_check'])) {
    $free = dau_docker_free_bytes();
    if ($free !== null) {
      if ($free < DAU_MIN_FREE_BYTES) { $out['stage'] = 'disk'; $out['result'] = 'blocked'; $out['error'] = 'Only ' . round($free / 1048576) . ' MB free for Docker; not pulling.'; $out['next'] = 'Free up space on the cache/docker.img, then update again.'; return $done(); }
      if ($free < (float)$g['min_free_gb'] * 1073741824) $out['warnings'][] = 'Low disk space for Docker: ' . round($free / 1073741824, 1) . ' GB free (warning level ' . (int)$g['min_free_gb'] . ' GB).';
    }
  }

  /* backups (optional) */
  $cc = dau_container_cfg($cfg, $name);
  if (!empty($g['backup']['enabled']) && !empty($cc['backup'])) {
    $b = dau_backup_run($cfg, $name, $before);
    $out['backup'] = $b;
    if (!$b['ok']) {
      if (!empty($g['backup']['required'])) { $out['stage'] = 'backup'; $out['result'] = 'blocked'; $out['error'] = 'Required backup failed: ' . $b['error']; $out['next'] = 'The container was not touched. Fix the backup destination or turn "backup required" off.'; return $done(); }
      $out['warnings'][] = 'Backup failed, updating anyway (backup is not required): ' . $b['error'];
    }
  }

  /* pull. A failure here leaves the container untouched. */
  $out['stage'] = 'pull';
  $p = dau_docker(['pull', $image], DAU_PULL_TIMEOUT);
  if ($p['timeout'] || $p['code'] !== 0) {
    $text = $p['timeout'] ? 'pull timed out' : trim($p['err'] . "\n" . $p['out']);
    list($class, $why, $next) = $p['timeout'] ? ['transient', 'The pull took too long and was stopped.', 'The container was not touched.'] : dau_classify_pull_error($text);
    $out['error'] = $why . ' ' . dau_clip(dau_redact($text), 300);
    $out['transient'] = $class === 'transient'; $out['next'] = $next;
    return $done();
  }
  $r = dau_docker(['image', 'inspect', '--format', '{{.Id}}', $image], 30);
  $newId = trim($r['out']);
  if ($r['code'] !== 0 || $newId === '') { $out['error'] = 'The image was pulled but cannot be inspected.'; $out['transient'] = true; return $done(); }
  $out['new']['id'] = dau_short_id($newId);
  if ($newId === $oldId) { $out['result'] = 'unchanged'; $out['stage'] = ''; return $done(); }   // already running the newest image: do not restart it for nothing

  /* apply with Unraid's own routine (keeps the template, network and extra settings). From here on, never auto-retry. */
  $out['stage'] = 'apply';
  $a = dau_run([DAU_PHP, $script, rawurlencode($name)], DAU_APPLY_TIMEOUT);
  if ($a['timeout']) { $out['error'] = 'Unraid\'s update routine did not finish in ' . DAU_APPLY_TIMEOUT . ' s.'; $out['next'] = 'Check the container in the Docker tab before doing anything else.'; }

  /* verify, independent of the exit code */
  $out['stage'] = 'verify';
  $vt = !empty($g['verify']['enabled']) ? (int)$g['verify']['timeout'] : 10;
  $v = dau_docker_verify($name, $newId, $wasRunning, $vt);
  $out['verification'] = $v;
  if (empty($g['verify']['enabled']) && $v['ok']) $out['verification']['detail'] = 'Health verification is switched off; only the image and container state were checked. ' . $v['detail'];

  $prevRef = $image . ($out['prev']['digest'] ? '@' . $out['prev']['digest'] : '');
  if ($v['ok'] || $v['result'] === 'timeout') {
    $out['result'] = 'updated'; $out['stage'] = '';
    if ($v['result'] === 'timeout') $out['next'] = 'Check the container\'s logs; it may simply need longer to become healthy. Increase the verification timeout in Settings if this is normal.';
    /* targeted cleanup: only the replaced image, never forced, so Docker itself refuses if anything still uses it */
    if (!empty($g['cleanup']) && $v['ok'] && $oldId !== '' && $oldId !== $newId) {
      $rm = dau_docker(['image', 'rm', $oldId], 120);
      $out['cleanup'] = $rm['code'] === 0 ? 'removed' : 'kept (still in use or already removed)';
    }
    return $done();
  }
  $out['result'] = 'failed';
  $out['error'] = $v['detail'];
  $out['next'] = 'The previous image was kept. Check the container\'s logs. To go back: edit the container, set Repository to "' . $prevRef . '" and apply. See the README section on recovery.';
  return $done();
}

/** Remove the retry record for a container that has finished (or whose image changed). */
function dau_docker_retry_clear(array &$st, $name) { unset($st['docker']['retry'][$name]); }

/**
 * Decide retry behaviour after a failure. Only a failure that left the container untouched (transient pull error)
 * is retried, at most retry.max times, retry.interval_min apart. Returns 'scheduled' | 'exhausted' | 'none'.
 */
function dau_docker_retry_after_failure(array $cfg, array &$st, $name, array $c, array $res, $now) {
  $max = (int)$cfg['global']['retry']['max'];
  if (!$res['transient'] || $max <= 0) return 'none';
  $rt = $st['docker']['retry'][$name] ?? null;
  if (!is_array($rt) || ($rt['digest'] ?? '') !== (string)$c['remote']) $rt = ['digest' => (string)$c['remote'], 'attempts' => 0];
  $rt['attempts'] = (int)$rt['attempts'] + 1;
  $rt['error'] = dau_clip($res['error'], 200);
  if ($rt['attempts'] > $max) { $rt['exhausted'] = true; unset($rt['next_at']); $st['docker']['retry'][$name] = $rt; return 'exhausted'; }
  $rt['next_at'] = $now + (int)$cfg['global']['retry']['interval_min'] * 60;
  $st['docker']['retry'][$name] = $rt;
  return 'scheduled';
}
