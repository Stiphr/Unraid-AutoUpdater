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
    if (basename($a[0]) === 'du') { if (!is_dir(end($a))) return ['out' => "0\t" . end($a) . "\n"]; $sz = 0; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(end($a), FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) $sz += $f->isFile() ? $f->getSize() : 0; return ['out' => $sz . "\t" . end($a) . "\n"]; }
    if (basename($a[0]) === 'rm') { dau_test_rm(end($a)); return null; }
    if (strpos($j, 'webGui/scripts/notify') !== false) { $GLOBALS['NOTIFY'][] = $a; return null; }
    if (strpos($j, 'docker') === false) return null;
    if (strpos($j, ' volume ls') !== false) return ['out' => "unused-vol\n" . str_repeat('a', 64) . "\n"];
    if (strpos($j, ' volume inspect') !== false) return ['out' => json_encode([['Name' => 'unused-vol', 'Mountpoint' => '/var/lib/docker/volumes/unused-vol/_data', 'CreatedAt' => 'x'], ['Name' => str_repeat('a', 64), 'Mountpoint' => '/var/lib/docker/volumes/x/_data']])];
    if (strpos($j, ' volume rm') !== false) { $GLOBALS['VOLRM'][] = end($a); return null; }
    if (strpos($j, ' image ls') !== false || strpos($j, ' image inspect') !== false) return null;
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


t('appdata: ignore list, recent warning and looking inside a folder', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  touch("$root/old-app", gmmktime(0, 0, 0, 1, 1, 2025)); touch("$root/tpl-only", dau_now() - 86400);
  $s = dau_appdata_scan(); $by = []; foreach ($s['orphans'] as $o) $by[$o['name']] = $o;
  eq($by['old-app']['recent'], false); eq($by['tpl-only']['recent'], true, 'changed yesterday = recent');
  ok(dau_appdata_ignore('nested', true)['ok']); $s = dau_appdata_scan(); ok(!in_array('nested', array_column($s['orphans'], 'name'))); eq($s['ignored'], ['nested']);
  $r = dau_appdata_run(['0:nested'], 'quarantine', false, false); eq($r['done'], [], 'an ignored folder cannot be moved'); ok(is_dir("$root/nested"));
  ok(dau_appdata_ignore('nested', false)['ok']); ok(in_array('nested', array_column(dau_appdata_scan()['orphans'], 'name')));
  eq(dau_appdata_ignore('../etc', true)['ok'], false); eq(dau_appdata_ignore('.hidden', true)['ok'], false);
  $p = dau_appdata_peek('0:nested'); ok($p['ok']); eq($p['items'][0]['name'], 'data.bin'); eq($p['count'], 2); ok($p['items'][0]['size'] > 0);
  eq(dau_appdata_peek('0:../x')['ok'], false); eq(dau_appdata_peek('0:linked')['ok'], false); eq(dau_appdata_peek('9:nested')['ok'], false); eq(dau_appdata_peek('0:nope')['ok'], false);
});

t('appdata: quarantine expires only when a time was set', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  dau_appdata_run(['0:old-app'], 'quarantine', false, false); $GLOBALS['DAU_NOW'] += 5 * 86400; dau_appdata_run(['0:nested'], 'quarantine', false, false); $GLOBALS['DAU_NOW'] += 4 * 86400;
  $cfg = dau_load_config(); eq(dau_quarantine_expire($cfg), 0, 'off by default'); eq(count(dau_quarantine_load()), 2);
  $cfg['appdata']['purge_days'] = 7; dau_save_config($cfg); eq(dau_quarantine_expire(dau_load_config()), 1, 'only the 9 day old one'); $q = dau_quarantine_load(); eq(count($q), 1); eq($q[0]['name'], 'nested');
  ok(is_dir($q[0]['path']), 'the younger one stays');
});

t('appdata: unused volumes are listed and removed without force', function () {
  adenv([]); $GLOBALS['VOLRM'] = [];
  $s = dau_cleanup_scan(); ok(isset($s['volumes']), json_encode($s)); eq(array_column($s['volumes'], 'name'), ['unused-vol', str_repeat('a', 64)]); ok($s['volumes'][1]['anonymous']);
  $r = dau_cleanup_run([], [], ['unused-vol', 'in-use-vol', '../x']); eq($GLOBALS['VOLRM'], ['unused-vol']); eq(count($r['skipped']), 2); eq($r['removed'], ['volume unused-vol']);
  foreach (calls_matching(' volume rm') as $a) ok(!in_array('-f', $a, true) && !in_array('--force', $a, true), 'never forced');
});

