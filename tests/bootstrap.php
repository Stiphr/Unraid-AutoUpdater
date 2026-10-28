<?php
/*
 * Minimal test harness. No external dependencies.
 * Runs the plugin code in-process against a throw-away copy of the paths it uses (/boot, /var/log, /var/run,
 * /tmp/plugins ...) using the in-process seams (DAU_EXEC_MOCK, DAU_HTTP_MOCK, DAU_NOW). Nothing here talks to
 * Docker, Unraid, Discord or the network.
 *
 * SAFETY: it deletes and recreates the directories below, so it refuses to run unless the marker file exists
 * or the directories do not exist yet (a fresh CI container / sandbox). Never run it on a real Unraid server.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'names' => [], 'current' => ''];

/*
 * A real Unraid server has the flash drive mounted at /boot and the stock web GUI installed. The tests would wipe
 * /boot/config/plugins/unraid-autoupdater and /var/log/plugins, so refuse in that case unless this machine has
 * already been used for tests (marker file written by reset_env()).
 */
function dau_test_guard() {
  $marker = '/tmp/.dau-test-ok';
  $mounts = @file_get_contents('/proc/mounts') ?: '';
  $realFlash = (bool)preg_match('#\s/boot\s#', $mounts);
  $realGui = is_dir('/usr/local/emhttp/plugins/dynamix') && !is_file($marker);
  if (($realFlash || $realGui) && !is_file($marker)) {
    fwrite(STDERR, "Refusing to run: this looks like a real Unraid server (flash mounted at /boot or stock web GUI present).\n");
    exit(2);
  }
}
dau_test_guard();

function reset_env() {
  @file_put_contents('/tmp/.dau-test-ok', "created by the plugin test harness\n");
  foreach (['/boot/config/plugins/unraid-autoupdater', '/boot/config/plugins/dockerMan', '/var/log/plugins', '/tmp/plugins'] as $d) {
    if (is_dir($d)) dau_test_rm($d);
  }
  foreach (glob('/var/log/unraid-autoupdater.log*') as $f) @unlink($f);
  foreach (['/var/run/unraid-autoupdater.lock', '/var/run/unraid-autoupdater.progress', '/var/run/unraid-autoupdater.stop', '/var/lib/docker/unraid-update-status.json', '/etc/unraid-version', '/var/local/emhttp/var.ini'] as $f) @unlink($f);
  foreach (['/boot/config/plugins/unraid-autoupdater', '/boot/config/plugins/dockerMan/templates-user', '/var/log/plugins', '/tmp/plugins', '/var/run', '/var/lib/docker', '/var/local/emhttp', '/etc'] as $d) @mkdir($d, 0755, true);
  file_put_contents('/etc/unraid-version', "version=\"7.3.4\"\n");
  file_put_contents('/var/local/emhttp/var.ini', "NAME=\"Tower\"\n");
  unset($GLOBALS['DAU_EXEC_MOCK'], $GLOBALS['DAU_HTTP_MOCK'], $GLOBALS['DAU_SPAWN_MOCK']);
  $GLOBALS['DAU_NOW'] = 1793000000;           // fixed clock (2026-10-26 07:33:20 UTC); sleeping advances it
  $GLOBALS['DAU_SLEPT'] = 0;
  $GLOBALS['CALLS'] = [];
}

function dau_test_rm($p) {
  if (is_link($p) || is_file($p)) { @unlink($p); return; }
  if (!is_dir($p)) return;
  foreach (scandir($p) as $f) if ($f !== '.' && $f !== '..') dau_test_rm("$p/$f");
  @rmdir($p);
}

function t($name, $fn) {
  $only = getenv('TFILTER');                       // TFILTER=text runs only the tests whose name contains it
  if ($only !== false && $only !== '' && strpos($name, $only) === false) return;
  $GLOBALS['T']['current'] = $name;
  reset_env();
  try { $fn(); }
  catch (Throwable $e) { fail("exception: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
}

function ok($cond, $msg = '') {
  if ($cond) { $GLOBALS['T']['pass']++; return; }
  fail($msg ?: 'assertion failed');
}
function eq($a, $b, $msg = '') {
  if ($a === $b) { $GLOBALS['T']['pass']++; return; }
  fail(($msg ? "$msg: " : '') . 'expected ' . json_encode($b) . ' got ' . json_encode($a));
}
function has($hay, $needle, $msg = '') { ok(strpos((string)$hay, $needle) !== false, ($msg ?: 'missing text') . ": '$needle' not in " . substr((string)$hay, 0, 300)); }
function lacks($hay, $needle, $msg = '') { ok(strpos((string)$hay, $needle) === false, ($msg ?: 'unexpected text') . ": '$needle' found"); }

function fail($msg) {
  $GLOBALS['T']['fail']++;
  $GLOBALS['T']['names'][] = $GLOBALS['T']['current'] . ' -> ' . $msg;
  echo "  FAIL [" . $GLOBALS['T']['current'] . "] $msg\n";
}

function test_summary() {
  $T = $GLOBALS['T'];
  echo "\n{$T['pass']} assertions passed, {$T['fail']} failed\n";
  return $T['fail'] === 0 ? 0 : 1;
}

/** Record every command; $handler(argv) may return a result array or null (-> code 0, empty output). */
function mock_exec(callable $handler) {
  $GLOBALS['DAU_EXEC_MOCK'] = function ($argv, $stdin) use ($handler) {
    $GLOBALS['CALLS'][] = $argv;
    $r = $handler($argv, $stdin);
    return is_array($r) ? $r : ['code' => 0, 'out' => '', 'err' => ''];
  };
}

function calls_matching($needle) {
  return array_values(array_filter($GLOBALS['CALLS'], function ($a) use ($needle) { return strpos(implode(' ', $a), $needle) !== false; }));
}

require_once __DIR__ . '/../src/include/lib.php';
