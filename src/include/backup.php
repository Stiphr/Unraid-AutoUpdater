<?php
/*
 * Unraid Swiss Manager - optional pre-update backups.
 * Three DIFFERENT things, never conflated:
 *   config  = the container's Unraid template (my-NAME.xml) + `docker inspect` JSON. Small, enough to recreate the container.
 *   image   = `docker save` of the image currently in use (lets you go back to the exact old version offline). Large.
 *   appdata = tar of the container's bind-mounted host paths under /mnt. NOT application-consistent: the container
 *             is not stopped for it, so databases may be captured mid-write. Use a proper backup plugin for those.
 * The flash drive is FAT: file permissions cannot be enforced there, which is why no secrets are ever put in a backup.
 */

function dau_backup_dir_for($dest, $name) { return rtrim($dest, '/') . '/' . $name; }

function dau_backup_free($dest) { $f = @disk_free_space($dest); return $f === false ? null : (float)$f; }

/** @return array ['ok'=>bool,'error'=>string,'files'=>[],'kinds'=>[...],'skipped'=>[reason,...]] */
function dau_backup_run(array $cfg, $name, array $inspect) {
  $b = $cfg['global']['backup'];
  $res = ['ok' => false, 'error' => '', 'files' => [], 'kinds' => [], 'skipped' => []];
  if (!dau_valid_name($name)) { $res['error'] = 'invalid container name'; return $res; }
  if (!dau_valid_dest($b['dest'] ?? '')) { $res['error'] = 'backup destination is not set to a valid path under /mnt'; return $res; }
  $dir = dau_backup_dir_for($b['dest'], $name);
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { $res['error'] = "cannot create $dir"; return $res; }
  $stamp = date('Ymd-His', dau_now());
  $free = dau_backup_free($dir);

  $need = function ($bytes, $what) use ($free, &$res) {
    if ($free !== null && $bytes * 1.2 > $free) { $res['skipped'][] = "$what: not enough free space at the destination"; return false; }
    return true;
  };

  if (!empty($b['config'])) {
    $f = "$dir/$stamp-config.json";
    $tpl = DAU_TEMPLATES . "/my-$name.xml";
    $payload = ['container' => $name, 'time' => dau_ts_iso(dau_now()), 'template_xml' => is_file($tpl) ? (string)@file_get_contents($tpl) : null, 'inspect' => $inspect];
    // environment variables can hold passwords: strip values from the inspect copy; the template keeps what the user configured
    if (isset($payload['inspect']['Config']['Env'])) $payload['inspect']['Config']['Env'] = array_map(function ($e) { return preg_replace('/=.*/s', '=<omitted>', $e); }, $payload['inspect']['Config']['Env']);
    if (dau_write_json($f, $payload, true)) { $res['files'][] = $f; $res['kinds'][] = 'config'; } else $res['skipped'][] = 'config: write failed';
  }
  if (!empty($b['image'])) {
    $img = (string)($inspect['Image'] ?? '');
    $r = dau_docker(['image', 'inspect', '--format', '{{.Size}}', $img], 30);
    $size = (float)trim($r['out']);
    if ($need($size, 'image') && $img !== '') {
      $f = "$dir/$stamp-image.tar";
      $tmp = "$dir/.$stamp-image.tar.partial";
      $s = dau_docker(['save', '-o', $tmp, $img], 1800);
      if ($s['code'] === 0 && !$s['timeout'] && is_file($tmp) && @rename($tmp, $f)) { $res['files'][] = $f; $res['kinds'][] = 'image'; }
      else { @unlink($tmp); $res['skipped'][] = 'image: docker save failed'; }
    }
  }
  if (!empty($b['appdata'])) {
    $paths = [];
    foreach (($inspect['Mounts'] ?? []) as $m) {
      $src = (string)($m['Source'] ?? '');
      if (($m['Type'] ?? '') === 'bind' && preg_match('#^/mnt/[^\x00-\x1F]+$#', $src) && !dau_has($src, '..') && strpos($src . '/', rtrim($b['dest'], '/') . '/') !== 0 && is_dir($src)) $paths[] = $src;
    }
    if (!$paths) $res['skipped'][] = 'appdata: this container has no host folders under /mnt';
    else {
      $size = 0.0;
      foreach ($paths as $p) { $r = dau_run([dau_bin('du'), '-sb', $p], 600); $size += (float)preg_replace('/\s.*/s', '', trim($r['out'])); }
      if ($need($size, 'appdata')) {
        $f = "$dir/$stamp-appdata.tar.gz"; $tmp = "$dir/.$stamp-appdata.tar.gz.partial";   // renamed only when complete: an interrupted tar never looks like a backup
        $t = dau_run(array_merge([dau_bin('tar'), '-czf', $tmp, '--'], $paths), 3600);
        if (($t['code'] === 0 || $t['code'] === 1) && !$t['timeout'] && is_file($tmp) && @rename($tmp, $f)) { $res['files'][] = $f; $res['kinds'][] = 'appdata'; }   // 1 = files changed while reading (expected, container is running)
        else { @unlink($tmp); $res['skipped'][] = 'appdata: tar failed'; }
      }
    }
  }
  $wanted = array_filter([!empty($b['config']) ? 'config' : null, !empty($b['image']) ? 'image' : null, !empty($b['appdata']) ? 'appdata' : null]);
  $missing = array_diff($wanted, $res['kinds']);
  $res['ok'] = !$missing && $res['kinds'];
  if ($res['ok']) dau_backup_prune($dir, (int)$b['retention']);   // only a complete new set may push an older one out
  if (!$res['ok']) $res['error'] = $res['skipped'] ? implode('; ', $res['skipped']) : 'no backup type selected';
  return $res;
}

/** Keep the newest $keep backup sets (a set = all files sharing one timestamp prefix). */
function dau_backup_prune($dir, $keep) {
  if ($keep < 1) return;
  $sets = [];
  foreach (glob($dir . '/*') ?: [] as $f) if (preg_match('#/(\d{8}-\d{6})-(config\.json|image\.tar|appdata\.tar\.gz)$#', $f, $m)) $sets[$m[1]][] = $f;
  krsort($sets);
  foreach (array_slice($sets, $keep, null, true) as $files) foreach ($files as $f) @unlink($f);
}
