<?php
/* API tests: api.php is run as a real subprocess (no docker/unraid present, so Docker reads are "not running"). */

function api_call($action, array $post = [], $method = 'POST', $csrf = 'tok') {
  @mkdir('/usr/local/emhttp/plugins', 0755, true);
  if (!is_link('/usr/local/emhttp/plugins/unraid-autoupdater')) @symlink(realpath(__DIR__ . '/../src'), '/usr/local/emhttp/plugins/unraid-autoupdater');
  file_put_contents(DAU_VARINI, "NAME=\"Tower\"\ncsrf_token=\"tok\"\n");
  $pre = sys_get_temp_dir() . '/dau-api-pre.php';
  file_put_contents($pre, '<?php $_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . '; $_POST=' . var_export(['action' => $action] + ($csrf !== null ? ['csrf_token' => $csrf] : []) + $post, true) . ';');
  $out = shell_exec('php -d auto_prepend_file=' . escapeshellarg($pre) . ' ' . escapeshellarg(__DIR__ . '/../src/include/api.php') . ' 2>/dev/null');
  return json_decode((string)$out, true) ?: ['_raw' => $out];
}

t('api: changes need POST', function () {
  has(api_call('save_config', ['config' => '{}'], 'GET')['error'] ?? '', 'POST');
  ok(isset(api_call('stop', [], 'POST', 'tok')['ok']));
  /* the token itself is validated by Unraid's auto-prepended local_prepend.php (it also strips it), not by this API */
});

t('api: save_config validates and never returns the webhook; secrets are separate', function () {
  $url = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyzABCDEF0123456789';
  $r = api_call('save_config', ['config' => json_encode(['global' => ['enabled' => true, 'update_delay_hours' => 99999, 'retry' => ['max' => 50]], 'discord' => ['enabled' => true, 'webhook_url' => $url]])]);
  ok(!empty($r['ok']), json_encode($r));
  $cfg = dau_load_config();
  eq($cfg['global']['update_delay_hours'], 720, 'clamped'); eq($cfg['global']['retry']['max'], 5);
  lacks(file_get_contents(DAU_CFG), 'abcdefghijkl', 'webhook not in config.json'); has(file_get_contents(DAU_SECRETS), 'abcdefghijkl');
  $ov = api_call('overview'); lacks(json_encode($ov), 'abcdefghijkl'); eq($ov['config']['discord']['webhook_set'], true);
  eq(api_call('export')['config']['global']['enabled'] ?? null, true);
  lacks(json_encode(api_call('export')), 'abcdefghijkl');
  has(api_call('save_config', ['config' => json_encode(['discord' => ['webhook_url' => 'https://evil.example.com/x']])])['error'] ?? '', 'valid Discord');
  ok(is_file(DAU_CRON), 'dispatcher scheduled');
});

t('api: container and plugin settings are sanitised', function () {
  api_call('set_container', ['names' => json_encode(['web', '../etc', 'x y']), 'data' => json_encode(['policy' => 'auto', 'delay_hours' => -5, 'schedule' => ['frequency' => 'hourly']])]);
  $c = dau_load_config(); ok(isset($c['containers']['web'])); eq(count($c['containers']), 1);
  eq($c['containers']['web']['policy'], 'auto'); ok($c['containers']['web']['delay_hours'] === null || $c['containers']['web']['delay_hours'] >= 0);
  api_call('set_plugin', ['files' => json_encode(['a.plg', 'unraid-autoupdater.plg', '../x.plg']), 'data' => json_encode(['policy' => 'auto'])]);
  $c = dau_load_config(); eq(array_keys($c['plugins']['items']), ['a.plg']);
  has(api_call('run', ['what' => 'docker', 'names' => json_encode([])])['error'] ?? '', 'Nothing');
  has(api_call('run', ['what' => 'rm -rf'])['error'] ?? '', 'Unknown');
  has(api_call('nope')['error'] ?? '', 'Unknown action');      // a non-empty needle: PHP 7.4 strpos() never matches ''
});

t('api: import accepts an export file and keeps a copy of the old settings', function () {
  api_call('save_config', ['config' => json_encode(['global' => ['skip_stopped' => true]])]);
  $exp = api_call('export');
  $r = api_call('import', ['config' => json_encode($exp)]); ok(!empty($r['ok']));
  ok(is_file(DAU_DIR . '/config.before-import.json'));
  has(api_call('import', ['config' => 'not json'])['error'] ?? '', 'Invalid');
});

t('cleanup api test helper files', function () { @unlink('/usr/local/emhttp/plugins/unraid-autoupdater'); @unlink(sys_get_temp_dir() . '/dau-api-pre.php'); ok(true); });
