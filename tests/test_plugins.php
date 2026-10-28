<?php
/* Plugin and OS tests (mocked `plugin` tool, fake files). */

function plg_xml($ver, $extra = '', $url = 'https://raw.githubusercontent.com/a/b/main/foo.plg', $min = '6.9.0') {
  return '<?xml version="1.0"?><!DOCTYPE PLUGIN [<!ENTITY name "foo"><!ENTITY gh "https://github.com/a/b">]><PLUGIN name="&name;" version="' . $ver . '" pluginURL="' . $url . '" min="' . $min . '">'
       . '<FILE Name="/boot/x.txz"><URL>&gh;/releases/x.txz</URL></FILE>' . $extra . str_repeat('<!-- pad -->', 10) . '</PLUGIN>';
}

/** Installed foo.plg (version 1) + mock plugin tool. $latest = what `check` reports/downloads. */
function plg_env($latest = '2', $newXml = null, array $o = []) {
  $GLOBALS['CALLS'] = [];
  file_put_contents(DAU_PLUGBOOT . '/foo.plg', plg_xml('1'));
  @symlink(DAU_PLUGBOOT . '/foo.plg', DAU_PLUGLINKS . '/foo.plg');
  file_put_contents(DAU_PLUGBOOT . '/' . DAU_SELF_PLG, plg_xml('1'));
  @symlink(DAU_PLUGBOOT . '/' . DAU_SELF_PLG, DAU_PLUGLINKS . '/' . DAU_SELF_PLG);
  file_put_contents(DAU_PLUGBOOT . '/unRAIDServer.plg', '<PLUGIN/>');
  @symlink(DAU_PLUGBOOT . '/unRAIDServer.plg', DAU_PLUGLINKS . '/unRAIDServer.plg');
  $o += ['install' => true];
  mock_exec(function ($a) use ($latest, $newXml, $o) {
    if (basename($a[0]) !== 'plugin') return null;
    $cmd = $a[1]; $arg = $a[2] ?? '';
    if ($cmd === 'version' || $cmd === 'pluginURL' || $cmd === 'min' || $cmd === 'max') {
      $x = dau_plg_parse(file_get_contents($arg)); $k = ['version' => 'version', 'pluginURL' => 'pluginURL', 'min' => 'min', 'max' => 'max'][$cmd];
      return $x ? ['out' => (string)$x[$k] . "\n"] : ['code' => 1];
    }
    if ($cmd === 'check') {
      if ($arg === 'unRAIDServer.plg') { if (isset($o['os_fail'])) return ['code' => 1, 'err' => 'download failed']; file_put_contents(DAU_PLUGTMP . '/unRAIDServer.plg', plg_xml($latest)); return ['out' => "plugin: checking\nplugin: downloading ... done\nExecuting hook script: CA_preHook\nClearing cache\n"]; }
      file_put_contents(DAU_PLUGTMP . '/' . $arg, $newXml ?? plg_xml($latest)); return ['out' => "plugin: checking: $arg ...\nplugin: downloading: $arg ... done\nExecuting hook script: CA_preHook\n$latest\nExecuting hook script: post_plugin_checks\n"];
    }
    if ($cmd === 'update') { if ($o['install']) copy(DAU_PLUGTMP . '/' . $arg, DAU_PLUGBOOT . '/' . $arg); return ['code' => 0]; }
    if ($cmd === 'changes') return ['out' => "Fixes things\n"];
    return null;
  });
  return dau_plugins_installed();
}

t('plugins: detection skips Unraid itself, marks self', function () {
  $i = plg_env(); eq(array_keys($i), ['foo.plg', DAU_SELF_PLG]); eq($i['foo.plg']['version'], '1'); ok($i[DAU_SELF_PLG]['self']);
  $c = dau_plugins_check_all(dau_defaults(), $i); ok(isset($c['avail']['foo.plg']));
});

t('plugins: default is OFF; policy matrix', function () {
  $cfg = dau_defaults(); eq($cfg['plugins']['auto_update'], false);
  $i = plg_env(); $p = $i['foo.plg']; $st = dau_state_defaults();
  eq(dau_plugin_eval($cfg, $st, $p, '2', 1)['state'], 'notify_only');
  $cfg['plugins']['auto_update'] = true; $cfg['plugins']['default_policy'] = 'auto'; $cfg['plugins']['delay_hours'] = 24;
  eq(dau_plugin_eval($cfg, $st, $p, '2', 100)['state'], 'no_identity');
  dau_plugins_track($st, ['foo.plg' => '2'], 100);
  eq(dau_plugin_eval($cfg, $st, $p, '2', 200)['state'], 'waiting_delay');
  eq(dau_plugin_eval($cfg, $st, $p, '2', 100 + 86400)['state'], 'ready');
  $cfg['plugins']['items']['foo.plg'] = ['policy' => 'ignore']; eq(dau_plugin_eval($cfg, $st, $p, '2', 100 + 86400)['state'], 'ignored');
  eq(dau_plugin_eval($cfg, $st, $i[DAU_SELF_PLG], '2', 100 + 86400)['state'], 'self');
  eq(dau_plugin_eval($cfg, $st, $p, '1', 1)['state'], 'up_to_date');
  dau_plugins_track($st, ['foo.plg' => '2'], 5000); eq($st['plugins']['seen']['foo.plg']['first'], 100, 'repeat check keeps clock');
});

