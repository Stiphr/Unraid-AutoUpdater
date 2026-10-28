<?php
/*
 * Unraid AutoUpdater - extra features: dependency order, major-version flag, rollback, cleanup tools, release notes,
 * quiet hours and the links used in notifications. Nothing here runs unless the matching setting or button is used.
 */

/* ---------------- quiet hours ---------------- */

/** True while quiet hours are active. Notifications are held back (not lost) and sent afterwards. */
function dau_quiet_now(array $cfg, $ts = null) {
  $q = $cfg['quiet'] ?? null;
  if (!is_array($q) || empty($q['enabled'])) return false;
  return dau_window_open(['enabled' => true, 'days' => $q['days'], 'start' => $q['start'], 'end' => $q['end']], $ts ?? dau_now());
}

/** Events that may be sent during quiet hours when "let urgent messages through" is on. */
function dau_event_urgent(array $ev) {
  return in_array($ev['kind'], ['failed', 'retry_exhausted', 'os_install_failed', 'os_prereq_failed', 'os_unverified', 'os_rebooting', 'os_reboot_blocked', 'rollback_failed', 'os_staged'], true);
}

/** @return array [events to send now, events to hold] */
function dau_quiet_split(array $cfg, array $events, $ts = null) {
  if (!dau_quiet_now($cfg, $ts)) return [$events, []];
  $now = []; $hold = [];
  foreach ($events as $e) {
    if (!empty($e['silent'])) { $now[] = $e; continue; }            // already muted: nothing to hold
    if (!empty($cfg['quiet']['urgent']) && dau_event_urgent($e)) $now[] = $e; else $hold[] = $e;
  }
  return [$now, $hold];
}

/* ---------------- dependency order ---------------- */

/**
 * Put containers in the order they must be updated: a container with "update after X" waits for X when both are in the list.
 * Stable for everything else; a cycle falls back to the original order. $todo items are [container, eval, silent].
 */
function dau_docker_order(array $todo, array $cfg) {
  $byName = []; foreach ($todo as $i => $t) $byName[$t[0]['name']] = $i;
  $deps = [];
  foreach ($todo as $t) {
    $n = $t[0]['name']; $cc = dau_container_cfg($cfg, $n);
    $deps[$n] = array_values(array_filter((array)$cc['after'], function ($d) use ($byName, $n) { return $d !== $n && isset($byName[$d]); }));
  }
  $out = []; $done = [];
  $left = array_keys($byName);
  while ($left) {
    $progress = false;
    foreach ($left as $k => $n) {
      $ok = true; foreach ($deps[$n] as $d) if (empty($done[$d])) { $ok = false; break; }
      if ($ok) { $out[] = $todo[$byName[$n]]; $done[$n] = true; unset($left[$k]); $progress = true; break; }
    }
    if (!$progress) { foreach ($left as $n) $out[] = $todo[$byName[$n]]; break; }     // cycle
  }
  return $out;
}

/* ---------------- image versions (major-version flag) ---------------- */

/** Version of an image from its labels / well-known environment variables. @return array ['version'=>string,'major'=>int|null] */
function dau_image_version($imageId) {
  $none = ['version' => '', 'major' => null, 'source' => ''];
  if ($imageId === '' || $imageId === null) return $none;
  $r = dau_docker(['image', 'inspect', '--format', '{{json .Config}}', $imageId], 30);
  $c = json_decode($r['out'], true);
  if (!is_array($c)) return $none;
  $lab = is_array($c['Labels'] ?? null) ? $c['Labels'] : [];
  $v = '';
  foreach (['org.opencontainers.image.version', 'org.label-schema.version', 'version'] as $k) if (!empty($lab[$k]) && is_string($lab[$k])) { $v = $lab[$k]; break; }
  if ($v === '' || $v === 'latest' || $v === 'edge' || $v === 'nightly') {
    $env = [];
    foreach ((array)($c['Env'] ?? []) as $e) if (is_string($e) && strpos($e, '=') !== false) { list($ek, $ev) = explode('=', $e, 2); $env[$ek] = $ev; }
    foreach (['PG_MAJOR', 'PG_VERSION', 'MYSQL_MAJOR', 'MYSQL_VERSION', 'MARIADB_VERSION', 'MONGO_MAJOR', 'MONGO_VERSION', 'REDIS_VERSION', 'APP_VERSION', 'VERSION'] as $k) {
      if (!empty($env[$k])) { $v = $env[$k]; break; }
    }
  }
  $v = dau_clip(trim((string)$v), 60);
  $src = (string)($lab['org.opencontainers.image.source'] ?? $lab['org.opencontainers.image.url'] ?? '');
  $major = preg_match('/^v?(\d+)/i', $v, $m) ? (int)$m[1] : null;
  return ['version' => $v, 'major' => $major, 'source' => preg_match('#^https://[A-Za-z0-9./_-]{3,150}$#', $src) ? $src : ''];
}

