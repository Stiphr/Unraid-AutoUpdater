<?php
/* Plugin branch (stable / beta) and force update. Everything mocked; nothing is downloaded or installed. */

function chplg($ch) { @mkdir(DAU_PLUGBOOT, 0755, true); file_put_contents(DAU_PLUGBOOT . '/unraid-autoupdater.plg', "<?xml version='1.0' standalone='yes'?>\n<!DOCTYPE PLUGIN [\n<!ENTITY name \"unraid-autoupdater\">\n<!ENTITY pluginURL \"" . dau_channel_url($ch) . "\">\n]>\n<PLUGIN name=\"&name;\" version=\"1\"></PLUGIN>\n"); }

t('channel: current branch is read from the installed plugin file; only two fixed addresses exist', function () {
  chplg('stable'); eq(dau_channel_current(), 'stable'); chplg('beta'); eq(dau_channel_current(), 'beta');
  eq(dau_channel_url('stable'), 'https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/refs/heads/stable/unraid-autoupdater.plg');
  eq(dau_channel_url('beta'), 'https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/refs/heads/beta/unraid-autoupdater.plg');
  eq(dau_channel_url('../evil'), ''); eq(dau_channel_install('http://evil/x.plg')['ok'], false);
});

t('channel: switching installs exactly that branch; the same branch needs Force', function () {
  chplg('stable'); mock_exec(function ($a) { return null; });
  $r = dau_channel_install('beta'); ok($r['ok']);
  $c = calls_matching(' install '); eq(count($c), 1); eq(end($c[0]), dau_channel_url('beta')); ok(strpos($c[0][0], 'plugin') !== false);
  $GLOBALS['CALLS'] = []; $r = dau_channel_install('stable'); eq($r['ok'], false); has($r['error'], 'Force update'); eq(calls_matching(' install '), [], 'nothing was run');
  $r = dau_channel_install('stable', true); ok($r['ok']); eq(end(calls_matching(' install ')[0]), dau_channel_url('stable'), 'force reinstalls the same branch');
  $h = dau_history_load(); eq($h[0]['action'], 'channel'); eq($h[0]['result'], 'updated'); has($h[0]['message'], 'orce update'); has($h[1]['message'], 'table -> beta');
});

t('channel: a failed install is reported and recorded; a running job blocks it', function () {
  chplg('beta'); mock_exec(function ($a) { return strpos(implode(' ', $a), ' install ') !== false ? ['code' => 1, 'err' => 'download failed https://x?token=abc123456789'] : null; });
  $r = dau_channel_install('stable'); eq($r['ok'], false); has($r['error'], 'download failed'); eq(dau_history_load()[0]['result'], 'failed');
  $lock = dau_lock_try(); $GLOBALS['CALLS'] = []; $r = dau_channel_install('stable'); eq($r['ok'], false); has($r['error'], 'Another job'); eq(calls_matching(' install '), []); dau_lock_release($lock);
});

t('channel: versions offered on each branch are read from the plugin files', function () {
  $plg = function ($v) { return "<?xml version='1.0'?><PLUGIN name=\"unraid-autoupdater\" version=\"$v\"></PLUGIN>"; };
  mock_exec(function ($a) use ($plg) { $u = end($a); if (strpos($u, '/stable/') !== false) return ['out' => $plg('2026.10.30a')]; if (strpos($u, '/beta/') !== false) return ['out' => $plg('2026.10.31b3')]; return null; });
  eq(dau_channel_versions(), ['stable' => '2026.10.30a', 'beta' => '2026.10.31b3']);
  mock_exec(function ($a) { return ['code' => 22]; }); eq(dau_channel_versions(), ['stable' => null, 'beta' => null]);
  mock_exec(function ($a) { return ['out' => '<PLUGIN version="bad version!"></PLUGIN>']; }); eq(dau_channel_versions()['stable'], null);
});
