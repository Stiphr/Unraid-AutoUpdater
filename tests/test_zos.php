<?php
/* Unraid OS: default notify-only, opt-in install / reboot, guards, boot verification, Discord branding. All mocked. */

function os_xml($ver, $url = 'https://stable.dl.unraid.net/unRAIDServer.plg') {
  return '<?xml version="1.0"?><!DOCTYPE PLUGIN [<!ENTITY name "unRAIDServer">]><PLUGIN name="&name;" version="' . $ver . '" pluginURL="' . $url . '" min="6.9.0">'
       . '<FILE Name="/boot/x"><URL>https://stable.dl.unraid.net/x.zip</URL></FILE>' . str_repeat('<!-- pad -->', 10) . '</PLUGIN>';
}

/** Installed OS 7.3.4, server offers $latest. $o: install(bool: does `plugin update` really change the file), url, resync, md, bootfree */
function os_env($latest = '7.4.0', array $o = []) {
  $o += ['install' => true, 'url' => 'https://stable.dl.unraid.net/unRAIDServer.plg', 'resync' => 0, 'md' => 'STARTED', 'bootfree' => 5000];
  $GLOBALS['CALLS'] = []; $GLOBALS['NOTIFY'] = []; $GLOBALS['BOOTFREE'] = $o['bootfree']; $GLOBALS['DAU_BOOTFREE'] = $o['bootfree'];
  file_put_contents(DAU_PLUGBOOT . '/unRAIDServer.plg', os_xml('7.3.4'));
  @symlink(DAU_PLUGBOOT . '/unRAIDServer.plg', DAU_PLUGLINKS . '/unRAIDServer.plg');
  @mkdir(DAU_BOOT, 0755, true); file_put_contents(DAU_BOOT . '/bzroot', 'x'); file_put_contents(DAU_BOOT . '/bzimage', 'x');
  @mkdir(dirname(DAU_NOTIFY), 0755, true); file_put_contents(DAU_NOTIFY, 'x');
  file_put_contents(DAU_VARINI, "NAME=\"Tower\"\nmdState=\"{$o['md']}\"\nmdResync=\"{$o['resync']}\"\n");
  mock_exec(function ($a) use ($latest, $o) {
    if (strpos(implode(' ', $a), 'notify') !== false) { $GLOBALS['NOTIFY'][] = $a; return null; }
    if (basename($a[0]) === 'reboot') return null;
    if (basename($a[0]) !== 'plugin') return null;
    $cmd = $a[1]; $arg = $a[2] ?? '';
    if (in_array($cmd, ['version', 'pluginURL', 'min', 'max'], true)) { $x = dau_plg_parse(file_get_contents($arg)); return $x ? ['out' => (string)$x[$cmd] . "\n"] : ['code' => 1]; }
    if ($cmd === 'check') { file_put_contents(DAU_PLUGTMP . '/' . $arg, os_xml($latest, $o['url'])); return ['out' => "plugin: checking\n"]; }
    if ($cmd === 'update') { if ($o['install']) copy(DAU_PLUGTMP . '/' . $arg, DAU_PLUGBOOT . '/' . $arg); return ['code' => 0]; }
    if ($cmd === 'changes') return ['out' => "Release notes\n"];
    return null;
  });
}
function os_cfg($mode = 'notify', array $over = []) {
  $c = dau_defaults();
  $c['os']['mode'] = $mode; $c['os']['confirmed'] = $mode === 'notify' ? '' : $mode; $c['os']['delay_days'] = 0;
  $c['os']['window'] = ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59'];
  $c['global']['enabled'] = false; $c['plugins']['check_enabled'] = false;
  foreach ($over as $k => $v) $c['os'][$k] = $v;
  dau_save_config($c);
  return $c;
}
function os_tick() {
  $st = dau_state_load(); dau_slots_register($st, ['o:os'], dau_now() - 86400 * 2); $st['slots']['o:os'] = ['seen' => dau_now() - 86400 * 2, 'last' => 0]; dau_state_save($st);
  return dau_job_main('tick');
}

t('os: default is check and notify only; install modes need a matching confirmation', function () {
  $c = dau_defaults(); eq($c['os']['mode'], 'notify'); eq($c['os']['confirmed'], ''); eq($c['os']['stable_only'], true); eq($c['os']['delay_days'], 7);
  $x = dau_sanitize_config(['os' => ['mode' => 'install']], $c); eq($x['os']['mode'], 'notify', 'no confirmation -> notify');
  $x = dau_sanitize_config(['os' => ['mode' => 'install', 'confirmed' => 'install']], $c); eq($x['os']['mode'], 'install');
  $x = dau_sanitize_config(['os' => ['mode' => 'install_reboot']], $x); eq($x['os']['mode'], 'notify', 'a different mode needs its own confirmation');
  $x = dau_sanitize_config(['os' => ['mode' => 'install_reboot', 'confirmed' => 'install']], $c); eq($x['os']['mode'], 'notify');
  $x = dau_sanitize_config(['os' => ['mode' => 'install_reboot', 'confirmed' => 'install_reboot']], $c); eq($x['os']['mode'], 'install_reboot');
  $y = dau_sanitize_config(['os' => ['mode' => 'notify']], $x); eq($y['os']['confirmed'], '', 'confirmation cleared');
  $x = dau_sanitize_config(['os' => ['mode' => 'format-disk', 'delay_days' => 9999]], $c); eq($x['os']['mode'], 'notify'); eq($x['os']['delay_days'], 90);
  /* a saved config that is tampered with on disk is cleaned on load */
  $c['os']['mode'] = 'install_reboot'; $c['os']['confirmed'] = ''; dau_write_json(DAU_CFG, $c); eq(dau_load_config()['os']['mode'], 'notify');
});

