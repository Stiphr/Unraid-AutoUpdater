<?php
/* Config, migration, schedule maths, windows, redaction, run helper, Discord. */

echo "== config ==\n";

t('defaults are safe: nothing automatic', function () {
  $c = dau_load_config();
  eq($c['global']['enabled'], false);
  eq($c['plugins']['auto_update'], false, 'plugin auto-update must default off');
  eq($c['discord']['enabled'], false);
  eq($c['global']['backup']['enabled'], false);
  eq($c['containers'], []);
});

t('v1 config migrates without losing schedules, exclusions or notify toggles', function () {
  $v1 = ['global' => ['enabled' => true, 'frequency' => 'weekly', 'time' => '03:30', 'weekday' => 2, 'monthday' => 1,
                      'notify' => true, 'check_only' => false, 'skip_stopped' => true, 'prune' => true, 'delay' => 15],
         'containers' => [
           'plex'    => ['auto' => true, 'schedule' => ['frequency' => 'daily', 'time' => '02:15', 'weekday' => 0, 'monthday' => 1]],
           'radarr'  => ['auto' => true],
           'adguard' => ['auto' => true, 'notify' => false, 'schedule' => ['frequency' => 'monthly', 'time' => '03:00', 'weekday' => 0, 'monthday' => 5]],
           'old'     => ['auto' => false],
         ]];
  file_put_contents(DAU_CFG, json_encode($v1));
  $c = dau_load_config($st);
  eq($st, 'migrated');
  eq($c['global']['frequency'], 'weekly'); eq($c['global']['weekday'], 2); eq($c['global']['delay'], 15); eq($c['global']['enabled'], true);
  eq($c['containers']['plex']['policy'], 'auto');
  eq($c['containers']['plex']['schedule']['time'], '02:15');
  eq($c['containers']['adguard']['notify'], false);
  eq($c['containers']['adguard']['schedule']['monthday'], 5);
  eq($c['containers']['old']['policy'], 'ignore');
  ok(is_file(DAU_DIR . '/config.v1.backup.json'), 'v1 backup kept');
  $again = dau_load_config($st2);
  eq($st2, 'ok', 'second load is not a migration');
  eq($again['containers']['radarr']['policy'], 'auto');
});

t('v1 "notify only" becomes policy notify', function () {
  file_put_contents(DAU_CFG, json_encode(['global' => ['enabled' => true, 'check_only' => true], 'containers' => ['a' => ['auto' => true]]]));
  eq(dau_load_config()['containers']['a']['policy'], 'notify');
});

t('corrupt config fails safe and keeps the damaged file', function () {
  file_put_contents(DAU_CFG, '{not json');
  $c = dau_load_config($st);
  eq($st, 'corrupt'); eq($c['global']['enabled'], false); eq($c['plugins']['auto_update'], false);
  ok(count(glob(DAU_DIR . '/config.corrupt-*.json')) === 1, 'corrupt copy kept');
});

