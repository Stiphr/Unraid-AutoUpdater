<?php
/*
 * Unraid AutoUpdater - appdata cleanup: orphaned appdata folders (quarantine first), and saved templates of deleted containers.
 * Never deletes anything it was not asked to: every action is checked again against a fresh scan, paths come from the scan and never from
 * the browser, and a folder that any container (even a stopped one) still maps is never touched. When Docker cannot be asked, nothing is offered.
 */

define('DAU_QDIR_NAME', '.unraid-autoupdater-quarantine');
define('DAU_QUARANTINE_DB', DAU_DIR . '/quarantine.json');
define('DAU_TPL_BACKUPS', DAU_DIR . '/template-backups');

function dau_appdata_roots(array $cfg = null) {
  $cfg = $cfg ?: dau_load_config();
  $roots = $cfg['appdata']['roots'] ?? ['/mnt/user/appdata'];
  $out = [];
  foreach ($roots as $r) {
    if (!dau_valid_dest($r)) continue;
    $rp = @realpath($r);
    if ($rp === false || !is_dir($rp) || is_link($r)) continue;
    if (count(array_filter(explode('/', trim($rp, '/')))) < 2) continue;   // never a share root's parent like /mnt/user
    $out[] = rtrim($r, '/');
  }
  return array_values(array_unique($out));
}

/** The part of a /mnt path after the disk / pool / "user" name, so /mnt/user/appdata/x and /mnt/cache/appdata/x compare equal. */
function dau_path_tail($p) {
  $p = '/' . trim((string)$p, '/');
  if (preg_match('#^/mnt/[^/]+/(.+)$#', $p, $m)) return $m[1];
  return ltrim($p, '/');
}

function dau_path_in($child, $parent) { return $child === $parent || strpos($child, rtrim($parent, '/') . '/') === 0; }

/** Every container (running or not) with the host folders it maps. null = Docker not available. */
function dau_appdata_mounts() {
  $r = dau_docker(['ps', '-aq', '--no-trunc'], 30);
  if ($r['code'] !== 0) return null;
  $ids = array_values(array_filter(preg_split('/\s+/', trim($r['out']))));
  if (!$ids) return [];
  $r = dau_docker(array_merge(['inspect'], $ids), 90);
  $data = json_decode($r['out'], true);
  if (!is_array($data)) return null;
  $out = [];
  foreach ($data as $c) {
    $src = [];
    foreach ($c['Mounts'] ?? [] as $m) if (($m['Type'] ?? 'bind') === 'bind' && !empty($m['Source'])) $src[] = (string)$m['Source'];
    foreach ($c['HostConfig']['Binds'] ?? [] as $b) { $p = explode(':', (string)$b); if (isset($p[0]) && $p[0] !== '' && $p[0][0] === '/') $src[] = $p[0]; }
    $out[] = ['name' => ltrim((string)($c['Name'] ?? ''), '/'), 'state' => (string)($c['State']['Status'] ?? ''), 'sources' => array_values(array_unique($src))];
  }
  return $out;
}

