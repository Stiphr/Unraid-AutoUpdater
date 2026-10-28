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
  $ov = api_call('overview'); eq($ov['name'], 'Unraid Auto Updater'); eq($ov['build'], DAU_BUILD);
});

t('discord: branding (name, grey/orange icon as avatar and header, build label)', function () {
  os_env(); $c = os_cfg('notify'); $c['discord']['enabled'] = true; dau_save_config($c);
  dau_secret_set('discord_url', 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJ-0123');
  $GLOBALS['SENT'] = []; $GLOBALS['DAU_HTTP_MOCK'] = function ($u, $b) { $GLOBALS['SENT'][] = json_decode($b, true); return ['code' => 204]; };
  dau_job_main('check');
  ok($GLOBALS['SENT'], 'sent'); $p = $GLOBALS['SENT'][0];
  eq($p['username'], 'Unraid Auto Updater'); eq($p['avatar_url'], DAU_DEFAULT_ICON); eq($p['allowed_mentions'], ['parse' => []]);
  $e = $p['embeds'][0]; eq($e['author']['name'], 'Unraid Auto Updater'); eq($e['author']['icon_url'], DAU_DEFAULT_ICON); has($e['footer']['text'], DAU_BUILD); eq($e['footer']['icon_url'], DAU_DEFAULT_ICON);
  has($e['title'], 'UNRAID AUTO UPDATER');
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