t('os: eval matrix', function () {
  os_env(); $now = dau_now(); $st = dau_state_defaults(); $st['os']['available'] = '7.4.0'; $st['os']['first_seen'] = $now;
  $c = os_cfg('notify'); eq(dau_os_eval($c, $st, $now)['state'], 'notify_only');
  eq(dau_os_eval($c, $st, $now, true)['state'], 'ready', 'manual ignores mode');
  $c = os_cfg('install'); eq(dau_os_eval($c, $st, $now)['state'], 'ready');
  $c2 = $c; $c2['os']['confirmed'] = ''; eq(dau_os_eval($c2, $st, $now)['state'], 'not_confirmed');
  $c2 = $c; $c2['os']['window']['enabled'] = false; eq(dau_os_eval($c2, $st, $now)['state'], 'blocked', 'install needs a window');
  $c2 = $c; $c2['os']['delay_days'] = 7; eq(dau_os_eval($c2, $st, $now)['state'], 'waiting_delay'); eq(dau_os_eval($c2, $st, $now + 7 * 86400)['state'], 'ready');
  $c2 = $c; $c2['os']['window'] = ['enabled' => true, 'days' => [3], 'start' => '03:00', 'end' => '05:00']; eq(dau_os_eval($c2, $st, $now)['state'], 'window_closed');
  $s2 = $st; $s2['os']['available'] = '7.4.0-rc.1'; eq(dau_os_eval($c, $s2, $now)['state'], 'prerelease'); eq(dau_os_eval($c, $s2, $now, true)['state'], 'ready');
  $c2 = $c; $c2['os']['stable_only'] = false; eq(dau_os_eval($c2, $s2, $now)['state'], 'ready');
  $s2 = $st; $s2['os']['attempted'] = ['version' => '7.4.0', 'result' => 'failed', 'reason' => 'x', 'at' => 1]; eq(dau_os_eval($c, $s2, $now)['state'], 'blocked');
  $s2 = $st; $s2['os']['pending'] = ['from' => '7.3.4', 'to' => '7.4.0', 'at' => 1, 'reboot' => false]; eq(dau_os_eval($c, $s2, $now)['state'], 'staged');
  $s2 = $st; $s2['os']['available'] = null; eq(dau_os_eval($c, $s2, $now)['state'], 'up_to_date');
});

t('os: default mode never installs and never reboots', function () {
  os_env(); os_cfg('notify'); eq(os_tick(), 0);
  eq(count(calls_matching('plugin update')), 0, 'no install'); eq(count(calls_matching('/sbin/reboot')), 0, 'no reboot');
  eq(dau_state_load()['os']['available'], '7.4.0'); eq(dau_state_load()['os']['pending'], null);

});

t('os: install mode installs once, does not reboot, records pending', function () {
  os_env(); os_cfg('install'); eq(os_tick(), 0);
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1); eq(count(calls_matching('/sbin/reboot')), 0);
  $os = dau_state_load()['os']; eq($os['pending']['to'], '7.4.0'); eq($os['pending']['from'], '7.3.4'); eq($os['pending']['reboot'], false);
  $h = dau_history_load(); eq($h[0]['type'], 'os'); eq($h[0]['result'], 'staged'); eq($h[0]['verification']['ok'], true);
  $GLOBALS['DAU_NOW'] += 3600; os_tick(); eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'not installed twice');
  eq(count(calls_matching('/sbin/reboot')), 0);
});

t('os: install_reboot reboots once after the job finished and notified', function () {
  os_env(); os_cfg('install_reboot'); eq(os_tick(), 0);
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1); eq(count(calls_matching('/sbin/reboot -n')), 1, 'rebooted');
  $os = dau_state_load()['os']; eq($os['rebooted_for'], '7.4.0'); eq($os['pending']['reboot'], true);
  /* the notification was sent before the reboot command */
  $idx = null; $ridx = null; foreach ($GLOBALS['CALLS'] as $i => $a) { $j = implode(' ', $a); if (strpos($j, 'notify') !== false && $idx === null) $idx = $i; if (strpos($j, '/sbin/reboot') !== false) $ridx = $i; }
  ok($idx !== null && $ridx > $idx, 'notify before reboot');
  /* the box did not actually go down (mock): a later run must not reboot a second time for the same version */
  $GLOBALS['DAU_NOW'] += 3600; os_tick(); eq(count(calls_matching('/sbin/reboot -n')), 1, 'once per version');
});

t('os: reboot is postponed during a parity check and done later', function () {
  os_env('7.4.0', ['resync' => 12345]); os_cfg('install_reboot'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'installing is fine'); eq(count(calls_matching('/sbin/reboot')), 0, 'no reboot during parity');
  $os = dau_state_load()['os']; ok($os['deferred_at'] > dau_now(), 'retry scheduled'); eq($os['rebooted_for'], '');
  $n = count($GLOBALS['NOTIFY']);
  $GLOBALS['DAU_NOW'] = $os['deferred_at'] + 5; os_tick(); eq(count(calls_matching('/sbin/reboot')), 0, 'still running');
  eq(count(array_filter($GLOBALS['NOTIFY'], function ($a) { return strpos(implode(' ', $a), 'postponed') !== false; })), 1, 'announced once');
  file_put_contents(DAU_VARINI, "NAME=\"Tower\"\nmdState=\"STARTED\"\nmdResync=\"0\"\n");
  $GLOBALS['DAU_NOW'] = dau_state_load()['os']['deferred_at'] + 5; os_tick(); eq(count(calls_matching('/sbin/reboot -n')), 1, 'rebooted once the check is over');
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1);
});