/** Saved templates (Unraid keeps one per container, also after the container was removed): name => ['file','repo','paths'=>[...]] */
function dau_templates_read() {
  $out = [];
  foreach (glob(DAU_TEMPLATES . '/my-*.xml') ?: [] as $f) {
    $n = substr(basename($f), 3, -4);
    if (!dau_valid_name($n)) continue;
    $x = @simplexml_load_file($f, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    if (!$x) continue;
    $paths = [];
    foreach ($x->Config ?? [] as $c) if (strtolower((string)$c['Type']) === 'path') { $v = trim((string)$c); if ($v !== '' && $v[0] === '/') $paths[] = $v; }
    $out[$n] = ['file' => $f, 'name' => (string)($x->Name ?? $n), 'repo' => (string)($x->Repository ?? ''), 'paths' => $paths, 'size' => (int)@filesize($f), 'mtime' => (int)@filemtime($f)];
  }
  return $out;
}

function dau_dir_size($p) {
  $r = dau_run([dau_bin('du'), '-sb', '--', $p], 120);
  return preg_match('/^(\d+)\s/', $r['out'], $m) ? (int)$m[1] : 0;
}

/**
 * Orphaned appdata folders. A folder is an orphan when no container (any state) maps it or something inside it.
 * Mounts that cover the whole appdata share (e.g. /mnt/user) are shown but never count as ownership.
 */
function dau_appdata_scan(array $cfg = null) {
  $cfg = $cfg ?: dau_load_config();
  $roots = dau_appdata_roots($cfg);
  if (!$roots) return ['ok' => false, 'error' => 'No appdata folder is set up, or it does not exist. Add it under Settings > Cleanup.'];
  $mounts = dau_appdata_mounts();
  if ($mounts === null) return ['ok' => false, 'error' => 'The Docker service is not running, so it cannot be checked which folders are still in use. Nothing is offered for cleanup.'];
  $tpls = dau_templates_read();
  $now = dau_now(); $orph = []; $busy = []; $prot = []; $broad = []; $ignored = [];
  $ign = array_flip($cfg['appdata']['ignore'] ?? []);
  $qroots = [];
  foreach ($roots as $ri => $root) {
    $rreal = realpath($root); $rdev = @stat($rreal)['dev'] ?? 0; $rtail = dau_path_tail($rreal);
    $qroots[] = $root . '/' . DAU_QDIR_NAME;
    $entries = @scandir($root) ?: [];
    foreach ($entries as $e) {
      if ($e === '.' || $e === '..' || $e[0] === '.') continue;
      if (preg_match('/[\x00-\x1F\x7F]/', $e)) continue;
      if (isset($ign[$e])) { $ignored[] = $e; continue; }
      $path = $root . '/' . $e;
      $row = ['id' => $ri . ':' . $e, 'root' => $ri, 'name' => $e, 'path' => $path, 'size' => 0, 'age' => 0, 'by' => [], 'tpl' => [], 'why' => ''];
      if (is_link($path)) { $row['why'] = 'symbolic link'; $prot[] = $row; continue; }
      if (!is_dir($path)) continue;
      $st = @stat($path);
      if ($st && $rdev && $st['dev'] !== $rdev) { $row['why'] = 'separate mount point'; $prot[] = $row; continue; }
      $real = realpath($path); $tail = dau_path_tail($real ?: $path);
      foreach ($mounts as $c) foreach ($c['sources'] as $s) {
        $sreal = @realpath($s) ?: $s; $stail = dau_path_tail($sreal);
        $covers = dau_path_in($sreal, $real) || dau_path_in($stail, $tail) || dau_path_in($s, $path);   // maps this folder or something in it
        if ($covers) { $row['by'][] = $c['name'] . ($c['state'] !== 'running' ? ' (' . $c['state'] . ')' : ''); break; }
        if (dau_path_in($real, $sreal) || dau_path_in($tail, $stail)) $broad[$c['name']] = true;     // maps a parent (like /mnt/user): shown, never ownership
      }
      foreach ($tpls as $tn => $t) foreach ($t['paths'] as $p) if (dau_path_in(dau_path_tail($p), $tail) || dau_path_in($p, $path)) { $row['tpl'][] = $tn; break; }
      $row['recent'] = false;
      $row['by'] = array_values(array_unique($row['by'])); $row['tpl'] = array_values(array_unique($row['tpl']));
      $row['age'] = $st ? max(0, $now - (int)$st['mtime']) : 0;
      if ($row['by']) { $busy[] = $row; continue; }
      $row['size'] = dau_dir_size($path);
      $row['recent'] = $row['age'] < 30 * 86400;
      $orph[] = $row;
    }
  }
  usort($orph, function ($a, $b) { return $b['size'] <=> $a['size']; });
  $total = 0; foreach ($orph as $o) $total += $o['size'];
  return ['ok' => true, 'roots' => $roots, 'orphans' => $orph, 'in_use' => count($busy), 'protected' => $prot, 'broad' => array_keys($broad), 'ignored' => array_values(array_unique($ignored)),
          'total' => $total, 'total_text' => dau_bytes_text($total), 'safe_mode' => !empty($cfg['appdata']['safe_mode'])];
}

function dau_quarantine_load() { $q = dau_read_json(DAU_QUARANTINE_DB, []); return is_array($q) ? $q : []; }
function dau_quarantine_save(array $q) { dau_write_json(DAU_QUARANTINE_DB, array_slice(array_values($q), -500)); }

/**
 * Move the chosen orphans to quarantine (default) or delete them for good. $ids come from the browser and are only used to pick
 * rows from a fresh scan. $dry = preview only. Deleting needs Safe Mode off AND $confirmDelete.
 */
function dau_appdata_run(array $ids, $mode, $dry, $confirmDelete) {
  $cfg = dau_load_config();
  $scan = dau_appdata_scan($cfg);
  if (!$scan['ok']) return $scan;
  $mode = $mode === 'delete' ? 'delete' : 'quarantine';
  if ($mode === 'delete' && (!empty($cfg['appdata']['safe_mode']) || !$confirmDelete)) return ['ok' => false, 'error' => 'Permanent delete needs Safe mode to be turned off and an explicit confirmation. Use quarantine instead.'];
  $by = []; foreach ($scan['orphans'] as $o) $by[$o['id']] = $o;
  $plan = []; $skipped = [];
  foreach ($ids as $id) {
    if (!is_string($id) || !isset($by[$id])) { $skipped[] = ['what' => (string)$id, 'why' => 'no longer an unused folder']; continue; }
    $plan[] = $by[$id];
  }
  $out = ['ok' => true, 'dry' => (bool)$dry, 'mode' => $mode, 'done' => [], 'failed' => [], 'skipped' => $skipped, 'freed' => 0, 'would' => []];
  $q = dau_quarantine_load(); $roots = $scan['roots'];
  foreach ($plan as $o) {
    $root = $roots[$o['root']];
    $real = @realpath($o['path']);
    $rroot = @realpath($root);
    if ($real === false || $rroot === false || dirname($real) !== $rroot || is_link($o['path'])) { $out['failed'][] = ['what' => $o['name'], 'why' => 'the folder is not directly inside the appdata folder any more']; continue; }
    if ($mode === 'quarantine') {
      $qd = $root . '/' . DAU_QDIR_NAME; $stamp = gmdate('Ymd-His', dau_now());
      $dest = $qd . '/' . $o['name'] . '__' . $stamp;
      if ($dry) { $out['would'][] = ['name' => $o['name'], 'to' => $dest, 'size' => $o['size']]; continue; }
      if (!is_dir($qd) && !@mkdir($qd, 0700, true)) { $out['failed'][] = ['what' => $o['name'], 'why' => 'the quarantine folder could not be created']; continue; }
      if (file_exists($dest)) { $out['failed'][] = ['what' => $o['name'], 'why' => 'quarantine name already exists']; continue; }
      if (@rename($o['path'], $dest)) {
        $q[] = ['id' => substr(hash('sha256', $dest . $stamp), 0, 12), 'name' => $o['name'], 'original' => $o['path'], 'path' => $dest, 'root' => $root, 'size' => $o['size'], 'at' => dau_now()];
        $out['done'][] = $o['name']; 
      } else $out['failed'][] = ['what' => $o['name'], 'why' => 'could not be moved'];
    } else {
      if ($dry) { $out['would'][] = ['name' => $o['name'], 'to' => null, 'size' => $o['size']]; continue; }
      $r = dau_run([dau_bin('rm'), '-rf', '--one-file-system', '--', $real], 600);
      if ($r['code'] === 0 && !file_exists($real)) { $out['done'][] = $o['name']; $out['freed'] += $o['size']; } else $out['failed'][] = ['what' => $o['name'], 'why' => dau_clip(dau_redact(trim($r['err'])), 150) ?: 'could not be removed'];
    }
  }
  if (!$dry) dau_quarantine_save($q);
  $out['freed_text'] = dau_bytes_text($out['freed']);
  return $out;
}

/** Quarantined folders, with whether they still exist and whether the original place is free. */
function dau_quarantine_list() {
  $q = dau_quarantine_load(); $roots = dau_appdata_roots(); $now = dau_now(); $rows = [];
  foreach ($q as $e) {
    $exists = is_dir($e['path']) && !is_link($e['path']);
    $rows[] = $e + ['exists' => $exists, 'free' => !file_exists($e['original']), 'age' => max(0, $now - (int)$e['at']), 'known_root' => in_array($e['root'], $roots, true)];
  }
  return ['ok' => true, 'rows' => array_reverse($rows)];
}

function dau_quarantine_act($id, $act, $suffix = false) {
  $q = dau_quarantine_load(); $i = null;
  foreach ($q as $k => $e) if (($e['id'] ?? '') === $id) $i = $k;
  if ($i === null) return ['ok' => false, 'error' => 'That entry is not in quarantine any more.'];
  $e = $q[$i]; $roots = dau_appdata_roots();
  if (!in_array($e['root'], $roots, true)) return ['ok' => false, 'error' => 'Its appdata folder is not set up any more.'];
  $qd = realpath($e['root'] . '/' . DAU_QDIR_NAME);
  $real = @realpath($e['path']);
  if (!$qd || !$real || dirname($real) !== $qd || is_link($e['path'])) { 
    if (!$real) { unset($q[$i]); dau_quarantine_save($q); return ['ok' => true, 'note' => 'The folder was already gone; the entry was removed.']; }
    return ['ok' => false, 'error' => 'The folder is not where it was put.'];
  }
  if ($act === 'purge') {
    $r = dau_run([dau_bin('rm'), '-rf', '--one-file-system', '--', $real], 600);
    if ($r['code'] !== 0 || file_exists($real)) return ['ok' => false, 'error' => dau_clip(dau_redact(trim($r['err'])), 150) ?: 'could not be removed'];
    unset($q[$i]); dau_quarantine_save($q); return ['ok' => true, 'freed' => (int)$e['size']];
  }
  if ($act === 'restore') {
    $dest = $e['original'];
    if (dirname($dest) !== $e['root'] || !dau_valid_dest($dest)) return ['ok' => false, 'error' => 'The original place is not valid.'];
    if (file_exists($dest) || is_link($dest)) {
      if (!$suffix) return ['ok' => false, 'conflict' => true, 'error' => 'There is already something at the original place. Nothing was overwritten.'];
      for ($n = 1; $n < 100 && (file_exists($dest . '-restored' . ($n > 1 ? $n : '')) || is_link($dest . '-restored' . ($n > 1 ? $n : ''))); $n++);
      $dest .= '-restored' . ($n > 1 ? $n : '');
    }
    if (!@rename($real, $dest)) return ['ok' => false, 'error' => 'could not be moved back'];
    unset($q[$i]); dau_quarantine_save($q); return ['ok' => true, 'to' => $dest];
  }
  return ['ok' => false, 'error' => 'Unknown action'];
}

/* ---------------- saved templates of containers that no longer exist ---------------- */

function dau_tpl_scan() {
  $cs = dau_containers();
  if ($cs === null) return ['ok' => false, 'error' => 'The Docker service is not running.'];
  $have = []; foreach ($cs as $c) $have[$c['name']] = true;
  $rows = [];
  foreach (dau_templates_read() as $n => $t) if (!isset($have[$n]) && !isset($have[$t['name']])) $rows[] = ['name' => $n, 'repo' => $t['repo'], 'mtime' => $t['mtime'], 'size' => $t['size']];
  $bk = [];
  foreach (glob(DAU_TPL_BACKUPS . '/*.xml') ?: [] as $f) if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]{0,127})__(\d{8}-\d{6})\.xml$/', basename($f), $m)) $bk[] = ['file' => basename($f), 'name' => $m[1], 'at' => (int)@filemtime($f), 'size' => (int)@filesize($f), 'free' => !is_file(DAU_TEMPLATES . '/my-' . $m[1] . '.xml')];
  usort($bk, function ($a, $b) { return $b['at'] <=> $a['at']; });
  return ['ok' => true, 'templates' => $rows, 'backups' => $bk];
}

