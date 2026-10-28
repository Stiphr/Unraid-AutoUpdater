<?php
/* Appdata cleanup: orphan detection, protections, quarantine / restore / purge, permanent delete, saved templates. Real folders under /mnt/dau-test, Docker mocked. */

function adenv(array $mounts, array $o = []) {
  $root = '/mnt/dau-test/appdata';
  if (is_dir('/mnt/dau-test')) dau_test_rm('/mnt/dau-test');
  @mkdir($root, 0755, true);
  foreach (['plex', 'old-app', 'stopped-app', 'tpl-only', 'nested', 'zzz-empty'] as $d) { @mkdir("$root/$d", 0755, true); file_put_contents("$root/$d/data.bin", str_repeat('x', 4096 * (1 + strlen($d)))); }
  @mkdir("$root/nested/sub", 0755, true);
  @symlink('/tmp', "$root/linked"); @mkdir("$root/.hidden", 0755, true);
  file_put_contents("$root/plain-file.txt", 'x');
  $cfg = dau_defaults(); $cfg['appdata'] = ['roots' => [$root], 'safe_mode' => $o['safe'] ?? true]; dau_save_config($cfg);
  $GLOBALS['AD'] = ['docker_down' => !empty($o['down'])];
  $all = [];
  foreach ($mounts as $n => $m) $all[] = ['Name' => "/$n", 'State' => ['Status' => $m[0]], 'Mounts' => array_map(function ($s) { return ['Type' => 'bind', 'Source' => $s]; }, $m[1]), 'HostConfig' => ['Binds' => []]];
  mock_exec(function ($a) use ($all) {
    $j = implode(' ', $a);
    if (basename($a[0]) === 'du') { $sz = 0; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(end($a), FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) $sz += $f->isFile() ? $f->getSize() : 0; return ['out' => $sz . "\t" . end($a) . "\n"]; }
    if (basename($a[0]) === 'rm') { dau_test_rm(end($a)); return null; }
    if (strpos($j, 'docker') === false) return null;
    if ($GLOBALS['AD']['docker_down']) return ['code' => 1, 'err' => 'Cannot connect'];
    if (preg_match('# ps -aq#', $j)) return ['out' => implode("\n", array_map(function ($i) { return "id$i"; }, array_keys($all))) . "\n"];
    if (strpos($j, ' inspect ') !== false) return ['out' => json_encode($all)];
    return null;
  });
  return $root;
}

t('appdata: only folders no container maps are offered; protections hold', function () {
  $root = adenv(['plex' => ['running', ['/mnt/user/dau-nope', '/mnt/dau-test/appdata/plex']], 'old-stopped' => ['exited', ['/mnt/dau-test/appdata/stopped-app/config']],
    'tool' => ['running', ['/mnt/dau-test']]]);
  file_put_contents(DAU_TEMPLATES . '/my-gone.xml', '<Container><Name>gone</Name><Repository>x/gone</Repository><Config Name="c" Type="Path" Target="/c">/mnt/dau-test/appdata/tpl-only/cfg</Config></Container>');
  $s = dau_appdata_scan(); ok($s['ok'], json_encode($s));
  $names = array_column($s['orphans'], 'name'); sort($names);
  eq($names, ['nested', 'old-app', 'tpl-only', 'zzz-empty'], 'plex (running) and stopped-app (stopped container) are in use');
  eq($s['in_use'], 2); 
  eq(array_column($s['protected'], 'name'), ['linked']); eq($s['protected'][0]['why'], 'symbolic link');
  ok(in_array('tool', $s['broad']), 'a mount of a parent folder is listed, not counted as ownership');
  $tpl = array_values(array_filter($s['orphans'], function ($o) { return $o['name'] === 'tpl-only'; }))[0]; eq($tpl['tpl'], ['gone']);
  ok($s['total'] > 0 && $s['orphans'][0]['size'] >= $s['orphans'][1]['size'], 'biggest first'); eq(count(array_filter($s['orphans'], function ($o) { return $o['name'] === '.hidden'; })), 0);
});

t('appdata: a stopped container still protects its folder (also through another disk name)', function () {
  adenv(['plex' => ['running', ['/mnt/cache/appdata/plex']], 'sleeper' => ['exited', ['/mnt/disk1/appdata/stopped-app/db']]]);
  $s = dau_appdata_scan(); $names = array_column($s['orphans'], 'name'); sort($names);
  eq($names, ['nested', 'old-app', 'tpl-only', 'zzz-empty']); eq($s['in_use'], 2);
});

t('appdata: when Docker cannot be asked nothing is offered', function () {
  adenv([], ['down' => true]); $s = dau_appdata_scan(); eq($s['ok'], false); has($s['error'], 'not running');
  $r = dau_appdata_run(['0:old-app'], 'quarantine', false, false); eq($r['ok'], false); ok(is_dir('/mnt/dau-test/appdata/old-app'));
});