t('appdata: weekly report counts, notifies once and deletes nothing', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]); @mkdir(dirname(DAU_NOTIFY), 0755, true); file_put_contents(DAU_NOTIFY, 'x'); $GLOBALS['NOTIFY'] = [];
  $cfg = dau_load_config(); ok(!dau_cleanup_due($cfg, dau_state_load(), dau_now()), 'off by default');
  $cfg['appdata']['report'] = true; $cfg['notify_unraid']['docker'] = true; dau_save_config($cfg); $cfg = dau_load_config(); ok(dau_cleanup_due($cfg, dau_state_load(), dau_now()));
  $st = dau_state_load(); $J = dau_job_new('scheduled', $cfg, $st); dau_job_cleanup($J);
  eq(count($J['events']), 1); eq($J['events'][0]['kind'], 'cleanup_report'); has($J['events'][0]['detail'], 'Unused appdata folders: 5'); eq($J['st']['cleanup']['appdata']['n'], 5); ok($J['st']['cleanup']['appdata']['size'] > 0);
  dau_job_finish($J); ok(count($GLOBALS['NOTIFY']) === 1, 'one Unraid notification'); has(json_encode($GLOBALS['NOTIFY']), 'space you can free');
  foreach (['old-app', 'nested', 'tpl-only', 'zzz-empty', 'stopped-app'] as $d) ok(is_dir("$root/$d"), "$d untouched");
  /* the same numbers a day later: no second message; a week later with the same numbers: still none */
  $GLOBALS['DAU_NOW'] += 86400 + 10; $st = dau_state_load(); ok(dau_cleanup_due($cfg, $st, dau_now())); $J = dau_job_new('scheduled', $cfg, $st); dau_job_cleanup($J); eq($J['events'], []);
  $GLOBALS['DAU_NOW'] += 8 * 86400; @mkdir("$root/brand-new"); $st = dau_state_load(); $J = dau_job_new('scheduled', $cfg, $st); dau_job_cleanup($J); eq(count($J['events']), 1, 'numbers changed and a week passed');
  /* quiet hours hold it like any other notification */
  $cfg['quiet'] = ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59', 'urgent' => true]; $J['cfg'] = $cfg; $GLOBALS['NOTIFY'] = []; dau_job_finish($J); eq($GLOBALS['NOTIFY'], []); eq(count($J['st']['queue']), 1);
});

t('appdata: report settings are validated', function () {
  $c = dau_sanitize_config(['appdata' => ['purge_days' => 9999, 'report' => '1', 'ignore' => ['ok', '../bad', '.hid', 'a/b', 'fine folder']]], dau_defaults());
  eq($c['appdata']['purge_days'], 365); eq($c['appdata']['report'], true); eq($c['appdata']['ignore'], ['ok', 'fine folder']);
});


t('cleanup: every item carries a status and a plain-language reason; in-use items are not offered', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]); touch("$root/old-app", gmmktime(0, 0, 0, 1, 1, 2025)); touch("$root/tpl-only", dau_now() - 86400);
  file_put_contents(DAU_TEMPLATES . '/my-gone.xml', '<Container><Name>gone</Name><Config Type="Path" Target="/c">/mnt/dau-test/appdata/nested/cfg</Config></Container>');
  $s = dau_appdata_scan(); $by = []; foreach ($s['orphans'] as $o) $by[$o['name']] = $o;
  eq($by['old-app']['status'], 'eligible'); eq($by['tpl-only']['status'], 'review', 'changed yesterday'); eq($by['nested']['status'], 'review', 'a template points here'); has($by['nested']['reason'], 'saved template');
  eq($s['protected'][0]['status'], 'protected'); ok($s['protected'][0]['reason'] !== ''); eq($s['in_use_rows'], [['name' => 'plex', 'by' => ['plex']]]);
  foreach ($by as $o) ok($o['reason'] !== '' && in_array($o['status'], ['eligible', 'review'], true));
  $d = dau_cleanup_scan(); foreach ($d['volumes'] as $v) eq($v['status'], 'review', 'volumes are never pre-approved'); ok(isset($d['in_use_images']));
});

t('cleanup: a dry run of the Docker cleanup changes nothing and reports sizes', function () {
  adenv([]); $GLOBALS['VOLRM'] = [];
  $r = dau_cleanup_run([], [], ['unused-vol', 'nope'], true); eq($r['dry'], true); eq($r['would'], ['volume unused-vol']); eq($r['removed'], []); eq($GLOBALS['VOLRM'], [], 'nothing removed'); eq(count($r['skipped']), 1);
  eq(calls_matching(' volume rm'), []);
});

t('widget: the Dashboard tile never shows the Unraid OS version and uses the plugin logo', function () {
  $page = file_get_contents(__DIR__ . '/../src/UnraidSwissManagerDash.page'); $api = file_get_contents(__DIR__ . '/../src/include/api.php');
  lacks($page, 'os_installed'); lacks($page, 'j.os+'); lacks($page, "'Unraid '+"); has($page, 'images/unraid-swiss-manager.png');
  lacks($page, 'class="w25"', 'no unstyled label columns (labels ran into their values on Unraid 7)'); has($page, "P+'?tab='+tab"); has($page, '#dau-tile .dau-row'); has($page, "'activity', 'bad'");
  has(file_get_contents(__DIR__ . '/../src/assets/app.js'), "q.get('tab')");
  $w = substr($api, strpos($api, "case 'widget'"), 1400); lacks($w, 'os_installed'); lacks($w, 'dau_os_version'); has($w, 'os_update');
});