/** archive = verified copy to the plugin's own folder, then the template is removed; restore = copy back if the name is free; drop = delete a backup. */
function dau_tpl_act($act, $name, $file = '') {
  if ($act === 'archive') {
    $scan = dau_tpl_scan(); if (!$scan['ok']) return $scan;
    $ok = false; foreach ($scan['templates'] as $t) if ($t['name'] === $name) $ok = true;
    if (!$ok) return ['ok' => false, 'error' => 'That template belongs to an installed container, or is gone.'];
    $src = DAU_TEMPLATES . "/my-$name.xml";
    if (!is_dir(DAU_TPL_BACKUPS) && !@mkdir(DAU_TPL_BACKUPS, 0700, true)) return ['ok' => false, 'error' => 'The backup folder could not be created.'];
    $dst = DAU_TPL_BACKUPS . '/' . $name . '__' . gmdate('Ymd-His', dau_now()) . '.xml';
    if (!@copy($src, $dst) || hash_file('sha256', $src) !== hash_file('sha256', $dst)) { @unlink($dst); return ['ok' => false, 'error' => 'The backup could not be verified, so the template was kept.']; }
    @chmod($dst, 0600);
    if (!@unlink($src)) return ['ok' => false, 'error' => 'Backed up, but the template could not be removed.'];
    return ['ok' => true, 'backup' => basename($dst)];
  }
  if (!is_string($file) || !preg_match('/^([A-Za-z0-9][A-Za-z0-9_.-]{0,127})__\d{8}-\d{6}\.xml$/', $file, $m)) return ['ok' => false, 'error' => 'Not a backup file.'];
  $bf = DAU_TPL_BACKUPS . '/' . $file;
  if (!is_file($bf) || is_link($bf)) return ['ok' => false, 'error' => 'That backup is gone.'];
  if ($act === 'restore') {
    $dst = DAU_TEMPLATES . '/my-' . $m[1] . '.xml';
    if (file_exists($dst)) return ['ok' => false, 'error' => 'A template with that name exists already. Nothing was overwritten.'];
    if (!@copy($bf, $dst)) return ['ok' => false, 'error' => 'could not be restored'];
    return ['ok' => true];
  }
  if ($act === 'drop') { @unlink($bf); return ['ok' => !is_file($bf)]; }
  return ['ok' => false, 'error' => 'Unknown action'];
}


