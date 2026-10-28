<?php
/*
 * Unraid Swiss Manager - core helpers.
 * Written for PHP 7.4+ (Unraid 6.9 and later): no match, str_contains, nullsafe or named arguments.
 *
 * Test seams (in-process only, never read from the environment or the web):
 *   $GLOBALS['DAU_EXEC_MOCK'](array $argv, $stdin)  -> result array or null to run for real
 *   $GLOBALS['DAU_HTTP_MOCK'](string $url, string $body) -> result array
 *   $GLOBALS['DAU_NOW'] (int) and $GLOBALS['DAU_SLEPT'] (int) replace the clock / sleeping
 */

if (!defined('DAU_LIB_LOADED')) {
define('DAU_LIB_LOADED', 1);

define('DAU_VERSION',   '@@VERSION@@');   // the one version shown everywhere (date based, e.g. 2026.10.30a; Unraid compares it as a string)
define('DAU_NAME',      'Unraid Swiss Manager');
define('DAU_ROOT',      '/usr/local/emhttp/plugins/unraid-swiss-manager');
define('DAU_DIR',       '/boot/config/plugins/unraid-swiss-manager');
define('DAU_CFG',       DAU_DIR . '/config.json');
define('DAU_SECRETS',   DAU_DIR . '/secrets.json');
define('DAU_STATE',     DAU_DIR . '/state.json');
define('DAU_HIST',      DAU_DIR . '/history.json');
define('DAU_CRON',      DAU_DIR . '/unraid-swiss-manager.cron');
define('DAU_RECOVERY',  DAU_DIR . '/plugin-recovery');
define('DAU_LOG',       '/var/log/unraid-swiss-manager.log');
define('DAU_LOCK',      '/var/run/unraid-swiss-manager.lock');
define('DAU_PROGRESS',  '/var/run/unraid-swiss-manager.progress');
define('DAU_STOPFLAG',  '/var/run/unraid-swiss-manager.stop');
define('DAU_PHP',       '/usr/bin/php');
define('DAU_UPDSTATUS', '/var/lib/docker/unraid-update-status.json');
define('DAU_DM',        '/usr/local/emhttp/plugins/dynamix.docker.manager');
define('DAU_PM',        '/usr/local/emhttp/plugins/dynamix.plugin.manager');
define('DAU_NOTIFY',    '/usr/local/emhttp/webGui/scripts/notify');
define('DAU_TEMPLATES', '/boot/config/plugins/dockerMan/templates-user');
define('DAU_PLUGLINKS', '/var/log/plugins');
define('DAU_PLUGTMP',   '/tmp/plugins');
define('DAU_PLUGBOOT',  '/boot/config/plugins');
define('DAU_OSVERFILE', '/etc/unraid-version');
define('DAU_VARINI',    '/var/local/emhttp/var.ini');
define('DAU_BOOT',      '/boot');
define('DAU_REBOOT',    '/sbin/reboot');
define('DAU_MOVERPID',  '/var/run/mover.pid');
define('DAU_SELF_PLG',  'unraid-swiss-manager.plg');
define('DAU_CATCHUP',   6 * 3600);      // a missed schedule still runs if it is at most this late
define('DAU_TICK_MIN',  5);             // dispatcher interval in minutes

$__ident = @parse_ini_file('/boot/config/ident.cfg');
if (is_array($__ident) && !empty($__ident['timezone'])) @date_default_timezone_set($__ident['timezone']);
unset($__ident);

/* ---------- small string helpers (PHP 7.4 has no str_contains & friends) ---------- */

function dau_starts($s, $p) { return $p === '' || strncmp($s, $p, strlen($p)) === 0; }
function dau_ends($s, $p)   { return $p === '' || substr($s, -strlen($p)) === $p; }
function dau_has($s, $p)    { return $p === '' || strpos($s, $p) !== false; }

/* ---------- clock ---------- */

function dau_now()    { return isset($GLOBALS['DAU_NOW']) ? (int)$GLOBALS['DAU_NOW'] : time(); }
function dau_sleep($s) {
  $s = max(0, (float)$s);
  if (isset($GLOBALS['DAU_NOW'])) { $GLOBALS['DAU_NOW'] += (int)ceil($s); $GLOBALS['DAU_SLEPT'] = ($GLOBALS['DAU_SLEPT'] ?? 0) + $s; return; }
  if ($s > 0) usleep((int)($s * 1000000));
}
function dau_ts_iso($ts) { return gmdate('Y-m-d\TH:i:s\Z', (int)$ts); }

/* ---------- JSON files ---------- */

function dau_read_json($file, $default) {
  if (!is_file($file)) return $default;
  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') return $default;
  $d = json_decode($raw, true);
  return is_array($d) ? $d : $default;
}

/** Atomic write. Skips the write when the content is unchanged (the flash drive has limited write cycles). */
function dau_write_json($file, $data, $private = false) {
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  if ($json === false) return false;
  if (is_file($file) && @file_get_contents($file) === $json) return true;
  @mkdir(dirname($file), 0755, true);
  $tmp = $file . '.tmp' . getmypid();
  if (@file_put_contents($tmp, $json) === false) return false;
  if ($private) @chmod($tmp, 0600);
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  return true;
}

/* ---------- running commands (no shell, argument arrays only) ---------- */

/**
 * Run a command without a shell. Returns ['code'=>int,'out'=>string,'err'=>string,'timeout'=>bool,'ran'=>bool].
 * Output is capped. The command is killed when the timeout expires.
 */
function dau_run(array $argv, $timeout = 60, $stdin = null, $maxBytes = 1048576) {
  if (isset($GLOBALS['DAU_EXEC_MOCK']) && is_callable($GLOBALS['DAU_EXEC_MOCK'])) {
    $r = call_user_func($GLOBALS['DAU_EXEC_MOCK'], $argv, $stdin);
    if (is_array($r)) return $r + ['code' => 0, 'out' => '', 'err' => '', 'timeout' => false, 'ran' => true];
  }
  $res = ['code' => -1, 'out' => '', 'err' => '', 'timeout' => false, 'ran' => false];
  $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $p = @proc_open($argv, $spec, $pipes);
  if (!is_resource($p)) { $res['err'] = 'could not start ' . basename((string)$argv[0]); return $res; }
  $res['ran'] = true;
  if ($stdin !== null) @fwrite($pipes[0], $stdin);
  @fclose($pipes[0]);
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  $deadline = microtime(true) + max(1, $timeout);
  $open = [1 => $pipes[1], 2 => $pipes[2]];
  while ($open) {
    $left = $deadline - microtime(true);
    if ($left <= 0) { $res['timeout'] = true; break; }
    $r = array_values($open); $w = null; $e = null;
    if (@stream_select($r, $w, $e, 0, (int)min(200000, $left * 1000000)) === false) break;
    foreach ($open as $k => $h) {
      $chunk = @fread($h, 65536);
      if ($chunk !== false && $chunk !== '') {
        $key = $k === 1 ? 'out' : 'err';
        if (strlen($res[$key]) < $maxBytes) $res[$key] .= $chunk;
      }
      if (feof($h)) { @fclose($h); unset($open[$k]); }
    }
  }
  if ($res['timeout']) {
    @proc_terminate($p, 15);
    usleep(300000);
    $st = @proc_get_status($p);
    if (!empty($st['running'])) @proc_terminate($p, 9);
  }
  foreach ($open as $h) @fclose($h);
  $code = @proc_close($p);
  $res['code'] = $res['timeout'] ? 124 : $code;
  return $res;
}

function dau_bin($name) {
  foreach (['/usr/bin', '/usr/local/bin', '/bin', '/usr/sbin', '/sbin', '/usr/local/sbin'] as $d) {
    if (is_file("$d/$name") && is_executable("$d/$name")) return "$d/$name";
  }
  return $name;
}

/* ---------- redaction ---------- */

/** Remove anything that looks like a secret from text that is logged, shown or sent. */
function dau_redact($s, array $extra = []) {
  $s = (string)$s;
  foreach ($extra as $x) if (is_string($x) && strlen($x) >= 6) $s = str_replace($x, '[redacted]', $s);
  $s = preg_replace('#https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/(?:v\d+/)?webhooks/\S+#i', '[discord-webhook]', $s);
  $s = preg_replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1[redacted]@', $s);
  $s = preg_replace('/\b(authorization|bearer)\b[:=\s]+\S+/i', '$1 [redacted]', $s);
  $s = preg_replace('/\b([A-Za-z0-9_.-]*(?:pass(?:word|wd)?|secret|token|api[_-]?key|apikey|auth|credential|private[_-]?key)[A-Za-z0-9_.-]*)\s*[=:]\s*("[^"]*"|\'[^\']*\'|\S+)/i', '$1=[redacted]', $s);
  $s = preg_replace('/\b[A-Za-z0-9_-]{40,}\b/', '[redacted]', $s);
  return $s;
}

function dau_clip($s, $max) {
  $s = (string)$s;
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, max(0, $max - 1), 'UTF-8') . '…' : $s;
  }
  return strlen($s) > $max ? substr($s, 0, max(0, $max - 3)) . '...' : $s;
}

