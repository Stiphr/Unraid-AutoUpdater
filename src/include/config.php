<?php
/* Unraid Swiss Manager - configuration: defaults, validation, v1 -> v2 migration, secrets. */

define('DAU_CFG_VERSION', 2);

const DAU_FREQS    = ['6h', '12h', 'daily', 'weekly', 'biweekly', 'monthly'];
const DAU_POLICIES = ['auto', 'notify', 'ignore'];

function dau_sched_spec() {
  return [
    'frequency' => ['enum', DAU_FREQS],
    'time'      => ['time'],
    'weekday'   => ['int', 0, 6],
    'monthday'  => ['int', 1, 28],
  ];
}

function dau_window_spec() {
  return ['enabled' => ['bool'], 'days' => ['days'], 'start' => ['time'], 'end' => ['time']];
}

function dau_window_default() {
  return ['enabled' => false, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '02:00', 'end' => '06:00'];
}

function dau_defaults() {
  $sched = ['frequency' => 'daily', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1];
  return [
    'version' => DAU_CFG_VERSION,
    /* Docker defaults. The v1 keys (enabled, frequency, time, weekday, monthday, notify, skip_stopped, delay) keep their meaning. */
    'global' => $sched + [
      'enabled'            => false,   // default schedule on/off; containers with their own schedule run regardless
      'notify'             => true,    // Unraid notifications for Docker (per-container toggle still applies)
      'skip_stopped'       => true,
      'delay'              => 10,      // seconds between container updates
      'update_delay_hours' => 0,       // default hold-back after an update is first seen (0 = immediate)
      'window'             => dau_window_default(),
      'retry'              => ['max' => 2, 'interval_min' => 30],
      'verify'             => ['enabled' => true, 'timeout' => 90],
      'cleanup'            => true,    // remove the one replaced image after a verified update (never forced)
      'disk_check'         => true,
      'min_free_gb'        => 5,
      'backup'             => [
        'enabled' => false, 'dest' => '', 'retention' => 3,
        'config' => true, 'image' => false, 'appdata' => false, 'required' => false,
      ],
    ],
    'containers' => [],
    'plugins' => $sched + [
      'check_enabled' => true,          // scheduled detection + notifications
      'auto_update'   => false,         // MASTER SWITCH: automatic installation is opt-in
      'default_policy' => 'notify',
      'delay_hours'   => 24,
      'delay'         => 30,            // seconds between plugin updates
      'window'        => dau_window_default(),
      'items'         => [],            // "name.plg" => {policy}
    ],
    'os' => $sched + [
      'check_enabled' => true,
      'time'          => '05:30',
      /* OS updates are NEVER installed unless mode is changed AND confirmed. Default: check and notify only. */
      'mode'          => 'notify',      // notify | install | install_reboot
      'confirmed'     => '',            // the install mode the person explicitly confirmed in the UI; a mode without a matching confirmation is ignored
      'delay_days'    => 7,             // wait this long after a release is first seen
      'stable_only'   => true,          // never auto-install -rc / beta / next releases
      'window'        => ['enabled' => true, 'days' => [0], 'start' => '03:00', 'end' => '05:00'],
      'pre'           => ['plugins' => true, 'docker' => false, 'require_plugins' => true, 'require_docker' => true, 'confirm' => false, 'notify' => true, 'scope' => 'auto'],   // pre-OS workflow, see workflow.php
    ],
    'discord' => [
      'enabled'    => false,
      'events'     => ['docker' => true, 'plugins' => true, 'os' => true],
      'types'      => ['success' => true, 'failed' => true, 'available' => true, 'summary' => true],
      'batching'   => 'combine',        // combine | separate
      'max_messages' => 5,
      'icon_url'   => '',               // optional https image for the webhook avatar; empty = bundled default
      'ui_url'     => '',               // address of this server's web GUI (for the approve / skip links); empty = http://<server name>
      'action_links' => true,           // add "Approve" / "Skip this version" links to update-available messages
    ],
    /* notifications are held back during quiet hours and sent afterwards; failures and reboot notices can still come through */
    'quiet' => ['enabled' => false, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '23:00', 'end' => '07:00', 'urgent' => true],
    /* appdata cleanup: folders to look in, and Safe mode = unused folders are moved to a hidden quarantine folder instead of being deleted */
    'appdata' => ['roots' => ['/mnt/user/appdata'], 'safe_mode' => true, 'ignore' => [], 'purge_days' => 0, 'report' => false],
    /* scheduled backups of appdata and of the boot drive: see backups.php */
    'backups' => dau_bk_defaults(),
    'ui' => ['theme' => 'auto', 'accent' => '#f15a2c', 'css' => '', 'widget' => true],
    'notify_unraid' => ['docker' => true, 'plugins' => false, 'os' => false],
    'history' => ['max_entries' => 200],
    'log'     => ['max_kb' => 1024],
  ];
}