t('invalid values are rejected, valid ones kept', function () {
  $in = ['global' => ['frequency' => 'hourly', 'time' => '99:99', 'weekday' => 17, 'monthday' => 0, 'delay' => 99999, 'update_delay_hours' => -5,
                      'retry' => ['max' => 99, 'interval_min' => 1], 'verify' => ['timeout' => 1], 'enabled' => 'maybe',
                      'backup' => ['dest' => '/etc/../root', 'retention' => 0]],
         'containers' => ['bad name!' => ['policy' => 'auto'], 'good' => ['policy' => 'nuke', 'delay_hours' => 9999, 'schedule' => ['frequency' => 'bogus']]],
         'plugins' => ['auto_update' => 'true', 'default_policy' => 'x'], 'discord' => ['max_messages' => 500, 'batching' => 'spam']];
  $c = dau_sanitize_config($in, dau_defaults());
  eq($c['global']['frequency'], 'daily'); eq($c['global']['time'], '04:00'); eq($c['global']['weekday'], 6); eq($c['global']['monthday'], 1);
  eq($c['global']['delay'], 600); eq($c['global']['update_delay_hours'], 0);
  eq($c['global']['retry']['max'], 5); eq($c['global']['retry']['interval_min'], 5); eq($c['global']['verify']['timeout'], 10);
  eq($c['global']['enabled'], false, 'garbage boolean keeps the old value');
  eq($c['global']['backup']['dest'], '', 'path traversal rejected'); eq($c['global']['backup']['retention'], 1);
  ok(!isset($c['containers']['bad name!']), 'bad container name dropped');
  eq($c['containers']['good']['policy'], 'ignore'); eq($c['containers']['good']['delay_hours'], 720);
  eq($c['plugins']['auto_update'], true); eq($c['plugins']['default_policy'], 'notify');
  eq($c['discord']['max_messages'], 20); eq($c['discord']['batching'], 'combine');
});

t('backup destination must be under /mnt', function () {
  ok(dau_valid_dest('/mnt/user/backups/docker'));
  ok(!dau_valid_dest('/boot/backups')); ok(!dau_valid_dest('/mnt/../etc')); ok(!dau_valid_dest("/mnt/user/a\nb")); ok(!dau_valid_dest('relative/path'));
});

t('plugin self entry cannot be configured', function () {
  $c = dau_sanitize_config(['plugin_items' => [DAU_SELF_PLG => ['policy' => 'auto'], 'x.plg' => ['policy' => 'auto'], '../evil.plg' => ['policy' => 'auto']]], dau_defaults());
  ok(!isset($c['plugins']['items'][DAU_SELF_PLG])); eq($c['plugins']['items']['x.plg']['policy'], 'auto'); ok(!isset($c['plugins']['items']['../evil.plg']));
});

t('secrets stay out of config and export', function () {
  $url = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJ-0123';
  ok(dau_webhook_valid($url));
  dau_secret_set('discord_url', $url);
  dau_save_config(dau_load_config());
  lacks(file_get_contents(DAU_CFG), 'webhooks'); lacks(json_encode(dau_export_config()), 'abcdefghij');
  eq(dau_secret_get('discord_url'), $url);
});