/* ---------- structured log ---------- */

function dau_log_max_bytes() { return (int)($GLOBALS['DAU_LOG_MAX'] ?? 1048576); }

/**
 * One line per event: "2026-10-28 08:48:22 level=info type=docker name=plex action=update result=ok retry=0 msg=..."
 * $type: docker|plugin|os|job|discord|config|backup   $result: ok|fail|skip|info|...
 */
function dau_log($level, $type, $name, $action, $result, $msg = '', $retry = null) {
  $q = function ($v) { $v = preg_replace('/[\r\n\t]+/', ' ', dau_redact((string)$v)); return strpbrk($v, ' "=') !== false ? '"' . str_replace('"', "'", $v) . '"' : ($v === '' ? '-' : $v); };
  $line = date('Y-m-d H:i:s', dau_now()) . ' level=' . $level . ' type=' . $q($type) . ' name=' . $q($name)
        . ' action=' . $q($action) . ' result=' . $q($result);
  if ($retry !== null) $line .= ' retry=' . (int)$retry;
  if ($msg !== '') $line .= ' msg=' . $q(dau_clip($msg, 600));
  $line .= "\n";
  $max = dau_log_max_bytes();
  clearstatcache(true, DAU_LOG);
  if (is_file(DAU_LOG) && filesize(DAU_LOG) > $max) {
    @rename(DAU_LOG, DAU_LOG . '.1');   // two generations, so the log is bounded to about 2x max
  }
  @file_put_contents(DAU_LOG, $line, FILE_APPEND);
}