/** Is this a jump to a different major version? */
function dau_major_jump(array $from, array $to) {
  return $from['major'] !== null && $to['major'] !== null && $from['major'] !== $to['major'];
}

/* ---------------- release notes ---------------- */

/** Changes of a plugin update (from the downloaded plg), only entries newer than the installed version. */
function dau_plugin_notes($file, $installed) {
  if (!dau_valid_plg($file)) return '';
  $tmp = DAU_PLUGTMP . '/' . $file;
  if (!is_file($tmp)) return '';
  $text = (string)@file_get_contents($tmp);
  if (!preg_match('#<CHANGES>(.*?)</CHANGES>#s', $text, $m)) return '';
  $body = preg_replace('/^\s*<!\[CDATA\[|\]\]>\s*$/', '', trim($m[1]));
  $parts = preg_split('/^(?=###)/m', $body);
  $keep = [];
  foreach ($parts as $p) {
    $p = trim($p); if ($p === '') continue;
    if (preg_match('/^###\s*(\S+)/', $p, $h)) { if (strcmp($h[1], (string)$installed) > 0) $keep[] = $p; }
  }
  if (!$keep) { $first = array_values(array_filter(array_map('trim', $parts))); $keep = array_slice($first, 0, 1); }
  return dau_clip(dau_redact(implode("\n\n", array_slice($keep, 0, 6))), 3500);
}

/** Where the notes of a container image live: its source repository's releases page. */
function dau_docker_notes(array $c) {
  $v = dau_image_version($c['image_id'] ?? '');
  $url = '';
  if ($v['source'] !== '' && preg_match('#^https://(github\.com|gitlab\.com)/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+#', $v['source'], $m)) $url = rtrim(preg_replace('/\.git$/', '', $m[0]), '/') . ($m[1] === 'github.com' ? '/releases' : '/-/releases');
  return ['version' => $v['version'], 'link' => $url, 'text' => $url ? '' : 'This image does not say where its release notes are. Look at its project page.'];
}

/* ---------------- links in notifications ---------------- */

function dau_ui_base(array $cfg) {
  $u = (string)($cfg['discord']['ui_url'] ?? '');
  return $u !== '' ? rtrim($u, '/') : 'http://' . preg_replace('/[^A-Za-z0-9.-]/', '', dau_server_name());
}

/** "[Update now](...) · [Skip this version](...)" for an update-available message; '' when it does not apply. */
function dau_action_links(array $cfg, array $ev) {
  if (empty($cfg['discord']['action_links']) || ($ev['kind'] ?? '') !== 'available') return '';
  $type = $ev['type'] ?? ''; $name = (string)($ev['name'] ?? '');
  if (!in_array($type, ['docker', 'plugin', 'os'], true) || ($type !== 'os' && $name === '')) return '';
  $base = dau_ui_base($cfg) . '/Settings/UnraidAutoUpdater';
  $link = function ($label, $act) use ($base, $type, $name) { return '[' . $label . '](' . $base . '?' . http_build_query(['act' => $act, 'type' => $type, 'name' => $name]) . ')'; };
  $o = [];
  if ($type !== 'os') $o[] = $link('Update now', 'update');
  if ($type === 'docker' && stripos((string)($ev['reason'] ?? ''), 'approval') !== false) $o[] = $link('Approve', 'approve');
  $o[] = $link($type === 'os' ? 'Skip this release' : 'Skip this version', 'skip');
  if ($type === 'os') $o[] = $link('Open Unraid OS tab', 'open');
  return implode(' · ', $o);
}

/* ---------------- rollback ---------------- */

function dau_template_path($name) { return DAU_TEMPLATES . '/my-' . $name . '.xml'; }

/** Only a plain image reference may be written into a template. */
function dau_valid_image_ref($r) { return is_string($r) && strlen($r) <= 300 && preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/@-]*$#', $r) === 1; }

function dau_template_repo($xml) { return preg_match('#<Repository>([^<]*)</Repository>#', $xml, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES) : null; }