function dau_container_spec() {
  return [
    'policy'      => ['enum', DAU_POLICIES],
    'notify'      => ['bool'],
    'critical'    => ['bool'],        // needs explicit approval before an automatic update
    'delay_hours' => ['int_or_null', 0, 720],
    'backup'      => ['bool'],
    'schedule'    => ['obj_or_null', dau_sched_spec()],
    'after'       => ['names'],       // update only after these containers (dependency order)
    'watch_major' => ['bool'],        // hold back updates that jump to a new major version (databases, for example)
  ];
}

/* ---------- schema-driven sanitising ---------- */

function dau_valid_time($t) { return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1; }

function dau_clean_value($spec, $v, $cur) {
  switch ($spec[0]) {
    case 'bool':  $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE); return $b === null ? $cur : $b;
    case 'enum':  return (is_string($v) && in_array($v, $spec[1], true)) ? $v : $cur;
    case 'time':  return dau_valid_time($v) ? $v : $cur;
    case 'int':   return is_numeric($v) ? max($spec[1], min($spec[2], (int)$v)) : $cur;
    case 'int_or_null':
      if ($v === null || $v === '' || $v === 'inherit') return null;
      return is_numeric($v) ? max($spec[1], min($spec[2], (int)$v)) : $cur;
    case 'days':
      if (!is_array($v)) return $cur;
      $d = array_values(array_unique(array_filter(array_map('intval', $v), function ($x) { return $x >= 0 && $x <= 6; })));
      sort($d);
      return $d ?: $cur;
    case 'path': // absolute path under /mnt, no traversal, no control characters
      if ($v === '' || $v === null) return '';
      return dau_valid_dest($v) ? rtrim($v, '/') : $cur;
    case 'paths':
      if (!is_array($v)) return $cur;
      $o = [];
      foreach ($v as $x) { $x = is_string($x) ? rtrim(trim($x), '/') : ''; if ($x !== '' && dau_valid_dest($x) && !in_array($x, $o, true) && count($o) < 5) $o[] = $x; }
      return $o ?: $cur;
    case 'folders':   // single folder names (never a path, never hidden)
      if (!is_array($v)) return $cur;
      $o = [];
      foreach ($v as $n) if (is_string($n) && preg_match('#^[^/\x00-\x1F\x7F.][^/\x00-\x1F\x7F]{0,254}$#', $n) && !in_array($n, $o, true) && count($o) < 100) $o[] = $n;
      return $o;
    case 'names':
      if (!is_array($v)) return $cur;
      $o = [];
      foreach ($v as $n) if (is_string($n) && dau_valid_name($n) && !in_array($n, $o, true) && count($o) < 20) $o[] = $n;
      return $o;
    case 'color': return (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? strtolower($v) : $cur;
    case 'css':
      if (!is_string($v)) return $cur;
      if (strlen($v) > 8000 || preg_match('/@import|expression\s*\(|javascript:|<\/?[a-z!]|\\0|behavior\s*:|-moz-binding/i', $v)) return $cur;
      return $v;
    case 'url':
      if ($v === '' || $v === null) return '';
      return (is_string($v) && strlen($v) <= 200 && preg_match('#^https?://[A-Za-z0-9._-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~%/+-]*)?$#', $v)) ? rtrim($v, '/') : $cur;
    case 'icon':  return ($v === '' || $v === null) ? '' : (dau_icon_url_valid($v) ? $v : $cur);
    case 'obj':   return is_array($v) ? dau_apply_spec(is_array($cur) ? $cur : [], $v, $spec[1]) : $cur;
    case 'obj_or_null':
      if ($v === null) return null;
      return is_array($v) ? dau_apply_spec(is_array($cur) ? $cur : dau_spec_defaults($spec[1]), $v, $spec[1]) : $cur;
  }
  return $cur;
}

function dau_spec_defaults($spec) {
  $o = ['frequency' => 'daily', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1];
  return array_intersect_key($o, $spec);
}