t('os: reboot guard for array states', function () {
  os_env(); foreach (['STARTED' => true, 'STOPPED' => true, 'SWAP_DSBL' => false, 'NEW_ARRAY' => false] as $md => $ok) {
    file_put_contents(DAU_VARINI, "mdState=\"$md\"\nmdResync=\"0\"\n"); eq(dau_os_reboot_safe()['ok'], $ok, $md);
  }
  @unlink(DAU_VARINI); eq(dau_os_reboot_safe()['ok'], false, 'unknown state is not safe');
  file_put_contents(DAU_VARINI, "mdState=\"STARTED\"\nmdResync=\"0\"\n");
  file_put_contents(DAU_MOVERPID, getmypid() . "\n"); eq(dau_os_reboot_safe()['ok'], false, 'mover running'); has(dau_os_reboot_safe()['reason'], 'mover');
  file_put_contents(DAU_MOVERPID, "999999999\n"); eq(dau_os_reboot_safe()['ok'], true, 'stale mover pid file'); @unlink(DAU_MOVERPID);
});

t('os: a failed install is not retried and never reboots', function () {
  os_env('7.4.0', ['install' => false]); os_cfg('install_reboot'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1); eq(count(calls_matching('/sbin/reboot')), 0);
  $os = dau_state_load()['os']; eq($os['attempted']['version'], '7.4.0'); eq($os['pending'], null);
  eq(dau_history_load()[0]['result'], 'failed'); has(dau_history_load()[0]['next'] ?? dau_history_load()[0]['message'], 'Do NOT reboot');
  $GLOBALS['DAU_NOW'] += 7200; os_tick(); eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'no automatic retry');
});

t('os: validation and space guards block the install', function () {
  os_env('7.4.0', ['url' => 'https://stable.dl.unraid.net/unRAIDServer.plg']);
  /* installed file points to another URL than the update -> blocked */
  file_put_contents(DAU_PLUGBOOT . '/unRAIDServer.plg', os_xml('7.3.4', 'https://stable.dl.unraid.net/other.plg')); os_cfg('install'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0); has(dau_state_load()['os']['attempted']['reason'], 'URL');
  $clr = function () { $s = dau_state_load(); $s['os']['attempted'] = null; $s['os']['available'] = null; $s['os']['notified'] = null; dau_state_save($s); };
  $clr(); os_env('7.4.0', ['bootfree' => 100]); os_cfg('install'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0); has(dau_state_load()['os']['attempted']['reason'], '600 MB');
  $clr(); os_env('7.4.0', ['url' => 'http://insecure.example/x.plg']); file_put_contents(DAU_PLUGBOOT . '/unRAIDServer.plg', os_xml('7.3.4', 'http://insecure.example/x.plg')); os_cfg('install'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'http refused');
});

t('os: stable_only and the waiting period hold an automatic install back, manual still works', function () {
  os_env('7.4.0-rc.1'); os_cfg('install'); os_tick(); eq(count(calls_matching('plugin update')), 0, 'pre-release not automatic');
  os_env('7.4.0'); os_cfg('install', ['delay_days' => 7]); $GLOBALS['DAU_NOW'] += 86400; dau_state_save(array_replace_recursive(dau_state_load(), ['os' => ['available' => null]]));
  os_tick(); eq(count(calls_matching('plugin update')), 0, 'waiting'); $d = dau_state_load()['os']['deferred_at']; ok($d >= dau_state_load()['os']['first_seen'] + 7 * 86400, 'deferred to the end of the delay');
  $GLOBALS['DAU_NOW'] = $d + 5; dau_job_main('tick'); eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'installed after the delay by the deferred tick (no schedule slot due)');
  os_env('7.5.0-rc.1'); os_cfg('notify'); eq(dau_job_main('os_install', ['reboot' => false]), 0); eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'manual install ignores mode and stable-only');
  eq(count(calls_matching('/sbin/reboot')), 0);
});

t('os: manual install and reboot; reboot of an already installed update', function () {
  os_env(); os_cfg('notify'); eq(dau_job_main('os_install', ['reboot' => true]), 0);
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1); eq(count(calls_matching('/sbin/reboot -n')), 1);
  /* installed earlier, reboot only now */
  os_env(); os_cfg('notify'); $st = dau_state_load(); $st['os']['pending'] = ['from' => '7.3.4', 'to' => '7.4.0', 'at' => 1, 'reboot' => false]; $st['os']['rebooted_for'] = ''; dau_state_save($st);
  eq(dau_job_main('os_install', ['reboot' => true]), 0); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'not installed again'); eq(count(calls_matching('/sbin/reboot -n')), 1);
  os_env(); os_cfg('notify'); dau_state_save(array_replace(dau_state_load(), []));
  eq(dau_job_main('os', []), 0); eq(count(calls_matching('plugin update')), 0, 'a plain OS check never installs');
});