t('appdata: dry run changes nothing; quarantine moves, restore and purge work', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  $r = dau_appdata_run(['0:old-app', '0:plex', '0:../etc'], 'quarantine', true, false);
  eq(array_column($r['would'], 'name'), ['old-app']); eq(count($r['skipped']), 2); ok(is_dir("$root/old-app")); eq(dau_quarantine_load(), []);
  $r = dau_appdata_run(['0:old-app', '0:plex'], 'quarantine', false, false); eq($r['done'], ['old-app']); eq(count($r['skipped']), 1);
  ok(!is_dir("$root/old-app") && is_dir("$root/plex"), 'plex (in use) was refused');
  $q = dau_quarantine_list()['rows']; eq(count($q), 1); ok($q[0]['exists'] && $q[0]['free'], 'restorable'); ok(strpos($q[0]['path'], '/.unraid-autoupdater-quarantine/old-app__') !== false);
  ok(!in_array('.unraid-autoupdater-quarantine', array_column(dau_appdata_scan()['orphans'], 'name')), 'quarantine is not offered as an orphan');
  /* restore, then conflict handling */
  $id = $q[0]['id']; $r = dau_quarantine_act($id, 'restore'); ok($r['ok']); ok(is_file("$root/old-app/data.bin")); eq(dau_quarantine_load(), []);
  dau_appdata_run(['0:old-app'], 'quarantine', false, false); $id = dau_quarantine_list()['rows'][0]['id']; @mkdir("$root/old-app"); file_put_contents("$root/old-app/new.txt", 'n');
  $r = dau_quarantine_act($id, 'restore'); eq($r['ok'], false); ok(!empty($r['conflict'])); ok(is_file("$root/old-app/new.txt") && !is_file("$root/old-app/data.bin"), 'nothing overwritten');
  $r = dau_quarantine_act($id, 'restore', true); ok($r['ok']); ok(is_file("$root/old-app-restored/data.bin"), 'restored beside it'); ok(is_file("$root/old-app/new.txt"));
  /* purge */
  dau_appdata_run(['0:nested'], 'quarantine', false, false); $e = dau_quarantine_list()['rows'][0]; $r = dau_quarantine_act($e['id'], 'purge'); ok($r['ok']); ok(!is_dir($e['path'])); eq(dau_quarantine_load(), []);
  /* a tampered record cannot reach outside the quarantine folder */
  $q = [['id' => 'abcdefabcdef', 'name' => 'x', 'original' => "$root/x", 'path' => "$root/plex", 'root' => $root, 'size' => 1, 'at' => 1]]; dau_quarantine_save($q);
  $r = dau_quarantine_act('abcdefabcdef', 'purge'); eq($r['ok'], false); ok(is_dir("$root/plex") && is_file("$root/plex/data.bin"), 'plex untouched');
});

t('appdata: permanent delete needs Safe mode off and a confirmation', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  $r = dau_appdata_run(['0:old-app'], 'delete', false, true); eq($r['ok'], false); has($r['error'], 'Safe mode'); ok(is_dir("$root/old-app"));
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]], ['safe' => false]);
  $r = dau_appdata_run(['0:old-app'], 'delete', false, false); eq($r['ok'], false); ok(is_dir("$root/old-app"), 'no confirmation, no delete');
  $r = dau_appdata_run(['0:old-app', '0:plex'], 'delete', true, true); eq(array_column($r['would'], 'name'), ['old-app']); ok(is_dir("$root/old-app"), 'dry run');
  $r = dau_appdata_run(['0:old-app', '0:plex'], 'delete', false, true); eq($r['done'], ['old-app']); ok(!is_dir("$root/old-app") && is_dir("$root/plex")); ok($r['freed'] > 0);
  foreach (calls_matching(' rm ') as $a) ok(in_array('--one-file-system', $a, true));
});

t('appdata: settings are validated', function () {
  $c = dau_sanitize_config(['appdata' => ['roots' => ['/mnt/user/appdata', '/etc', '/mnt/user/../x', 'rel', '/mnt/cache/apps/'], 'safe_mode' => false]], dau_defaults());
  eq($c['appdata']['roots'], ['/mnt/user/appdata', '/mnt/cache/apps']); eq($c['appdata']['safe_mode'], false);
  $c = dau_sanitize_config(['appdata' => ['roots' => ['/etc']]], dau_defaults()); eq($c['appdata']['roots'], ['/mnt/user/appdata'], 'all bad = unchanged'); eq($c['appdata']['safe_mode'], true, 'safe by default');
});

t('appdata: saved templates of removed containers can be archived and restored', function () {
  adenv(['live' => ['running', []]]);
  foreach (['live', 'gone'] as $n) file_put_contents(DAU_TEMPLATES . "/my-$n.xml", "<Container><Name>$n</Name><Repository>x/$n</Repository></Container>");
  $s = dau_tpl_scan(); eq(array_column($s['templates'], 'name'), ['gone']);
  $r = dau_tpl_act('archive', 'live'); eq($r['ok'], false); ok(is_file(DAU_TEMPLATES . '/my-live.xml'), 'a template of an installed container is refused');
  $r = dau_tpl_act('archive', 'gone'); ok($r['ok'], json_encode($r)); ok(!is_file(DAU_TEMPLATES . '/my-gone.xml')); ok(is_file(DAU_TPL_BACKUPS . '/' . $r['backup']));
  $s = dau_tpl_scan(); eq($s['templates'], []); eq(count($s['backups']), 1); ok($s['backups'][0]['free']);
  $f = $s['backups'][0]['file']; $r = dau_tpl_act('restore', '', $f); ok($r['ok']); ok(is_file(DAU_TEMPLATES . '/my-gone.xml'));
  $r = dau_tpl_act('restore', '', $f); eq($r['ok'], false); has($r['error'], 'Nothing was overwritten');
  $r = dau_tpl_act('restore', '', '../../etc/passwd'); eq($r['ok'], false); $r = dau_tpl_act('drop', '', $f); ok($r['ok']); eq(dau_tpl_scan()['backups'], []);
});