function dau_template_set_repo($xml, $repo) {
  $new = preg_replace_callback('#<Repository>[^<]*</Repository>#', function () use ($repo) { return '<Repository>' . htmlspecialchars($repo, ENT_QUOTES) . '</Repository>'; }, $xml, 1, $n);
  return $n === 1 ? $new : null;
}

/**
 * Go back to the image the container used before its last update. The template's Repository is switched to the old
 * image's digest and Unraid's own update routine recreates the container; the container is then pinned and the version
 * that was rolled back is skipped, so nothing updates it again by itself. $res: result, error, next, verification.
 */
function dau_docker_rollback(array $cfg, array &$st, $name) {
  $res = ['result' => 'failed', 'stage' => 'precheck', 'error' => '', 'next' => '', 'verification' => null, 'started' => dau_now(), 'finished' => 0, 'prev' => null, 'new' => null];
  $done = function () use (&$res) { $res['finished'] = dau_now(); return $res; };
  $rec = $st['docker']['rollback'][$name] ?? null;
  if (!dau_valid_name($name) || !is_array($rec) || !dau_valid_image_ref($rec['ref'] ?? '')) { $res['error'] = 'There is no earlier image recorded for this container.'; $res['result'] = 'blocked'; return $done(); }
  $script = DAU_DM . '/scripts/update_container';
  $tp = dau_template_path($name);
  if (!is_file($script) || !is_file($tp)) { $res['error'] = 'The container is not managed by an Unraid template, so it cannot be recreated here.'; $res['result'] = 'blocked'; return $done(); }
  $before = dau_inspect_one($name);
  if ($before === null) { $res['error'] = 'The container does not exist.'; $res['result'] = 'blocked'; return $done(); }
  $wasRunning = !empty($before['State']['Running']);
  $xml = (string)file_get_contents($tp);
  $orig = dau_template_repo($xml);
  if ($orig === null) { $res['error'] = 'The container template has no Repository entry.'; $res['result'] = 'blocked'; return $done(); }
  $res['prev'] = ['id' => dau_short_id($before['Image'] ?? ''), 'digest' => (string)($rec['new_digest'] ?? '')];
  $res['new'] = ['id' => dau_short_id($rec['prev_id'] ?? ''), 'digest' => (string)($rec['prev_digest'] ?? '')];

  $res['stage'] = 'pull';
  $p = dau_docker(['pull', $rec['ref']], DAU_PULL_TIMEOUT);
  if ($p['timeout'] || $p['code'] !== 0) { $res['error'] = 'The earlier image could not be pulled: ' . dau_clip(dau_redact(trim($p['err'] . ' ' . $p['out'])), 250); $res['next'] = 'The container was not touched.'; return $done(); }
  $r = dau_docker(['image', 'inspect', '--format', '{{.Id}}', $rec['ref']], 30);
  $oldId = trim($r['out']);

  /* keep a copy of the template, switch it to the old image, recreate */
  $keep = DAU_DIR . '/rollback'; if (!is_dir($keep)) @mkdir($keep, 0755, true);
  @file_put_contents($keep . '/my-' . $name . '.xml.' . dau_now() . '.bak', $xml);
  foreach (array_slice(array_reverse(glob($keep . '/my-' . $name . '.xml.*.bak') ?: []), 3) as $old) @unlink($old);
  $new = dau_template_set_repo($xml, $rec['ref']);
  if ($new === null || @file_put_contents($tp, $new) === false) { $res['error'] = 'The container template could not be changed.'; return $done(); }
  $res['stage'] = 'apply';
  $a = dau_run([DAU_PHP, $script, rawurlencode($name)], DAU_APPLY_TIMEOUT);
  $res['stage'] = 'verify';
  $v = dau_docker_verify($name, $oldId !== '' ? $oldId : null, $wasRunning, !empty($cfg['global']['verify']['enabled']) ? (int)$cfg['global']['verify']['timeout'] : 10);
  $res['verification'] = $v;
  if ($v['ok'] || $v['result'] === 'timeout') {
    $res['result'] = 'rolled_back'; $res['stage'] = '';
    $st['docker']['pinned'][$name] = ['repository' => $orig, 'ref' => $rec['ref'], 'at' => dau_now()];
    if (!empty($rec['new_digest'])) $st['docker']['skipped'][$name] = (string)$rec['new_digest'];
    unset($st['docker']['rollback'][$name]);
    $res['next'] = 'The container is pinned to the earlier image and the version you rolled back from is skipped. Use "Unpin" on the Docker tab to follow updates again.';
    return $done();
  }
  @file_put_contents($tp, $xml);              // the template goes back to what it was; the container is not recreated again automatically
  $res['error'] = $v['detail'];
  $res['next'] = 'The template was restored but the container may need attention: check it in the Docker tab and its logs.';
  return $done();
}