/* ---------------- look inside a folder, ignore list, scheduled report, quarantine expiry ---------------- */

/** The biggest things directly inside an unused folder, so you can see what you are about to remove. */
function dau_appdata_peek($id) {
  if (!is_string($id) || !preg_match('/^(\d):([^\/\x00-\x1F\x7F]{1,255})$/', $id, $m) || $m[2] === '.' || $m[2] === '..') return ['ok' => false, 'error' => 'Bad folder'];
  $roots = dau_appdata_roots(); $root = $roots[(int)$m[1]] ?? null;
  if ($root === null) return ['ok' => false, 'error' => 'Unknown appdata folder'];
  $path = $root . '/' . $m[2]; $real = @realpath($path); $rr = @realpath($root);
  if ($real === false || $rr === false || dirname($real) !== $rr || is_link($path) || !is_dir($real)) return ['ok' => false, 'error' => 'The folder is not directly inside the appdata folder'];
  $items = []; $n = 0;
  foreach (@scandir($real) ?: [] as $e) {
    if ($e === '.' || $e === '..') continue;
    $n++; if (count($items) >= 200) continue;
    $p = $real . '/' . $e; $link = is_link($p); $dir = !$link && is_dir($p);
    $items[] = ['name' => $e, 'dir' => $dir, 'link' => $link, 'size' => $link ? 0 : ($dir ? 0 : (int)@filesize($p))];
  }
  /* folder sizes only for the biggest-looking dozen, to keep this quick */
  foreach ($items as $i => $it) if ($it['dir'] && $i < 25) $items[$i]['size'] = dau_dir_size($real . '/' . $it['name']);
  usort($items, function ($a, $b) { return $b['size'] <=> $a['size']; });
  return ['ok' => true, 'name' => $m[2], 'count' => $n, 'items' => array_slice($items, 0, 15), 'more' => max(0, $n - 15)];
}