/** Apply $in over $cur, keeping only keys present in $spec. Unknown keys are dropped, bad values keep the old value. */
function dau_apply_spec(array $cur, array $in, array $spec) {
  foreach ($spec as $k => $s) {
    if (!array_key_exists($k, $in)) continue;
    $cur[$k] = dau_clean_value($s, $in[$k], $cur[$k] ?? null);
  }
  return $cur;
}

function dau_valid_dest($p) {
  if (!is_string($p) || $p === '' || strlen($p) > 200) return false;
  if (preg_match('/[\x00-\x1F\x7F]/', $p) || dau_has($p, '..')) return false;
  return preg_match('#^/mnt/[A-Za-z0-9._ /+-]+$#', $p) === 1;
}

function dau_global_spec() {
  return dau_sched_spec() + [
    'enabled' => ['bool'], 'notify' => ['bool'], 'skip_stopped' => ['bool'],
    'delay' => ['int', 0, 600], 'update_delay_hours' => ['int', 0, 720],
    'window' => ['obj', dau_window_spec()],
    'retry'  => ['obj', ['max' => ['int', 0, 5], 'interval_min' => ['int', 5, 1440]]],
    'verify' => ['obj', ['enabled' => ['bool'], 'timeout' => ['int', 10, 900]]],
    'cleanup' => ['bool'], 'disk_check' => ['bool'], 'min_free_gb' => ['int', 0, 4096],
    'backup' => ['obj', [
      'enabled' => ['bool'], 'dest' => ['path'], 'retention' => ['int', 1, 20],
      'config' => ['bool'], 'image' => ['bool'], 'appdata' => ['bool'], 'required' => ['bool'],
    ]],
  ];
}

function dau_plugins_spec() {
  return dau_sched_spec() + [
    'check_enabled' => ['bool'], 'auto_update' => ['bool'], 'default_policy' => ['enum', DAU_POLICIES],
    'delay_hours' => ['int', 0, 720], 'delay' => ['int', 0, 600],
    'window' => ['obj', dau_window_spec()],
  ];
}

function dau_os_spec() {
  return dau_sched_spec() + [
    'check_enabled' => ['bool'], 'mode' => ['enum', ['notify', 'install', 'install_reboot']], 'confirmed' => ['enum', ['', 'install', 'install_reboot']],
    'delay_days' => ['int', 0, 90], 'stable_only' => ['bool'], 'window' => ['obj', dau_window_spec()],
    'pre' => ['obj', ['plugins' => ['bool'], 'docker' => ['bool'], 'require_plugins' => ['bool'], 'require_docker' => ['bool'], 'confirm' => ['bool'], 'notify' => ['bool'], 'scope' => ['enum', ['auto', 'all']]]],
  ];
}

/** https only, no credentials, no whitespace or quotes: used for the Discord avatar. */
function dau_icon_url_valid($u) {
  return is_string($u) && strlen($u) <= 300 && preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/[A-Za-z0-9._~%/+-]*(\?[A-Za-z0-9._~%=&+-]*)?$#', $u) === 1;
}

function dau_discord_spec() {
  return [
    'enabled' => ['bool'],
    'events'  => ['obj', ['docker' => ['bool'], 'plugins' => ['bool'], 'os' => ['bool']]],
    'types'   => ['obj', ['success' => ['bool'], 'failed' => ['bool'], 'available' => ['bool'], 'summary' => ['bool']]],
    'batching' => ['enum', ['combine', 'separate']],
    'max_messages' => ['int', 1, 20],
    'icon_url' => ['icon'], 'ui_url' => ['url'], 'action_links' => ['bool'],
  ];
}