function dau_log_tail($n = 200) {
  $out = [];
  foreach ([DAU_LOG . '.1', DAU_LOG] as $f) if (is_file($f)) { $l = @file($f, FILE_IGNORE_NEW_LINES); if ($l) $out = array_merge($out, $l); }
  return implode("\n", array_slice($out, -$n)) . ($out ? "\n" : '');
}

/* ---------- lock, progress, stop flag ---------- */

/** Non-blocking exclusive lock shared by every job type. Returns a handle or false. */
function dau_lock_try() {
  $fp = @fopen(DAU_LOCK, 'c');
  if (!$fp) return false;
  if (flock($fp, LOCK_EX | LOCK_NB)) return $fp;
  fclose($fp);
  return false;
}
function dau_lock_release($fp) { if (is_resource($fp)) { @flock($fp, LOCK_UN); @fclose($fp); } }

function dau_is_locked() {
  $fp = dau_lock_try();
  if ($fp) { dau_lock_release($fp); return false; }
  return true;
}

function dau_progress($p) { @file_put_contents(DAU_PROGRESS, json_encode($p)); }

function dau_run_info() {
  $p = dau_read_json(DAU_PROGRESS, []);
  $queued = !empty($p['queued']) && (dau_now() - (int)($p['ts'] ?? 0)) < 20;
  return ['running' => dau_is_locked() || $queued, 'progress' => (object)$p, 'stop_requested' => is_file(DAU_STOPFLAG)];
}

function dau_stop_requested() { clearstatcache(true, DAU_STOPFLAG); return is_file(DAU_STOPFLAG); }

/** Start run.php in the background (used by the web UI). Returns false if a job is already running. */
function dau_spawn(array $args) {
  if (dau_run_info()['running']) return false;
  @unlink(DAU_STOPFLAG);
  dau_write_json(DAU_PROGRESS, ['queued' => true, 'ts' => dau_now(), 'phase' => 'Starting']);
  $cmd = 'nohup ' . escapeshellarg(DAU_PHP) . ' ' . escapeshellarg(DAU_ROOT . '/scripts/run.php');
  foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
  if (isset($GLOBALS['DAU_SPAWN_MOCK'])) { call_user_func($GLOBALS['DAU_SPAWN_MOCK'], $args); return true; }
  exec($cmd . ' > /dev/null 2>&1 &');
  return true;
}

/* ---------- Unraid helpers ---------- */

function dau_server_name() {
  $v = @parse_ini_file(DAU_VARINI);
  $n = is_array($v) && !empty($v['NAME']) ? $v['NAME'] : (gethostname() ?: 'Unraid');
  return dau_clip(preg_replace('/[^\x20-\x7E]/', '', $n), 64);
}

function dau_os_version() {
  $v = @parse_ini_file(DAU_OSVERFILE);
  return is_array($v) && !empty($v['version']) ? (string)$v['version'] : null;
}

/** Send a native Unraid notification (the bell menu / email / agents configured in Notification Settings). */
function dau_unraid_notify($subject, $desc, $importance = 'normal', $link = '/Docker') {
  if (!is_file(DAU_NOTIFY)) return;
  dau_run([DAU_NOTIFY, '-e', DAU_NAME, '-s', dau_clip(dau_redact($subject), 120),
           '-d', dau_clip(dau_redact($desc), 500), '-i', $importance, '-l', $link], 30);
}

/** True when the pid file names a process that is still alive (a stale file left by a crash does not count). */
function dau_pid_alive($file) {
  $pid = (int)trim((string)@file_get_contents($file));
  return $pid > 1 && is_dir('/proc/' . $pid);
}

function dau_valid_name($n) { return is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $n) === 1; }

/** Plugin file name as used in /var/log/plugins, e.g. "community.applications.plg". */
function dau_valid_plg($n) { return is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+-]{0,100}\.plg$/', $n) === 1; }

} // DAU_LIB_LOADED

foreach (['config', 'schedule', 'state', 'discord', 'backup', 'backups', 'docker', 'plugins', 'os', 'workflow', 'extras', 'appdata', 'jobs'] as $__m) {
  $__f = __DIR__ . '/' . $__m . '.php';
  if (is_file($__f)) require_once $__f;
}
unset($__m, $__f);