/** Never offer this folder again (or offer it again). Only the folder's name is stored. */
function dau_appdata_ignore($name, $add) {
  if (!is_string($name) || !preg_match('#^[^/\x00-\x1F\x7F.][^/\x00-\x1F\x7F]{0,254}$#', $name)) return ['ok' => false, 'error' => 'Bad folder name'];
  $cfg = dau_load_config(); $l = $cfg['appdata']['ignore'] ?? [];
  $l = array_values(array_diff($l, [$name])); if ($add) $l[] = $name;
  if (count($l) > 100) return ['ok' => false, 'error' => 'The ignore list is full (100).'];
  $cfg['appdata']['ignore'] = $l; dau_save_config($cfg);
  return ['ok' => true];
}

/** Quarantined folders older than appdata.purge_days are deleted (only when the person set that). Returns how many were removed. */
function dau_quarantine_expire(array $cfg) {
  $days = (int)($cfg['appdata']['purge_days'] ?? 0); if ($days < 1) return 0;
  $n = 0;
  foreach (dau_quarantine_load() as $e) {
    if (dau_now() - (int)($e['at'] ?? 0) < $days * 86400) continue;
    $r = dau_quarantine_act((string)($e['id'] ?? ''), 'purge');
    if (!empty($r['ok'])) { $n++; dau_log('info', 'cleanup', $e['name'] ?? '-', 'quarantine expired', 'ok', "older than $days days"); }
  }
  return $n;
}

/**
 * Counts for the tab badge and the report. Looks only (never removes). Returns the summary stored in state.
 * The scan is not repeated on every page view: the tab badge reads what the last scan stored.
 */
function dau_cleanup_summary($a = null, $d = null) {
  $cfg = dau_load_config(); $z = ['n' => 0, 'size' => 0];
  $sum = ['at' => dau_now(), 'appdata' => $z, 'images' => $z, 'volumes' => $z, 'containers' => $z, 'quarantine' => count(dau_quarantine_load())];
  $a = $a ?: dau_appdata_scan($cfg);
  if (!empty($a['ok'])) $sum['appdata'] = ['n' => count($a['orphans']), 'size' => $a['total']];
  $d = $d ?: dau_cleanup_scan();
  if (!empty($d['ok'])) {
    $sum['images'] = ['n' => count($d['images']), 'size' => array_sum(array_column($d['images'], 'size'))];
    $sum['containers'] = ['n' => count($d['containers']), 'size' => array_sum(array_column($d['containers'], 'size'))];
    $sum['volumes'] = ['n' => count($d['volumes'] ?? []), 'size' => array_sum(array_column($d['volumes'] ?? [], 'size'))];
  }
  return $sum;
}