/** Clean a user-supplied configuration fragment into a full, valid configuration. Never throws. */
function dau_sanitize_config(array $in, array $cur) {
  if (isset($in['global']) && is_array($in['global'])) $cur['global'] = dau_apply_spec($cur['global'], $in['global'], dau_global_spec());
  if (isset($in['plugins']) && is_array($in['plugins'])) $cur['plugins'] = dau_apply_spec($cur['plugins'], $in['plugins'], dau_plugins_spec());
  if (isset($in['os']) && is_array($in['os'])) {
    $cur['os'] = dau_apply_spec($cur['os'], $in['os'], dau_os_spec());
    /* An install mode only counts when the person confirmed exactly that mode. Anything else falls back to notify-only. */
    if ($cur['os']['mode'] !== 'notify' && $cur['os']['confirmed'] !== $cur['os']['mode']) $cur['os']['mode'] = 'notify';
    if ($cur['os']['mode'] === 'notify') $cur['os']['confirmed'] = '';
  }
  if (isset($in['discord']) && is_array($in['discord'])) $cur['discord'] = dau_apply_spec($cur['discord'], $in['discord'], dau_discord_spec());
  if (isset($in['notify_unraid']) && is_array($in['notify_unraid'])) {
    $cur['notify_unraid'] = dau_apply_spec($cur['notify_unraid'], $in['notify_unraid'], ['docker' => ['bool'], 'plugins' => ['bool'], 'os' => ['bool']]);
  }
  if (isset($in['quiet']) && is_array($in['quiet'])) $cur['quiet'] = dau_apply_spec($cur['quiet'], $in['quiet'], dau_window_spec() + ['urgent' => ['bool']]);
  if (isset($in['appdata']) && is_array($in['appdata'])) $cur['appdata'] = dau_apply_spec($cur['appdata'], $in['appdata'], ['roots' => ['paths'], 'safe_mode' => ['bool'], 'ignore' => ['folders'], 'purge_days' => ['int', 0, 365], 'report' => ['bool']]);
  if (isset($in['backups']) && is_array($in['backups'])) $cur['backups'] = dau_apply_spec($cur['backups'], $in['backups'], dau_bk_spec());
  if (isset($in['ui']) && is_array($in['ui'])) $cur['ui'] = dau_apply_spec($cur['ui'], $in['ui'], ['theme' => ['enum', ['auto', 'dark', 'light', 'midnight']], 'accent' => ['color'], 'css' => ['css'], 'widget' => ['bool']]);
  if (isset($in['history']) && is_array($in['history'])) $cur['history'] = dau_apply_spec($cur['history'], $in['history'], ['max_entries' => ['int', 20, 1000]]);
  if (isset($in['log']) && is_array($in['log'])) $cur['log'] = dau_apply_spec($cur['log'], $in['log'], ['max_kb' => ['int', 64, 8192]]);
  if (isset($in['containers']) && is_array($in['containers'])) {
    $n = 0;
    foreach ($in['containers'] as $name => $c) {
      if (!dau_valid_name((string)$name) || !is_array($c) || ++$n > 1000) continue;
      $base = is_array($cur['containers'][$name] ?? null) ? $cur['containers'][$name] : ['policy' => 'ignore'];
      $cur['containers'][$name] = dau_apply_spec($base, $c, dau_container_spec());
    }
  }
  if (isset($in['plugin_items']) && is_array($in['plugin_items'])) {
    foreach ($in['plugin_items'] as $f => $c) {
      if (!dau_valid_plg((string)$f) || !is_array($c) || $f === DAU_SELF_PLG) continue;
      $cur['plugins']['items'][$f] = dau_apply_spec(is_array($cur['plugins']['items'][$f] ?? null) ? $cur['plugins']['items'][$f] : [], $c, ['policy' => ['enum', DAU_POLICIES]]);
    }
  }
  return $cur;
}

/** A settings file made by Export: per-plugin policies are stored under plugins.items there. */
function dau_import_config(array $in) {
  if (is_array($in['plugins']['items'] ?? null)) $in['plugin_items'] = $in['plugins']['items'];
  return dau_sanitize_config($in, dau_defaults());
}

/* ---------- v1 -> v2 migration ---------- */

/**
 * v1 stored {global:{...,check_only,prune}, containers:{name:{auto,notify,schedule}}}.
 * Mapping: auto:true -> policy auto (policy notify when v1 "notify only" was on); auto:false or absent -> ignore.
 * Schedules, notify toggles and every global setting carry over unchanged.
 */
function dau_migrate_v1(array $old) {
  $cfg = dau_defaults();
  $g = is_array($old['global'] ?? null) ? $old['global'] : [];
  $cfg['global'] = dau_apply_spec($cfg['global'], $g, dau_global_spec());
  $checkOnly = !empty($g['check_only']);
  foreach ((is_array($old['containers'] ?? null) ? $old['containers'] : []) as $name => $c) {
    if (!dau_valid_name((string)$name) || !is_array($c)) continue;
    $n = ['policy' => !empty($c['auto']) ? ($checkOnly ? 'notify' : 'auto') : 'ignore'];
    if (array_key_exists('notify', $c)) $n['notify'] = (bool)$c['notify'];
    if (isset($c['schedule']) && is_array($c['schedule'])) $n['schedule'] = $c['schedule'];
    $cfg['containers'][$name] = dau_apply_spec(['policy' => 'ignore'], $n, dau_container_spec());
  }
  return $cfg;
}