t('os: boot verification after the reboot (success and failure)', function () {
  os_env(); os_cfg('install_reboot'); os_tick(); eq(dau_state_load()['os']['pending']['reboot'], true);
  /* the server came back running the new version */
  file_put_contents(DAU_OSVERFILE, "version=\"7.4.0\"\n"); $GLOBALS['NOTIFY'] = [];
  dau_install();
  $st = dau_state_load(); eq($st['os']['pending'], null); eq($st['os']['boot_result']['kind'], 'os_verified'); eq($st['os']['installed'], '7.4.0'); eq($st['stats']['os']['ok'], 1);
  $h = dau_history_load(); eq($h[0]['result'], 'updated'); eq($h[0]['trigger'], 'boot'); eq($h[0]['verification']['ok'], true);
  eq(count($GLOBALS['NOTIFY']), 1, 'native notification at boot');
  /* the next job announces it exactly once (Discord) and clears it */
  $GLOBALS['SENT'] = []; $c = os_cfg('install_reboot'); $c['discord']['enabled'] = true; dau_save_config($c);
  dau_secret_set('discord_url', 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJ-0123');
  $GLOBALS['DAU_HTTP_MOCK'] = function ($u, $b) { $GLOBALS['SENT'][] = json_decode($b, true); return ['code' => 204]; };
  dau_job_main('os'); $all = json_encode($GLOBALS['SENT']); has($all, 'OS update verified after reboot'); eq(dau_state_load()['os']['boot_result'], null);
  $n = count($GLOBALS['SENT']); dau_job_main('os'); eq(count($GLOBALS['SENT']), $n, 'not announced twice');
  /* failure: still the old version after the reboot */
  os_env(); os_cfg('install_reboot'); file_put_contents(DAU_OSVERFILE, "version=\"7.3.4\"\n");
  $st = dau_state_load(); $st['os']['pending'] = ['from' => '7.3.4', 'to' => '7.4.0', 'at' => 1, 'reboot' => true]; $st['os']['boot_result'] = null; dau_state_save($st);
  dau_install(); $st = dau_state_load(); eq($st['os']['boot_result']['kind'], 'os_unverified'); eq($st['stats']['os']['failed'], 1); eq(dau_history_load()[0]['result'], 'failed');
  /* no pending update: nothing recorded */
  os_env(); $st = dau_state_load(); $st['os']['pending'] = null; $st['os']['boot_result'] = null; dau_state_save($st); $before = count(dau_history_load()); dau_install(); eq(count(dau_history_load()), $before);
});

t('os: API refuses an install without explicit confirmation', function () {
  $r = api_call('run', ['what' => 'os_install']); has($r['error'] ?? '', 'confirmation');
  $r = api_call('run', ['what' => 'os_install', 'confirm' => '0', 'reboot' => '1']); has($r['error'] ?? '', 'confirmation');
  $o = api_call('os'); ok(isset($o['mode']) && $o['mode'] === 'notify', 'os view'); ok(isset($o['reboot']['ok']));
  $ov = api_call('overview'); eq($ov['name'], 'Unraid Swiss Manager'); eq($ov['version'], DAU_VERSION); ok(!isset($ov['build']), 'only one version');
});

t('discord: branding (name, grey/orange icon as avatar and header, build label)', function () {
  os_env(); $c = os_cfg('notify'); $c['discord']['enabled'] = true; dau_save_config($c);
  dau_secret_set('discord_url', 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJ-0123');
  $GLOBALS['SENT'] = []; $GLOBALS['DAU_HTTP_MOCK'] = function ($u, $b) { $GLOBALS['SENT'][] = json_decode($b, true); return ['code' => 204]; };
  dau_job_main('check');
  ok($GLOBALS['SENT'], 'sent'); $p = $GLOBALS['SENT'][0];
  eq($p['username'], 'Unraid Swiss Manager'); eq($p['avatar_url'], DAU_DEFAULT_ICON); eq($p['allowed_mentions'], ['parse' => []]);
  $e = $p['embeds'][0]; eq($e['author']['name'], 'Unraid Swiss Manager'); eq($e['author']['icon_url'], DAU_DEFAULT_ICON); has($e['footer']['text'], DAU_VERSION); eq($e['footer']['icon_url'], DAU_DEFAULT_ICON);
  has($e['title'], 'UNRAID AUTOUPDATER');
  /* custom icon, and unsafe values fall back */
  $c['discord']['icon_url'] = 'https://example.com/my-logo.png'; dau_save_config($c); $GLOBALS['SENT'] = []; $st = dau_state_load(); dau_discord_test($st);
  eq($GLOBALS['SENT'][0]['avatar_url'], 'https://example.com/my-logo.png'); eq($GLOBALS['SENT'][0]['embeds'][0]['author']['icon_url'], 'https://example.com/my-logo.png');
  foreach (['http://example.com/a.png', 'javascript:alert(1)', 'https://example.com/a b.png', 'https://user:pw@example.com/a.png', str_repeat('a', 400)] as $bad) {
    $x = dau_sanitize_config(['discord' => ['icon_url' => $bad]], dau_defaults()); eq($x['discord']['icon_url'], '', "rejects $bad");
  }
  ok(is_file(__DIR__ . '/../assets/icon.png'), 'bundled icon exists'); eq(file_get_contents(__DIR__ . '/../assets/icon.png', false, null, 1, 3), 'PNG');
});

t('discord: reboot and staged notices are always sent when OS events are on', function () {
  $dc = dau_defaults()['discord']; $dc['enabled'] = true; $dc['types'] = ['success' => false, 'failed' => false, 'available' => false, 'summary' => false];
  foreach (['os_rebooting', 'os_staged', 'os_reboot_blocked'] as $k) ok(dau_discord_wants($dc, ['type' => 'os', 'kind' => $k]), $k);
  ok(!dau_discord_wants($dc, ['type' => 'os', 'kind' => 'os_verified'])); $dc['events']['os'] = false; ok(!dau_discord_wants($dc, ['type' => 'os', 'kind' => 'os_rebooting']));
});

/* ======================= pre-OS maintenance workflow ======================= */

/** OS env + plugin foo (policy auto, update 1 -> 2) and optionally the docker mock. $o: foo_ok, docker(bool), pull_ok, stopflag_on_foo */
function wf_env($o = []) {
  $o += ['foo_ok' => true, 'docker' => false, 'pull_ok' => true, 'stop_on_foo' => false];
  @unlink(DAU_STATE); @unlink(DAU_HIST);
  if ($o['docker']) job_env(['pull_ok' => $o['pull_ok']]);
  $base = $o['docker'] ? $GLOBALS['DAU_EXEC_MOCK'] : null;      // the Docker mock installed by job_env()
  os_env('7.4.0', array_intersect_key($o, ['bootfree' => 1, 'resync' => 1]));
  file_put_contents(DAU_PLUGBOOT . '/foo.plg', plg_xml('1')); @symlink(DAU_PLUGBOOT . '/foo.plg', DAU_PLUGLINKS . '/foo.plg');
  $osMock = $GLOBALS['DAU_EXEC_MOCK'];
  $GLOBALS['DAU_EXEC_MOCK'] = function ($a, $i) use ($o, $osMock, $base) {
    if (basename($a[0]) === 'plugin' && ($a[2] ?? '') === 'foo.plg' || (basename($a[0]) === 'plugin' && ($a[1] ?? '') === 'check' && ($a[2] ?? '') === 'foo.plg')) {
      $GLOBALS['CALLS'][] = $a; $cmd = $a[1];
      if ($cmd === 'check') { file_put_contents(DAU_PLUGTMP . '/foo.plg', plg_xml('2')); return ['code' => 0, 'out' => '', 'err' => '', 'timeout' => false, 'ran' => true]; }
      if ($cmd === 'update') { if ($o['foo_ok']) copy(DAU_PLUGTMP . '/foo.plg', DAU_PLUGBOOT . '/foo.plg'); if ($o['stop_on_foo']) touch(DAU_STOPFLAG); return ['code' => 0, 'out' => '', 'err' => '', 'timeout' => false, 'ran' => true]; }
    }
    if (basename($a[0]) === 'plugin' && in_array($a[1] ?? '', ['version', 'pluginURL', 'min', 'max'], true) && strpos($a[2] ?? '', 'foo.plg') !== false) {
      $x = dau_plg_parse(file_get_contents($a[2])); return ['code' => 0, 'out' => (string)$x[$a[1]] . "\n", 'err' => '', 'timeout' => false, 'ran' => true];
    }
    if ($base && !in_array(basename($a[0]), ['plugin', 'reboot'], true)) return $base($a, $i);
    return $osMock($a, $i);   // records the call
  };
}
function wf_cfg($mode = 'install', array $pre = [], array $os = []) {
  $c = os_cfg($mode, $os); $c['os']['pre'] = $pre + $c['os']['pre']; $c['plugins']['items'] = ['foo.plg' => ['policy' => 'auto']];
  $c['containers']['app'] = ['policy' => 'auto']; $c['containers']['db'] = ['policy' => 'ignore'];
  dau_save_config($c); return $c;
}
function call_idx($needle) { foreach ($GLOBALS['CALLS'] as $i => $a) if (strpos(implode(' ', $a), $needle) !== false) return $i; return null; }
function evkinds() { $k = []; foreach ($GLOBALS['SENT'] ?? [] as $p) foreach ($p['embeds'] ?? [] as $e) $k[] = $e['title']; return $k; }

t('workflow: defaults and settings validation', function () {
  $c = dau_defaults()['os']['pre'];
  eq($c, ['plugins' => true, 'docker' => false, 'require_plugins' => true, 'require_docker' => true, 'confirm' => false, 'notify' => true, 'scope' => 'auto']);
  eq($c['docker'], false, 'Docker updates before the OS are OFF by default');
  $x = dau_sanitize_config(['os' => ['pre' => ['docker' => 'yes', 'scope' => 'everything', 'confirm' => 'true', 'bogus' => 1]]], dau_defaults());
  eq($x['os']['pre']['docker'], true); eq($x['os']['pre']['scope'], 'auto'); eq($x['os']['pre']['confirm'], true); ok(!isset($x['os']['pre']['bogus']));
  ok(dau_os_pre_defaults() === $c, 'one set of defaults');
});

t('workflow: plugins are updated before the OS; Docker is left alone by default', function () {
  wf_env(['docker' => true]); wf_cfg('install'); job_set_update(); eq(os_tick(), 0);
  $p = call_idx('plugin update foo.plg'); $o = call_idx('plugin update unRAIDServer.plg');
  ok($p !== null && $o !== null && $p < $o, 'plugin first, then the OS');
  eq(count(calls_matching('update_container')), 0, 'Docker not touched');
  $os = dau_state_load()['os']; eq($os['pending']['to'], '7.4.0'); eq($os['last_report']['result'], 'ok'); eq($os['last_report']['plugins']['updated'], 1); eq($os['last_report']['docker'], null); eq($os['workflow'], null);
  $h = dau_history_load(); $acts = array_map(function ($e) { return $e['type'] . ':' . $e['action'] . ':' . $e['result']; }, $h);
  ok(in_array('plugin:update:updated', $acts) && in_array('os:prerequisites:ok', $acts) && in_array('os:update:staged', $acts), implode(',', $acts));
});

t('workflow: Docker containers are updated first only when enabled; critical and non-auto ones never', function () {
  wf_env(['docker' => true]); $c = wf_cfg('install', ['docker' => true]); $c['containers']['db'] = ['policy' => 'auto', 'critical' => true]; dau_save_config($c); job_set_update(); os_tick();
  $d = call_idx('update_container'); $o = call_idx('plugin update unRAIDServer.plg');
  ok($d !== null && $d < $o, 'container before the OS'); eq(count(calls_matching('update_container')), 1); has(implode(' ', calls_matching('update_container')[0]), 'app');
  $r = dau_state_load()['os']['last_report']; eq($r['docker']['updated'], 1); eq($r['docker']['selected'], 1);
});

t('workflow: a failed plugin update stops the OS update when required, continues when not', function () {
  wf_env(['foo_ok' => false]); wf_cfg('install_reboot'); $GLOBALS['SENT'] = [];
  os_tick(); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'OS not installed'); eq(count(calls_matching('/sbin/reboot')), 0, 'no reboot');
  $os = dau_state_load()['os']; eq($os['last_report']['result'], 'failed'); eq($os['workflow'], null); eq($os['attempted']['version'], '7.4.0'); has($os['attempted']['reason'], 'plugin update');
  ok(array_filter(dau_history_load(), function ($e) { return $e['action'] === 'prerequisites' && $e['result'] === 'failed'; }), 'recorded');
  $GLOBALS['DAU_NOW'] += 7200; os_tick(); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'one automatic attempt per version');
  /* not required: the OS update still goes ahead, the failure is reported */
  wf_env(['foo_ok' => false]); wf_cfg('install', ['require_plugins' => false]); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'continues when plugin success is not required'); eq(dau_state_load()['os']['last_report']['plugins']['failed'], 1);
});