t('plugins: successful update, verified by re-reading the installed file', function () {
  $i = plg_env(); dau_plugins_check_all(dau_defaults(), $i);
  $r = dau_plugin_update(dau_defaults(), $i['foo.plg'], '2');
  eq($r['result'], 'updated'); eq($r['verification']['result'], 'version_matches');
  ok(is_file(DAU_RECOVERY . '/foo-1.plg'), 'recovery copy kept');
});

t('plugins: failed install is detected despite exit code 0, no rollback attempted', function () {
  $i = plg_env('2', null, ['install' => false]); dau_plugins_check_all(dau_defaults(), $i);
  $r = dau_plugin_update(dau_defaults(), $i['foo.plg'], '2');
  eq($r['result'], 'failed'); has($r['next'], 'Not rolled back'); has($r['next'], 'foo-1.plg');
  eq(count(calls_matching('plugin update')), 1);
});

t('plugins: parsing a plg leaves XML file loading working (PHP 7 entity loader is restored)', function () {
  ok(dau_plg_parse(plg_xml('2')) !== null);
  file_put_contents('/tmp/dau-t.xml', '<a><b>1</b></a>'); ok(@simplexml_load_file('/tmp/dau-t.xml') !== false, 'simplexml_load_file still works'); @unlink('/tmp/dau-t.xml');
});

t('plugins: validation blocks unsafe updates', function () {
  $cases = [
    'new host'      => [plg_xml('2', '<FILE Name="/x"><URL>https://evil.example.net/x</URL></FILE>'), 'new host'],
    'url change'    => [plg_xml('2', '', 'https://other.example.com/foo.plg'), 'source URL'],
    'version lie'   => [plg_xml('3'), 'differs'],
    'too new unraid'=> [plg_xml('2', '', 'https://raw.githubusercontent.com/a/b/main/foo.plg', '99.0.0'), 'compatible'],
    'external ent'  => ['<?xml version="1.0"?><!DOCTYPE PLUGIN [<!ENTITY x SYSTEM "file:///etc/passwd">]><PLUGIN name="foo" version="2" pluginURL="https://raw.githubusercontent.com/a/b/main/foo.plg">&x;' . str_repeat(' ', 80) . '</PLUGIN>', 'valid plugin'],
    'tiny'          => ['<PLUGIN/>', 'size'],
  ];
  foreach ($cases as $k => list($xml, $needle)) {
    $i = plg_env('2', $xml); dau_plugins_check_all(dau_defaults(), $i);
    $r = dau_plugin_update(dau_defaults(), $i['foo.plg'], '2');
    eq($r['result'], 'blocked', $k); has($r['error'], $needle, $k); eq(count(calls_matching('plugin update')), 0, "$k: nothing installed");
  }
});

t('plugins: unsafe pluginURL and tampering after validation', function () {
  $i = plg_env(); $p = $i['foo.plg']; dau_plugins_check_all(dau_defaults(), $i);
  $p['url'] = 'https://x.example.com/a;rm -rf /'; eq(dau_plugin_validate($p, '2')['ok'], false);
  $p['url'] = 'http://x.example.com/a.plg'; eq(dau_plugin_validate($p, '2')['ok'], false);
  $p['url'] = 'https://x.example.com/a`id`.plg'; eq(dau_plugin_validate($p, '2')['ok'], false);
});

t('plugins: self plugin is never updated', function () {
  $i = plg_env(); $r = dau_plugin_update(dau_defaults(), $i[DAU_SELF_PLG], '2'); eq($r['result'], 'blocked'); eq(count(calls_matching('plugin update')), 0);
});

t('os: detect once, never install, withdrawn + failure handling', function () {
  plg_env('7.4.0'); $st = dau_state_defaults();
  $c = dau_os_check(); ok($c['ok']); eq($c['latest'], '7.4.0');
  $ev = dau_os_process($st, $c, 100); eq(count($ev), 1); eq($ev[0]['kind'], 'available'); has($ev[0]['reason'], 'Fixes');
  eq(count(dau_os_process($st, $c, 200)), 0, 'no repeat notification');
  $ev = dau_os_process($st, dau_os_check_result_for('7.3.4'), 300); eq($ev[0]['kind'], 'os_withdrawn');
  eq(count(dau_os_process($st, dau_os_check_result_for('7.3.4'), 400)), 0);
  $ev = dau_os_process($st, ['ok' => false, 'error' => 'boom'], 500); eq($ev[0]['kind'], 'os_check_failed');
  eq(count(dau_os_process($st, ['ok' => false, 'error' => 'boom'], 600)), 0, 'failure announced once');
  eq(count(calls_matching('update unRAID')), 0); eq(count(calls_matching('reboot')), 0);
  plg_env('7.4.0', null, ['os_fail' => 1]); ok(!dau_os_check()['ok']);
});
function dau_os_check_result_for($v) { return ['ok' => true, 'latest' => $v]; }

t('os: installed after available is reported', function () {
  plg_env('7.4.0'); $st = dau_state_defaults(); dau_os_process($st, ['ok' => true, 'latest' => '7.4.0'], 1);
  file_put_contents(DAU_OSVERFILE, "version=\"7.4.0\"\n");
  $ev = dau_os_process($st, ['ok' => true, 'latest' => '7.4.0'], 2); eq($ev[0]['kind'], 'os_installed');
});