/* ---------- load / save ---------- */

/**
 * Load the configuration. A damaged file never crashes a job and never enables anything:
 * it is kept as config.corrupt-<time>.json and the safe defaults (everything off) are used.
 */
function dau_load_config(&$status = null) {
  $status = 'ok';
  $def = dau_defaults();
  if (!is_file(DAU_CFG)) return $def;
  $raw = @file_get_contents(DAU_CFG);
  $c = json_decode((string)$raw, true);
  if (!is_array($c)) {
    dau_keep_damaged(DAU_CFG, (string)$raw, 'config');
    $status = 'corrupt';
    return $def;
  }
  if ((int)($c['version'] ?? 1) < DAU_CFG_VERSION) {
    @copy(DAU_CFG, DAU_DIR . '/config.v1.backup.json');
    $c = dau_migrate_v1($c);
    dau_save_config($c);
    dau_log('info', 'config', '-', 'migrate', 'ok', 'configuration migrated from v1 to v' . DAU_CFG_VERSION . ' (backup: config.v1.backup.json)');
    $status = 'migrated';
  }
  $cfg = dau_sanitize_config($c, $def);
  /* sanitize ignores unknown top-level keys; carry the plugin per-item map through explicitly */
  if (is_array($c['plugins']['items'] ?? null)) {
    foreach ($c['plugins']['items'] as $f => $v) {
      if (dau_valid_plg((string)$f) && $f !== DAU_SELF_PLG && is_array($v)) $cfg['plugins']['items'][$f] = dau_apply_spec([], $v, ['policy' => ['enum', DAU_POLICIES]]);
    }
  }
  return $cfg;
}

function dau_save_config(array $cfg) {
  $out = $cfg;
  $out['version'] = DAU_CFG_VERSION;
  $out['containers'] = (object)($cfg['containers'] ?? []);
  $out['plugins']['items'] = (object)($cfg['plugins']['items'] ?? []);
  return dau_write_json(DAU_CFG, $out);
}

/* ---------- per-component effective settings ---------- */

function dau_container_cfg(array $cfg, $name) {
  $c = is_array($cfg['containers'][$name] ?? null) ? $cfg['containers'][$name] : [];
  return $c + ['policy' => 'ignore', 'notify' => true, 'critical' => false, 'delay_hours' => null, 'backup' => false, 'schedule' => null, 'after' => [], 'watch_major' => false];
}

function dau_container_delay_hours(array $cfg, $name) {
  $c = dau_container_cfg($cfg, $name);
  return $c['delay_hours'] === null ? (int)$cfg['global']['update_delay_hours'] : (int)$c['delay_hours'];
}

function dau_plugin_policy(array $cfg, $file) {
  $p = $cfg['plugins']['items'][$file]['policy'] ?? null;
  return in_array($p, DAU_POLICIES, true) ? $p : $cfg['plugins']['default_policy'];
}

/* ---------- secrets (webhook URL) ---------- */

/**
 * The webhook URL lives in its own file, is never returned by the API and never written to a log.
 * The flash drive is FAT-formatted, so file permissions cannot protect it; /boot is not web-accessible
 * and is only exposed if the user shares it. See the README for the exact limitation.
 */
function dau_secret_get($key) {
  $s = dau_read_json(DAU_SECRETS, []);
  return is_string($s[$key] ?? null) ? $s[$key] : '';
}

function dau_secret_set($key, $value) {
  $s = dau_read_json(DAU_SECRETS, []);
  if ($value === '' || $value === null) unset($s[$key]); else $s[$key] = (string)$value;
  return dau_write_json(DAU_SECRETS, $s, true);
}

function dau_webhook_valid($u) {
  return is_string($u) && strlen($u) <= 300
      && preg_match('#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/(?:v\d+/)?webhooks/\d{15,22}/[A-Za-z0-9._-]{20,100}$#', $u) === 1;
}

/** Config exported for backup: everything except secrets. */
function dau_export_config() {
  $cfg = dau_load_config();
  $cfg['containers'] = (object)$cfg['containers'];
  $cfg['plugins']['items'] = (object)$cfg['plugins']['items'];
  return ['exported_at' => dau_ts_iso(dau_now()), 'plugin_version' => DAU_VERSION, 'secrets_included' => false, 'config' => $cfg];
}