t('workflow: a failed container update stops the OS update when required', function () {
  wf_env(['docker' => true, 'pull_ok' => false]); wf_cfg('install', ['docker' => true]); job_set_update(); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0); has(dau_state_load()['os']['attempted']['reason'], 'container update');
  wf_env(['docker' => true, 'pull_ok' => false]); wf_cfg('install', ['docker' => true, 'require_docker' => false]); job_set_update(); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'continues when not required');
});

t('workflow: failed system check stops the OS update', function () {
  wf_env(['bootfree' => 100]); wf_cfg('install'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0); has(json_encode(dau_state_load()['os']['last_report']['issues']), 'flash drive');
  wf_env(['resync' => 0]); file_put_contents(DAU_VARINI, "NAME=\"Tower\"\nmdState=\"SWAP_DSBL\"\nmdResync=\"0\"\n"); $s = dau_state_load(); $s['os']['attempted'] = null; $s['os']['available'] = null; dau_state_save($s); wf_cfg('install'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'unusual array state');
});

t('workflow: confirmation pauses before the install; confirm continues, abort cancels', function () {
  wf_env(); wf_cfg('install_reboot', ['confirm' => true]); $GLOBALS['SENT'] = [];
  os_tick(); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'paused'); eq(count(calls_matching('plugin update foo.plg')), 1, 'prerequisites ran');
  $wf = dau_state_load()['os']['workflow']; eq($wf['state'], 'awaiting_confirmation'); eq($wf['reboot'], true);
  eq(dau_os_eval(dau_load_config(), dau_state_load(), dau_now(), false)['state'], 'awaiting_confirmation');
  $GLOBALS['DAU_NOW'] += 3600; os_tick(); eq(count(calls_matching('plugin update foo.plg')), 1, 'not repeated while waiting'); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0);
  eq(dau_job_main('os_confirm'), 0); eq(count(calls_matching('plugin update unRAIDServer.plg')), 1, 'installed after the confirmation'); eq(count(calls_matching('/sbin/reboot -n')), 1, 'reboot as authorised');
  eq(dau_state_load()['os']['workflow'], null);
  /* abort */
  wf_env(); wf_cfg('install', ['confirm' => true]); os_tick(); $a = api_call('os_abort'); ok(!empty($a['ok']), json_encode($a));
  eq(dau_state_load()['os']['workflow'], null); eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'nothing installed');
  ok(array_filter(dau_history_load(), function ($e) { return $e['result'] === 'aborted'; }), 'abort recorded');
  /* a confirm with nothing waiting is refused */
  $r = api_call('run', ['what' => 'os_confirm', 'confirm' => '1']); has($r['error'] ?? '', 'waiting');
});