/** Undo the pin: the template gets its original Repository back (the container itself is not touched). */
function dau_docker_unpin(array &$st, $name) {
  $pin = $st['docker']['pinned'][$name] ?? null;
  if (!is_array($pin) || !dau_valid_name($name)) return ['ok' => false, 'error' => 'This container is not pinned.'];
  $tp = dau_template_path($name);
  $xml = is_file($tp) ? (string)file_get_contents($tp) : '';
  $cur = $xml !== '' ? dau_template_repo($xml) : null;
  if ($cur === null) return ['ok' => false, 'error' => 'The container template was not found.'];
  if ($cur === $pin['repository']) { unset($st['docker']['pinned'][$name]); return ['ok' => true]; }
  $new = dau_template_set_repo($xml, (string)$pin['repository']);
  if ($new === null || @file_put_contents($tp, $new) === false) return ['ok' => false, 'error' => 'The container template could not be changed.'];
  unset($st['docker']['pinned'][$name]);
  return ['ok' => true];
}

/* ---------------- cleanup tools ---------------- */

function dau_bytes_text($b) {
  $b = (float)$b;
  if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
  if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
  return round($b / 1024) . ' kB';
}

/**
 * Unused images and leftover containers, with sizes. Nothing is removed here.
 * Images: dangling (no name) or not used by any container. Containers: stopped, not created from an Unraid template, not compose.
 */
function dau_cleanup_scan() {
  $cs = dau_containers();
  if ($cs === null) return ['ok' => false, 'error' => 'The Docker service is not running.'];
  $used = [];
  foreach ($cs as $c) $used[$c['image_id']] = true;
  $r = dau_docker(['image', 'ls', '-a', '--no-trunc', '--format', '{{.ID}}|{{.Repository}}|{{.Tag}}|{{.CreatedSince}}'], 60);
  $imgs = [];
  foreach (preg_split('/\R/', trim($r['out'])) as $line) {
    $f = explode('|', $line, 4); if (count($f) < 4 || !preg_match('/^sha256:[0-9a-f]{64}$/', $f[0])) continue;
    if (isset($used[$f[0]])) continue;
    $imgs[$f[0]] = ['id' => $f[0], 'short' => dau_short_id($f[0]), 'tags' => [], 'dangling' => true, 'created' => $f[3], 'size' => 0, 'status' => 'eligible', 'reason' => 'It has no name or tag and no container uses it. This is usually a leftover from an update.'];
    if ($f[1] !== '<none>') { $imgs[$f[0]]['tags'][] = $f[1] . ($f[2] !== '<none>' ? ':' . $f[2] : ''); $imgs[$f[0]]['dangling'] = false; $imgs[$f[0]]['status'] = 'review'; $imgs[$f[0]]['reason'] = 'It was downloaded earlier and no container uses it now. Check that you do not need it; it can be downloaded again.'; }
  }
  if ($imgs) {
    $r = dau_docker(array_merge(['image', 'inspect', '--format', '{{.Id}} {{.Size}}'], array_keys($imgs)), 60);
    foreach (preg_split('/\R/', trim($r['out'])) as $line) { $p = explode(' ', trim($line)); if (count($p) === 2 && isset($imgs[$p[0]])) $imgs[$p[0]]['size'] = (int)$p[1]; }
  }
  $orph = [];
  $names = [];
  foreach ($cs as $c) if (!$c['managed'] && $c['state'] !== 'running' && in_array($c['state'], ['exited', 'created', 'dead'], true)) $names[] = $c['name'];
  if ($names) {
    $r = dau_docker(array_merge(['inspect', '-s'], $names), 60);
    $data = json_decode($r['out'], true);
    foreach (is_array($data) ? $data : [] as $d) {
      $lab = $d['Config']['Labels'] ?? [];
      if (isset($lab['com.docker.compose.project']) || !empty($lab['net.unraid.docker.managed'])) continue;
      $n = ltrim($d['Name'] ?? '', '/'); if (!dau_valid_name($n)) continue;
      $orph[] = ['name' => $n, 'image' => (string)($d['Config']['Image'] ?? ''), 'state' => (string)($d['State']['Status'] ?? ''), 'size' => (int)($d['SizeRw'] ?? 0), 'finished' => (string)($d['State']['FinishedAt'] ?? ''), 'status' => 'review', 'reason' => 'A stopped container that was not created from an Unraid template. Removing it deletes the container, not its appdata.'];
    }
  }
  /* volumes no container uses (Docker itself says "dangling"); anonymous ones have a 64-character hex name */
  $vols = [];
  $r = dau_docker(['volume', 'ls', '-q', '-f', 'dangling=true'], 30);
  $vn = array_values(array_filter(array_map('trim', preg_split('/\R/', $r['out'])), function ($n) { return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $n) === 1; }));
  if ($r['code'] === 0 && $vn) {
    $r = dau_docker(array_merge(['volume', 'inspect'], array_slice($vn, 0, 200)), 60);
    $vd = json_decode($r['out'], true);
    foreach (is_array($vd) ? $vd : [] as $v) {
      $n = (string)($v['Name'] ?? ''); $mp = (string)($v['Mountpoint'] ?? '');
      if (!in_array($n, $vn, true)) continue;
      $size = ($mp !== '' && strpos($mp, '/var/lib/docker/volumes/') === 0 && strpos($mp, '..') === false) ? dau_dir_size($mp) : 0;
      $an = preg_match('/^[0-9a-f]{64}$/', $n) === 1;
      $vols[] = ['name' => $n, 'anonymous' => $an, 'size' => $size, 'created' => (string)($v['CreatedAt'] ?? ''), 'status' => 'review', 'reason' => $an ? 'No container uses this unnamed volume. It may hold data of a removed container.' : 'No container uses this named volume. It was created on purpose, so it may hold data you want.'];
    }
    usort($vols, function ($a, $b) { return $b['size'] <=> $a['size']; });
  }
  $imgs = array_values($imgs);
  usort($imgs, function ($a, $b) { return $b['size'] <=> $a['size']; });
  $total = 0; foreach ($imgs as $i) $total += $i['size']; foreach ($orph as $o) $total += $o['size']; foreach ($vols as $v) $total += $v['size'];
  return ['ok' => true, 'images' => $imgs, 'containers' => $orph, 'volumes' => $vols, 'in_use_images' => count($used), 'total' => $total, 'total_text' => dau_bytes_text($total)];
}