t('webhook URL validation rejects other hosts and schemes', function () {
  foreach (['http://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz', 'https://evil.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
            'https://discord.com.evil.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz', 'https://discord.com/api/webhooks/12/abc',
            'https://169.254.169.254/latest', 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz/../../x', ''] as $u) ok(!dau_webhook_valid($u), "should reject $u");
  ok(dau_webhook_valid('https://ptb.discord.com/api/v10/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz'));
});

echo "== schedule ==\n";

function ts($s) { return (new DateTime($s, new DateTimeZone('UTC')))->getTimestamp(); }

t('daily/weekly/monthly occurrences', function () {
  $d = ['frequency' => 'daily', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1];
  eq(dau_occ_before($d, ts('2026-10-28 05:00')), ts('2026-10-28 04:00'));
  eq(dau_occ_before($d, ts('2026-10-28 03:00')), ts('2026-10-27 04:00'));
  eq(dau_occ_after($d, ts('2026-10-28 03:00')), ts('2026-10-28 04:00'));
  eq(dau_occ_after($d, ts('2026-10-28 04:00')), ts('2026-10-29 04:00'));
  $w = ['frequency' => 'weekly', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1];     // Sunday; 2026-11-01 is a Sunday
  eq(dau_occ_before($w, ts('2026-10-28 12:00')), ts('2026-10-25 04:00'));
  eq(dau_occ_after($w, ts('2026-10-28 12:00')), ts('2026-11-01 04:00'));
  $m = ['frequency' => 'monthly', 'time' => '03:00', 'weekday' => 0, 'monthday' => 5];
  eq(dau_occ_before($m, ts('2026-10-28 12:00')), ts('2026-10-05 03:00'));
  eq(dau_occ_before($m, ts('2026-10-02 12:00')), ts('2026-09-05 03:00'));
  eq(dau_occ_after($m, ts('2026-10-28 12:00')), ts('2026-11-05 03:00'));
  eq(dau_occ_after($m, ts('2026-12-28 12:00')), ts('2027-01-05 03:00'), 'year rollover');
  eq(dau_occ_before($m, ts('2027-01-02 12:00')), ts('2026-12-05 03:00'), 'year rollover back');
  $h = ['frequency' => '6h', 'time' => '04:15', 'weekday' => 0, 'monthday' => 1];
  eq(dau_occ_before($h, ts('2026-10-28 11:00')), ts('2026-10-28 10:15'));
  eq(dau_occ_after($h, ts('2026-10-28 11:00')), ts('2026-10-28 16:15'));
});

t('due logic: catch-up, no retro-run for new slots, bi-weekly gate', function () {
  $d = ['frequency' => 'daily', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1];
  $now = ts('2026-10-28 04:07');
  ok(dau_slot_due($d, ['seen' => ts('2026-10-20 00:00'), 'last' => ts('2026-10-27 04:00')], $now), 'due after the occurrence');
  ok(!dau_slot_due($d, ['seen' => ts('2026-10-20 00:00'), 'last' => ts('2026-10-28 04:00')], $now), 'not twice');
  ok(!dau_slot_due($d, ['seen' => ts('2026-10-28 03:00'), 'last' => 0], ts('2026-10-28 03:30')), 'before the time');
  ok(!dau_slot_due($d, ['seen' => ts('2026-10-28 04:03'), 'last' => 0], $now), 'slot created after the occurrence does not run retroactively');
  ok(dau_slot_due($d, ['seen' => ts('2026-10-20 00:00'), 'last' => ts('2026-10-27 04:00')], ts('2026-10-28 09:59')), 'still inside catch-up');
  ok(!dau_slot_due($d, ['seen' => ts('2026-10-20 00:00'), 'last' => ts('2026-10-27 04:00')], ts('2026-10-28 10:01')), 'missed too long ago');
  $b = ['frequency' => 'biweekly', 'time' => '04:00', 'weekday' => 0, 'monthday' => 1];
  ok(!dau_slot_due($b, ['seen' => 1, 'last' => ts('2026-10-25 04:00')], ts('2026-11-01 04:05')), 'off week');
  ok(dau_slot_due($b, ['seen' => 1, 'last' => ts('2026-10-18 04:00')], ts('2026-11-01 04:05')), 'on week');
  eq(dau_next_run($b, ['seen' => 1, 'last' => ts('2026-10-25 04:00')], ts('2026-10-26 00:00')), ts('2026-11-08 04:00'), 'bi-weekly next run skips the off week');
});

t('maintenance window incl. overnight and day-of-week', function () {
  $w = ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '02:00', 'end' => '06:00'];
  ok(dau_window_open($w, ts('2026-10-28 03:00'))); ok(!dau_window_open($w, ts('2026-10-28 06:00'))); ok(!dau_window_open($w, ts('2026-10-28 01:59')));
  ok(dau_window_open(['enabled' => false] + $w, ts('2026-10-28 12:00')), 'disabled window = always open');
  $o = ['enabled' => true, 'days' => [5], 'start' => '22:00', 'end' => '04:00'];      // Fridays; 2026-10-30 is a Friday
  ok(dau_window_open($o, ts('2026-10-30 23:00'))); ok(dau_window_open($o, ts('2026-10-31 03:00')), 'after midnight belongs to Friday window');
  ok(!dau_window_open($o, ts('2026-10-31 23:00')), 'Saturday night is not in a Friday-only window'); ok(!dau_window_open($o, ts('2026-10-30 12:00')));
  eq(dau_window_next_open($o, ts('2026-10-30 12:00')), ts('2026-10-30 22:00'));
  eq(dau_window_next_open($o, ts('2026-10-30 23:00')), ts('2026-10-30 23:00'), 'already open');
});

echo "== run helper / redaction / log ==\n";

t('dau_run: output, exit code, timeout kill, no shell', function () {
  $r = dau_run(['/bin/echo', 'hello; echo injected'], 5);
  eq(trim($r['out']), 'hello; echo injected', 'arguments are not interpreted by a shell'); eq($r['code'], 0);
  $r = dau_run(['/bin/sh', '-c', 'echo err >&2; exit 3'], 5); eq($r['code'], 3); eq(trim($r['err']), 'err');
  $t = microtime(true); $r = dau_run(['/bin/sleep', '30'], 1); ok($r['timeout'] && microtime(true) - $t < 5, 'killed on timeout'); eq($r['code'], 124);
  $r = dau_run(['/bin/sh', '-c', 'head -c 3000000 /dev/zero | tr "\\0" "a"'], 10, null, 100000); ok(strlen($r['out']) < 400000, 'output capped');
  $r = dau_run(['/nonexistent/binary'], 5); ok(!$r['ran'] || $r['code'] !== 0, 'missing binary reported');
});

t('redaction removes secrets', function () {
  $s = dau_redact('failed https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz POST; password=hunter2 token: abc123 API_KEY="xyz" https://user:pw@host/x Bearer eyJhbGciOiJIUzI1NiJ9abcdefghijklmnopqrstuvwxyz0123456789ABCDEF');
  foreach (['hunter2', 'abc123', 'xyz"', 'user:pw', 'abcdefghijklmnopqrstuvwxyz', 'eyJhbGci'] as $secret) lacks($s, $secret);
  has($s, '[discord-webhook]');
  lacks(dau_redact('custom secret value', ['custom secret value']), 'custom secret');
});

t('log is structured, redacted and bounded', function () {
  $GLOBALS['DAU_LOG_MAX'] = 2000;
  dau_log('info', 'docker', 'plex', 'update', 'ok', 'done token=SECRETVALUE', 1);
  $l = file_get_contents(DAU_LOG);
  has($l, 'level=info'); has($l, 'type=docker'); has($l, 'name=plex'); has($l, 'action=update'); has($l, 'result=ok'); has($l, 'retry=1'); lacks($l, 'SECRETVALUE');
  for ($i = 0; $i < 100; $i++) dau_log('info', 'job', '-', 'x', 'ok', str_repeat('y', 100));
  ok(filesize(DAU_LOG) <= 2600 && is_file(DAU_LOG . '.1'), 'rotated');
  ok(filesize(DAU_LOG . '.1') <= 2600);
  unset($GLOBALS['DAU_LOG_MAX']);
});

t('lock prevents overlapping jobs', function () {
  $a = dau_lock_try(); ok($a !== false);
  eq(dau_lock_try(), false, 'second lock refused'); ok(dau_is_locked());
  dau_lock_release($a); ok(!dau_is_locked());
});

t('state: slots start counting now; stats and history are bounded', function () {
  $st = dau_state_load(); $now = dau_now();
  ok(dau_slots_register($st, ['global', 'c1'], $now)); eq($st['slots']['c1']['seen'], $now);
  ok(!dau_slots_register($st, ['global', 'c1'], $now + 5), 'idempotent');
  dau_slots_register($st, ['global'], $now); ok(!isset($st['slots']['c1']), 'removed schedules are forgotten');
  for ($i = 0; $i < 30; $i++) dau_history_add(['type' => 'docker', 'name' => "c$i", 'result' => 'updated', 'started' => $now, 'finished' => $now + 5], 25);
  eq(count(dau_history_load()), 25, 'history capped'); eq(dau_history_load()[0]['name'], 'c29', 'newest first');
  dau_stats_record($st, 'docker', true, 20); dau_stats_record($st, 'docker', true, 40); dau_stats_record($st, 'docker', false, 99);
  $s = dau_stats_summary($st); eq($s['total_ok'], 2); eq($s['total_failed'], 1); eq($s['avg_duration'], 30);
});

t('v1 history entries are readable', function () {
  dau_write_json(DAU_HIST, [['container' => 'plex', 'image' => 'x', 'result' => 'updated', 'message' => 'm', 'trigger' => 'scheduled', 'ts' => 1790000000]]);
  $h = dau_history_load(); eq($h[0]['name'], 'plex'); eq($h[0]['type'], 'docker'); eq($h[0]['result'], 'updated');
});

echo "== discord ==\n";

function dc_setup($overrides = []) {
  $url = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz_SECRETTOKEN-0123';
  dau_secret_set('discord_url', $url);
  $cfg = dau_defaults(); $cfg['discord']['enabled'] = true;
  foreach ($overrides as $k => $v) $cfg['discord'][$k] = $v;
  $GLOBALS['SENT'] = [];
  $GLOBALS['DAU_HTTP_MOCK'] = function ($u, $body) { $GLOBALS['SENT'][] = ['url' => $u, 'body' => json_decode($body, true)]; return ['code' => 204]; };
  return [$cfg, $url];
}
function ev($kind, $type = 'docker', $name = 'plex', $extra = []) { return ['type' => $type, 'name' => $name, 'kind' => $kind, 'ts' => dau_now()] + $extra; }

t('discord: valid webhook sends one combined message with summary first', function () {
  list($cfg, $url) = dc_setup(); $st = dau_state_load();
  $sum = ['trigger' => 'scheduled', 'docker' => ['updated' => 4, 'failed' => 1, 'skipped' => 2], 'plugins' => ['updated' => 2, 'failed' => 0, 'skipped' => 1], 'os' => ['available' => true, 'version' => '7.3.5'], 'status' => 'warnings'];
  $r = dau_discord_dispatch($cfg, $st, [ev('updated'), ev('failed', 'docker', 'radarr', ['error' => 'boom', 'stage' => 'pull'])], $sum);
  eq($r['messages'], 1); eq(count($GLOBALS['SENT']), 1); eq($GLOBALS['SENT'][0]['url'], $url);
  $e = $GLOBALS['SENT'][0]['body']['embeds'];
  eq(count($e), 3); has($e[0]['title'], 'UPDATE SUMMARY'); has($e[0]['description'], 'Updated: 4'); has($e[0]['description'], 'Completed with warnings'); has($e[0]['description'], 'Installed automatically: No');
  has($e[1]['title'], 'Update failed', 'failures sorted before successes'); has($e[2]['title'], 'Update successful');
  eq($GLOBALS['SENT'][0]['body']['allowed_mentions'], ['parse' => []], 'mentions disabled');
});

t('discord: per-type and per-category toggles', function () {
  list($cfg) = dc_setup(['types' => ['success' => false, 'failed' => true, 'available' => false, 'summary' => false], 'events' => ['docker' => true, 'plugins' => false, 'os' => true]]); $st = dau_state_load();
  dau_discord_dispatch($cfg, $st, [ev('updated'), ev('available'), ev('failed', 'plugin', 'x.plg'), ev('failed', 'docker', 'radarr'), ev('os_check_failed', 'os', 'Unraid OS')], ['docker' => ['updated' => 1]]);
  $titles = array_map(function ($e) { return $e['title']; }, $GLOBALS['SENT'][0]['body']['embeds']);
  eq(count($titles), 2, 'only failed docker + failed os'); has(implode('|', $titles), 'radarr'); has(implode('|', $titles), 'OS update check failed');
  list($cfg2) = dc_setup(['enabled' => false]); $GLOBALS['SENT'] = []; dau_discord_dispatch($cfg2, $st, [ev('failed')], []); eq(count($GLOBALS['SENT']), 0, 'disabled = silent');
});

t('discord: silent (per-container notify off) events are not sent', function () {
  list($cfg) = dc_setup(); $st = dau_state_load();
  dau_discord_dispatch($cfg, $st, [ev('failed', 'docker', 'quiet', ['silent' => true])], []); eq(count($GLOBALS['SENT']), 0);
});

t('discord: embeds never exceed Discord limits; long summaries are chunked and capped', function () {
  list($cfg) = dc_setup(['max_messages' => 2]); $st = dau_state_load(); $evs = [];
  for ($i = 0; $i < 40; $i++) $evs[] = ev('failed', 'docker', "container-$i", ['error' => str_repeat('E', 3000), 'log' => str_repeat('L', 3000), 'reason' => str_repeat('R', 3000), 'next' => str_repeat('N', 3000)]);
  dau_discord_dispatch($cfg, $st, $evs, ['docker' => ['failed' => 40]]);
  eq(count($GLOBALS['SENT']), 2, 'capped at max_messages');
  foreach ($GLOBALS['SENT'] as $m) {
    ok(count($m['body']['embeds']) <= 10, '<=10 embeds'); $tot = 0;
    foreach ($m['body']['embeds'] as $e) { $s = dau_embed_size($e); $tot += $s; ok(strlen($e['title']) <= 256 + 8); ok(count($e['fields'] ?? []) <= 25);
      foreach (($e['fields'] ?? []) as $f) { ok(strlen($f['value']) <= 1024 + 3, 'field value limit'); ok(strlen($f['name']) <= 256 + 3); } }
    ok($tot <= 6000, "message total $tot <= 6000");
  }
  has($GLOBALS['SENT'][1]['body']['content'] ?? '', 'more notification');
});

t('discord: separate mode sends one embed per message', function () {
  list($cfg) = dc_setup(['batching' => 'separate', 'max_messages' => 10]); $st = dau_state_load();
  dau_discord_dispatch($cfg, $st, [ev('updated'), ev('updated', 'docker', 'b')], []); eq(count($GLOBALS['SENT']), 2);
});

t('discord: rate limit is honoured, then retried', function () {
  list($cfg) = dc_setup(); $st = dau_state_load(); $n = 0;
  $GLOBALS['DAU_HTTP_MOCK'] = function ($u, $b) use (&$n) { $n++; return $n === 1 ? ['code' => 429, 'body' => '{"retry_after":1.5,"global":false}'] : ['code' => 204]; };
  $r = dau_discord_dispatch($cfg, $st, [ev('updated')], []);
  eq($r['messages'], 1); eq($n, 2); ok($GLOBALS['DAU_SLEPT'] >= 1.5, 'waited for retry_after');
  $n = 0; $GLOBALS['DAU_HTTP_MOCK'] = function () use (&$n) { $n++; return ['code' => 429, 'body' => '{"retry_after":120}']; };
  $r = dau_discord_dispatch($cfg, $st, [ev('updated')], []); eq($r['messages'], 0); eq($n, 1, 'gives up when asked to wait too long'); has($r['error'], 'rate limited');
});

t('discord: transient failures retry a bounded number of times', function () {
  list($cfg) = dc_setup(); $st = dau_state_load(); $n = 0;
  $GLOBALS['DAU_HTTP_MOCK'] = function () use (&$n) { $n++; return ['code' => 0, 'error' => 'timeout']; };
  $r = dau_discord_dispatch($cfg, $st, [ev('updated')], []); eq($r['messages'], 0); eq($n, 3, 'initial try + 2 retries'); has($r['error'], 'network error');
  eq($st['discord']['invalid'], false, 'a network error does not invalidate the webhook');
  $n = 0; $GLOBALS['DAU_HTTP_MOCK'] = function () use (&$n) { $n++; return $n < 3 ? ['code' => 503] : ['code' => 204]; };
  eq(dau_discord_dispatch($cfg, $st, [ev('updated')], [])['messages'], 1);
});

t('discord: invalid webhook is marked invalid and not hammered', function () {
  list($cfg) = dc_setup(); $st = dau_state_load(); $n = 0;
  $GLOBALS['DAU_HTTP_MOCK'] = function () use (&$n) { $n++; return ['code' => 404, 'body' => '{"message":"Unknown Webhook","code":10015}']; };
  $r = dau_discord_dispatch($cfg, $st, [ev('updated')], []); eq($n, 1); eq($st['discord']['invalid'], true); has($r['error'], 'rejected');
  dau_discord_dispatch($cfg, $st, [ev('updated')], []); eq($n, 1, 'no further requests while invalid');
  $GLOBALS['DAU_HTTP_MOCK'] = function () { return ['code' => 204]; };
  $r = dau_discord_test($st); ok($r['ok']); eq($st['discord']['invalid'], false, 'successful test clears the flag');
});

t('discord: no URL / bad URL never causes a request; secrets never appear in payload or log', function () {
  list($cfg, $url) = dc_setup(); $st = dau_state_load();
  dau_secret_set('discord_url', ''); dau_discord_dispatch($cfg, $st, [ev('failed')], []); eq(count($GLOBALS['SENT']), 0);
  $r = dau_discord_test($st, 'https://evil.example/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz'); ok(!$r['ok']); eq(count($GLOBALS['SENT']), 0, 'never contacts a non-Discord host');
  dau_secret_set('discord_url', $url);
  dau_discord_dispatch($cfg, $st, [ev('failed', 'docker', 'x', ['error' => "pull failed for $url with password=hunter2 and token=abcdefabcdefabcdefabcdefabcdefabcdefabcdef", 'log' => "POST $url"])], []);
  $body = json_encode($GLOBALS['SENT'][0]['body']);
  foreach (['SECRETTOKEN', 'hunter2', 'abcdefabcdef'] as $s) lacks($body, $s, 'payload'); has($body, '[discord-webhook]');
  $GLOBALS['DAU_HTTP_MOCK'] = function () { return ['code' => 500, 'body' => 'x']; }; dau_discord_dispatch($cfg, $st, [ev('failed')], []);
  lacks(is_file(DAU_LOG) ? file_get_contents(DAU_LOG) : '', 'SECRETTOKEN', 'log');
});

t('discord: summary only when there was activity', function () {
  list($cfg) = dc_setup(); $st = dau_state_load();
  dau_discord_dispatch($cfg, $st, [], ['docker' => ['updated' => 0, 'failed' => 0, 'skipped' => 0]]); eq(count($GLOBALS['SENT']), 0, 'quiet runs send nothing');
});

echo "== php 7.4 compatibility (static) ==\n";
t('no PHP 8-only syntax or functions in src/', function () {
  $bad = ['/\bstr_contains\s*\(/', '/\bstr_starts_with\s*\(/', '/\bstr_ends_with\s*\(/', '/\bmatch\s*\(/', '/\?->/', '/\barray_is_list\s*\(/', '/\benum\s+\w+/', '/\breadonly\b/', '/\bfn\s*\(.*\)\s*:\s*\w+\s*=>/', '/\bmixed\b\s+\$/', '/\bpublic\s+function\s+__construct\s*\(\s*(public|private|protected)/'];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src/include')); $it2 = glob(__DIR__ . '/../src/scripts/*.php');
  $files = $it2; foreach ($it as $f) if (substr($f, -4) === '.php') $files[] = (string)$f;
  foreach ($files as $f) { $code = preg_replace('#//.*|/\*.*?\*/|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'|"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"#s', '', file_get_contents($f));
    foreach ($bad as $re) ok(!preg_match($re, $code), basename($f) . " uses PHP8-only construct $re"); }
});