t('workflow: stop request aborts before the OS install', function () {
  wf_env(['stop_on_foo' => true]); wf_cfg('install'); os_tick();
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0); eq(dau_state_load()['os']['last_report']['result'], 'aborted');
  ok(array_filter(dau_history_load(), function ($e) { return $e['action'] === 'prerequisites' && $e['result'] === 'aborted'; }));
});

t('workflow: manual install uses the choices from the dialog; reboot stays explicit', function () {
  wf_env(); wf_cfg('notify'); eq(dau_job_main('os_install', ['reboot' => false, 'pre_plugins' => false, 'pre_docker' => false]), 0);
  eq(count(calls_matching('plugin update foo.plg')), 0, 'plugins skipped when unticked'); eq(count(calls_matching('plugin update unRAIDServer.plg')), 1); eq(count(calls_matching('/sbin/reboot')), 0);
  wf_env(); wf_cfg('notify'); dau_job_main('os_install', ['reboot' => false, 'pre_plugins' => true, 'pre_docker' => false]);
  ok(call_idx('plugin update foo.plg') < call_idx('plugin update unRAIDServer.plg'), 'plugins first'); eq(count(calls_matching('/sbin/reboot')), 0, 'no reboot without explicit authorisation');
  /* default notify mode never starts the workflow by itself */
  wf_env(); wf_cfg('notify'); os_tick(); eq(count(calls_matching('plugin update')), 0);
});