/**
 * Remove exactly the chosen items, each re-checked against a fresh scan. Never forced.
 * $dry = only report what would be removed. 'skipped' = no longer valid (in use, gone); 'failed' = Docker refused.
 * 'size_removed' is the size measured BEFORE removal (images share layers, so the space really freed can be smaller).
 */
function dau_cleanup_run(array $imageIds, array $containerNames, array $volumeNames = [], $dry = false) {
  $scan = dau_cleanup_scan();
  if (!$scan['ok']) return $scan;
  $okImg = []; foreach ($scan['images'] as $i) $okImg[$i['id']] = $i;
  $okCon = []; foreach ($scan['containers'] as $c) $okCon[$c['name']] = $c;
  $okVol = []; foreach ($scan['volumes'] ?? [] as $v) $okVol[$v['name']] = $v;
  $out = ['ok' => true, 'dry' => (bool)$dry, 'removed' => [], 'failed' => [], 'skipped' => [], 'would' => [], 'freed' => 0, 'size_removed' => 0];
  $do = function ($label, $size, callable $rm) use (&$out, $dry) {
    if ($dry) { $out['would'][] = $label; $out['size_removed'] += $size; return; }
    $r = $rm();
    if ($r['code'] === 0) { $out['removed'][] = $label; $out['size_removed'] += $size; } else $out['failed'][] = ['what' => $label, 'why' => dau_clip(dau_redact(trim($r['err'])), 150) ?: 'Docker refused'];
  };
  foreach ($containerNames as $n) {
    if (!isset($okCon[$n])) { $out['skipped'][] = ['what' => (string)$n, 'why' => 'no longer a leftover container']; continue; }
    $do('container ' . $n, $okCon[$n]['size'], function () use ($n) { return dau_docker(['rm', $n], 60); });
  }
  foreach ($volumeNames as $n) {
    if (!isset($okVol[$n])) { $out['skipped'][] = ['what' => (string)$n, 'why' => 'now in use or already removed']; continue; }
    $do('volume ' . $n, $okVol[$n]['size'], function () use ($n) { return dau_docker(['volume', 'rm', $n], 60); });   // never forced: Docker refuses a volume that is in use
  }
  foreach ($imageIds as $id) {
    if (!isset($okImg[$id])) { $out['skipped'][] = ['what' => dau_short_id($id), 'why' => 'now in use or already removed']; continue; }
    $label = $okImg[$id]['tags'] ? implode(', ', $okImg[$id]['tags']) : $okImg[$id]['short'];
    $do('image ' . $label, $okImg[$id]['size'], function () use ($id) { return dau_docker(['image', 'rm', $id], 120); });
  }
  $out['freed'] = $out['size_removed'];           // kept for older callers; it is a measured-before size, not space read back from the disk
  $out['freed_text'] = dau_bytes_text($out['size_removed']);
  return $out;
}

