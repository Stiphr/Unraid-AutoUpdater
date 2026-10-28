<?php
/* Usage: php tests/run.php [test_file_substring] */
require __DIR__ . '/bootstrap.php';
$only = $argv[1] ?? '';
foreach (glob(__DIR__ . '/test_*.php') as $f) {
  if ($only !== '' && strpos(basename($f), $only) === false) continue;
  require $f;
}
exit(test_summary());