t('workflow: interrupted workflow is recorded, never resumed blindly; no duplicate jobs', function () {
  wf_env(); wf_cfg('install'); $s = dau_state_load(); $s['os']['workflow'] = ['state' => 'running', 'version' => '7.4.0', 'started' => dau_now() - 600, 'reboot' => false, 'manual' => false]; dau_state_save($s);
  dau_job_main('check'); eq(dau_state_load()['os']['workflow'], null); ok(array_filter(dau_history_load(), function ($e) { return $e['result'] === 'interrupted' && $e['trigger'] === 'recovery'; }));
  eq(count(calls_matching('plugin update unRAIDServer.plg')), 0, 'a check never installs');
  /* a paused workflow expires after 24 h */
  $s = dau_state_load(); $s['os']['workflow'] = ['state' => 'awaiting_confirmation', 'version' => '7.4.0', 'started' => dau_now() - 90000, 'reboot' => false, 'manual' => false]; dau_state_save($s);
  dau_job_main('check'); eq(dau_state_load()['os']['workflow'], null);
  /* second job while one runs */
  $lock = dau_lock_try(); ok($lock); eq(dau_job_main('os_install', ['reboot' => false]), 3, 'refused while locked'); dau_lock_release($lock);
});

t('workflow: Discord reports the workflow and keeps working', function () {
  wf_env(['foo_ok' => false]); $c = wf_cfg('install'); $c['discord']['enabled'] = true; dau_save_config($c);
  dau_secret_set('discord_url', 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJ-0123');
  $GLOBALS['SENT'] = []; $GLOBALS['DAU_HTTP_MOCK'] = function ($u, $b) { $GLOBALS['SENT'][] = json_decode($b, true); return ['code' => 204]; };
  os_tick(); $all = json_encode($GLOBALS['SENT']); has($all, 'Pre-OS maintenance started'); has($all, 'OS update stopped'); has($all, 'Plugins: 0 updated, 1 failed'); lacks($all, 'abcdefghijkl');
  has($all, 'Unraid Swiss Manager');
});

t('release: version, build, icon and update metadata are consistent', function () {
  $plg = file_get_contents(__DIR__ . '/../unraid-autoupdater.plg'); $x = dau_plg_parse($plg); ok($x !== null, 'plg parses');
  $ver = (string)$x['version']; $top = trim(strtok(file_get_contents(__DIR__ . '/../CHANGES.txt'), "\n"));
  eq($top, '###' . $ver, 'top CHANGES entry = plg version'); eq((string)$x['name'], 'unraid-autoupdater');
  has($plg, "define('DAU_VERSION',   '$ver');"); lacks($plg, 'DAU_BUILD'); lacks($plg, '@@'); ok(preg_match('/^\d{4}\.\d{2}\.\d{2}([a-z]|b\d+)?$/', $ver) === 1, 'date format'); $beta = (bool)preg_match('/b\d+$/', $ver);
  eq((string)$x['icon'], 'unraid-autoupdater.png'); has($plg, 'images/unraid-autoupdater.png'); has($plg, hash('sha256', file_get_contents(__DIR__ . '/../assets/icon.png')));
  eq((string)$x['pluginURL'], 'https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/refs/heads/' . ($beta ? 'beta' : 'stable') . '/unraid-autoupdater.plg');
  ok(dau_version_newer($ver, '2026.10.29') && dau_version_newer($ver, '2026.10.28f'), 'newer than the previous releases');
  ok(!dau_version_newer($ver, $ver), 'not newer than itself');
  $fn = 0; foreach ($x->FILE as $f) if (isset($f->SHA256)) { $fn++; $in = trim((string)$f->INLINE, " \t\n\r\0\x0b") . "\n"; eq(hash('sha256', $in), (string)$f->SHA256, 'checksum ' . $f['Name']); }
  ok($fn >= 15, 'all inline files carry a checksum');
  has(file_get_contents(__DIR__ . '/../src/UnraidSwissManager.page'), 'Icon="unraid-autoupdater.png"');
  $js = file_get_contents(__DIR__ . '/../src/assets/app.js'); has($js, "images/unraid-autoupdater.png"); has($js, "class: 'brand'");
  ok(strpos($js, "class: 'brand'") < strpos($js, "class: 'dau-name'"), 'logo is left of the title');
});

t('release: settings and webhook survive a plugin update', function () {
  $c = dau_defaults(); $c['global']['update_delay_hours'] = 48; $c['os']['pre']['docker'] = true; $c['discord']['enabled'] = true; $c['containers']['app'] = ['policy' => 'auto']; dau_save_config($c);
  $u = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJ-0123'; dau_secret_set('discord_url', $u);
  $before = dau_load_config(); dau_install();   // the plugin's install script runs this on every install, update and boot
  eq(dau_load_config(), $before, 'config unchanged'); eq(dau_secret_get('discord_url'), $u, 'webhook unchanged');
  /* settings written by the previous release (no os.pre block) get safe defaults without losing anything */
  $old = $before; unset($old['os']['pre'], $old['os']['mode']); dau_write_json(DAU_CFG, $old); $n = dau_load_config();
  eq($n['os']['pre'], dau_os_pre_defaults()); eq($n['os']['mode'], 'notify'); eq($n['global']['update_delay_hours'], 48); eq($n['containers']['app']['policy'], 'auto');
});

t('rename: migration stub and one-time migration from docker.autoupdate', function () {
  $new = dau_plg_parse(file_get_contents(__DIR__ . '/../unraid-autoupdater.plg')); $stub = dau_plg_parse(file_get_contents(__DIR__ . '/../docker.autoupdate.plg'));
  ok($stub !== null, 'stub parses'); eq((string)$stub['name'], 'docker.autoupdate'); eq((string)$new['name'], 'unraid-autoupdater');
  $isBeta = (bool)preg_match('/b\d+$/', (string)$new['version']); /* beta builds never touch the stub, which stays on the stable URL */
  if (!$isBeta) eq((string)$stub['version'], (string)$new['version'], 'same version'); ok(dau_version_newer((string)$stub['version'], '2026.10.29a'), 'installed copies are offered the stub');
  /* the stub was published under the old repo name; GitHub redirects it, so it is left as published */
  eq((string)$stub['pluginURL'], 'https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/refs/heads/stable/docker.autoupdate.plg', 'old installs keep finding updates here');
  $stubText = file_get_contents(__DIR__ . '/../docker.autoupdate.plg');
  has($stubText, 'plugin install https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/refs/heads/stable/unraid-autoupdater.plg'); has($stubText, 'plugin remove docker.autoupdate.plg'); lacks($stubText, 'rm -rf');
  eq(count($stub->FILE), 2, 'install script + harmless remove script');
  /* run the migration block of the new plugin in a sandbox */
  $blk = null; foreach ($new->FILE as $f) if (isset($f['Run']) && strpos((string)$f->INLINE, 'docker.autoupdate') !== false && strpos((string)$f->INLINE, 'DAU_PLUGS') !== false) $blk = (string)$f->INLINE;
  ok($blk !== null, 'migration block present'); $box = '/tmp/dau-mig-' . getmypid(); dau_test_rm($box);
  foreach (['/plugins/docker.autoupdate', '/em/docker.autoupdate', '/run'] as $d) mkdir($box . $d, 0755, true);
  file_put_contents("$box/plugins/docker.autoupdate/config.json", '{"os":{"mode":"notify"}}'); file_put_contents("$box/plugins/docker.autoupdate/secrets.json", '{"discord_url":"x"}');
  file_put_contents("$box/plugins/docker.autoupdate/history.json", '[]'); file_put_contents("$box/plugins/docker.autoupdate/docker-autoupdate.cron", "* * * * * x\n"); file_put_contents("$box/run/docker-autoupdate.lock", '');
  mkdir("$box/plugins/unraid-autoupdater"); file_put_contents("$box/plugins/unraid-autoupdater/history.json", '["new"]');   // already present: must not be overwritten
  file_put_contents("$box/mig.sh", $blk);
  $cmd = "DAU_PLUGS=$box/plugins DAU_EMHTTP=$box/em DAU_RUN=$box/run bash $box/mig.sh 2>&1"; $out = shell_exec($cmd);
  has($out, 'migrated'); eq(file_get_contents("$box/plugins/unraid-autoupdater/config.json"), '{"os":{"mode":"notify"}}'); eq(file_get_contents("$box/plugins/unraid-autoupdater/secrets.json"), '{"discord_url":"x"}');
  eq(file_get_contents("$box/plugins/unraid-autoupdater/history.json"), '["new"]', 'existing files are never overwritten');
  ok(!file_exists("$box/plugins/unraid-autoupdater/docker-autoupdate.cron") && !file_exists("$box/plugins/docker.autoupdate.migrated/docker-autoupdate.cron"), 'old schedule removed');
  ok(!is_dir("$box/plugins/docker.autoupdate") && is_file("$box/plugins/docker.autoupdate.migrated/config.json"), 'old settings kept as .migrated');
  ok(!is_dir("$box/em/docker.autoupdate") && !file_exists("$box/run/docker-autoupdate.lock"), 'old runtime removed');
  $out2 = shell_exec($cmd); lacks($out2, 'migrated'); eq(file_get_contents("$box/plugins/unraid-autoupdater/config.json"), '{"os":{"mode":"notify"}}', 'second run does nothing');
  dau_test_rm($box);
});

t('rename: no leftovers of the old name in the plugin code or the dashboard', function () {
  foreach (array_merge(glob(__DIR__ . '/../src/*'), glob(__DIR__ . '/../src/*/*')) as $f) {
    if (!is_file($f)) continue; $s = file_get_contents($f);
    foreach (['docker.autoupdate', 'docker-autoupdate', 'DockerAutoUpdate', 'Docker Auto Update', 'Unraid Auto Updater', 'DAU_BUILD'] as $old) lacks($s, $old, basename($f));
  }
  ok(is_file(__DIR__ . '/../src/UnraidSwissManager.page') && !is_file(__DIR__ . '/../src/DockerAutoUpdate.page'));
  $css = file_get_contents(__DIR__ . '/../src/assets/app.css'); has($css, '.dau .ver{'); has($css, 'var(--accent)');
  $js = file_get_contents(__DIR__ . '/../src/assets/app.js'); has($js, "class: 'ver'"); has($js, "'Plugin version ' + S.ov.version"); lacks($js, 'Build ');
});