/* ---------------- plugin channel (stable / beta) and force update ---------------- */

const DAU_CHANNEL_BASE = 'https://raw.githubusercontent.com/Stiphr/Unraid-AutoUpdater/refs/heads/';

function dau_channel_url($ch) { return in_array($ch, ['stable', 'beta'], true) ? DAU_CHANNEL_BASE . ($ch === 'beta' ? 'beta' : 'main') . '/unraid-autoupdater.plg' : ''; }

/** Which branch this install follows: read from the installed plugin file's update address. */
function dau_channel_current() {
  $f = DAU_PLUGBOOT . '/unraid-autoupdater.plg';
  $t = is_file($f) ? (string)@file_get_contents($f, false, null, 0, 4096) : '';
  if (preg_match('#<!ENTITY\s+pluginURL\s+"([^"]+)"#', $t, $m)) return strpos($m[1], '/refs/heads/beta/') !== false ? 'beta' : 'stable';
  return preg_match('/b\d+$/', DAU_VERSION) ? 'beta' : 'stable';
}

/** Version offered on each branch (downloads only the plugin file and reads its version). */
function dau_channel_versions() {
  $out = [];
  foreach (['stable', 'beta'] as $ch) {
    $r = dau_run([dau_bin('curl'), '-fsSL', '--max-time', '20', '--max-filesize', '2000000', dau_channel_url($ch)], 30, null, 2000000);
    $x = $r['code'] === 0 ? dau_plg_parse($r['out']) : null;
    $v = $x ? (string)$x['version'] : '';
    $out[$ch] = preg_match('/^[A-Za-z0-9._+-]{1,40}$/', $v) ? $v : null;
  }
  return $out;
}

/**
 * Install the plugin file of a branch right now (switch branch, or reinstall the current one = force update).
 * Only the two fixed addresses above are ever used. Runs in the background job, holds the job lock and leaves a history line.
 */
function dau_channel_install($ch, $force = false) {
  $url = dau_channel_url($ch);
  if ($url === '') return ['ok' => false, 'error' => 'Unknown channel'];
  $cur = dau_channel_current();
  if ($cur === $ch && !$force) return ['ok' => false, 'error' => 'This server already follows that channel. Use Force update to reinstall it.'];
  $lock = dau_lock_try(); if (!$lock) return ['ok' => false, 'error' => 'Another job is running.'];
  $t0 = dau_now(); $what = $cur === $ch ? 'force update (' . $ch . ')' : $cur . ' -> ' . $ch;
  try {
    dau_log('info', 'plugin', DAU_NAME, 'channel', 'start', $what);
    $r = dau_run([dau_plugin_bin(), 'install', $url], 600);
    $ok = $r['code'] === 0 && !$r['timeout'];
    $err = $ok ? '' : dau_clip(dau_redact(trim(preg_replace('/[\r\n]+/', ' ', $r['err'] . ' ' . $r['out']))), 300);
    dau_history_add(['type' => 'plugin', 'name' => DAU_NAME, 'action' => 'channel', 'result' => $ok ? 'updated' : 'failed', 'started' => $t0, 'finished' => dau_now(), 'trigger' => 'manual',
      'message' => ucfirst($what), 'error' => $err], (int)(dau_load_config()['history']['max_entries'] ?? 200));
    dau_log($ok ? 'info' : 'error', 'plugin', DAU_NAME, 'channel', $ok ? 'ok' : 'failed', $what . ($err ? ': ' . $err : ''));
    return ['ok' => $ok, 'error' => $err];
  } finally { dau_lock_release($lock); }
}
