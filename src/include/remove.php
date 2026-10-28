<?php
/*
 * Unraid Swiss Manager - removing containers and plugins (only ever on an explicit request from the web UI, never scheduled).
 *
 * Container: stop (if running) -> docker rm (never forced) -> optionally its image (never forced, so Docker keeps an image another
 * container still uses) -> optionally archive its saved template (restorable from Cleanup). Appdata folders are never touched here:
 * once the container is gone, Cleanup offers them with its quarantine. A container that other containers use as their network
 * ("--net=container:NAME") is refused, because removing it would cut them off.
 * Plugin: Unraid's own `plugin remove NAME.plg`, verified afterwards. This plugin and Unraid itself are never removed from here.
 */

/** Containers whose network mode is "container:<name or id>" of this one. */
function dau_rm_net_dependants($name, $id) {
  $out = [];
  foreach (dau_inspect_all() ?? [] as $c) {
    $n = ltrim((string)($c['Name'] ?? ''), '/'); $mode = (string)($c['HostConfig']['NetworkMode'] ?? '');
    if ($n === $name || strpos($mode, 'container:') !== 0) continue;
    $t = substr($mode, 10);
    if ($t === $name || ($id !== '' && ($t === $id || (strlen($t) >= 12 && strpos($id, $t) === 0)))) $out[] = $n;
  }
  return $out;
}

/** `docker inspect` of every container (any state). null = Docker not available. */
function dau_inspect_all() {
  $r = dau_docker(['ps', '-aq', '--no-trunc'], 30);
  if ($r['code'] !== 0) return null;
  $ids = array_values(array_filter(preg_split('/\s+/', trim($r['out']))));
  if (!$ids) return [];
  $r = dau_docker(array_merge(['inspect'], $ids), 90);
  $d = json_decode($r['out'], true);
  return is_array($d) ? $d : null;
}

/**
 * Remove one container. $o: image (bool) also remove its image, template (bool) archive its saved template.
 * @return array result: removed | failed | blocked, plus what happened to the image, template and appdata.
 */
function dau_docker_remove($name, array $o) {
  $res = ['result' => 'failed', 'stage' => 'precheck', 'error' => '', 'next' => '', 'image' => '', 'template' => '', 'appdata' => [], 'started' => dau_now(), 'finished' => 0,
          'prev' => null, 'new' => null, 'verification' => null];
  $done = function () use (&$res) { $res['finished'] = dau_now(); return $res; };
  if (!dau_valid_name($name)) { $res['result'] = 'blocked'; $res['error'] = 'Invalid container name.'; return $done(); }
  $i = dau_inspect_one($name);
  if ($i === null) { $res['result'] = 'blocked'; $res['error'] = 'The container does not exist (any more).'; return $done(); }
  $id = (string)($i['Id'] ?? ''); $imageId = (string)($i['Image'] ?? ''); $image = (string)($i['Config']['Image'] ?? '');
  $res['prev'] = ['id' => dau_short_id($imageId), 'digest' => $image];
  foreach ($i['Mounts'] ?? [] as $m) if (($m['Type'] ?? '') === 'bind' && strpos((string)($m['Source'] ?? ''), '/mnt/') === 0) $res['appdata'][] = (string)$m['Source'];
  $deps = dau_rm_net_dependants($name, $id);
  if ($deps) { $res['result'] = 'blocked'; $res['error'] = 'Other containers use its network: ' . implode(', ', $deps) . '. Remove or change those first.'; return $done(); }

  if (!empty($i['State']['Running']) || in_array($i['State']['Status'] ?? '', ['running', 'restarting', 'paused'], true)) {
    $res['stage'] = 'stop';
    dau_phase('Stopping ' . $name, ['current' => $name]);
    $r = dau_docker(['stop', '-t', '30', $name], 90);
    if ($r['code'] !== 0 || $r['timeout']) { $res['error'] = 'The container could not be stopped: ' . dau_clip(dau_redact(trim($r['err'])), 160); $res['next'] = 'Nothing was removed.'; return $done(); }
  }
  $res['stage'] = 'remove';
  dau_phase('Removing ' . $name, ['current' => $name]);
  $r = dau_docker(['rm', $name], 60);
  if ($r['code'] !== 0 || dau_inspect_one($name) !== null) {
    $res['error'] = 'Docker did not remove the container: ' . dau_clip(dau_redact(trim($r['err'])), 160);
    $res['next'] = 'It was stopped but not removed. Start it again on the Docker tab if you need it.';
    return $done();
  }
  $res['result'] = 'removed'; $res['stage'] = '';
  if (!empty($o['image']) && $imageId !== '') {
    $r = dau_docker(['image', 'rm', $imageId], 120);
    $res['image'] = $r['code'] === 0 ? 'removed' : 'kept (another container still uses it)';
  }
  if (!empty($o['template']) && is_file(DAU_TEMPLATES . "/my-$name.xml")) {
    $t = dau_tpl_act('archive', $name);
    $res['template'] = !empty($t['ok']) ? 'archived' : 'kept (' . ($t['error'] ?? 'not archived') . ')';
  } elseif (is_file(DAU_TEMPLATES . "/my-$name.xml")) $res['template'] = 'kept';
  if ($res['appdata']) $res['next'] = 'Its folders were not touched (' . implode(', ', array_slice($res['appdata'], 0, 3)) . (count($res['appdata']) > 3 ? ', …' : '') . '). Cleanup can move unused ones to quarantine.';
  return $done();
}

/** Remove one plugin with Unraid's own tool. Never this plugin, never Unraid itself. */
function dau_plugin_remove($file) {
  $res = ['result' => 'failed', 'stage' => 'precheck', 'error' => '', 'next' => '', 'started' => dau_now(), 'finished' => 0, 'prev' => null, 'new' => null, 'verification' => null];
  $done = function () use (&$res) { $res['finished'] = dau_now(); return $res; };
  if (!dau_valid_plg($file)) { $res['result'] = 'blocked'; $res['error'] = 'Invalid plugin file name.'; return $done(); }
  if ($file === DAU_SELF_PLG) { $res['result'] = 'blocked'; $res['error'] = 'This plugin cannot remove itself. Use Plugins in Unraid.'; return $done(); }
  if ($file === 'unRAIDServer.plg' || strpos($file, 'unRAIDServer-') === 0) { $res['result'] = 'blocked'; $res['error'] = 'Unraid itself cannot be removed.'; return $done(); }
  $inst = dau_plugins_installed();
  if (!isset($inst[$file])) { $res['result'] = 'blocked'; $res['error'] = 'The plugin is not installed (any more).'; return $done(); }
  $res['prev'] = ['version' => $inst[$file]['version']];
  $res['stage'] = 'remove';
  dau_phase('Removing plugin ' . $inst[$file]['name'], ['current' => $inst[$file]['name']]);
  $r = dau_run([dau_plugin_bin(), 'remove', $file], 600);
  clearstatcache();
  if (is_link(DAU_PLUGLINKS . '/' . $file) || is_file(DAU_PLUGLINKS . '/' . $file)) {
    $res['error'] = $r['timeout'] ? 'Unraid\'s plugin tool did not finish in 10 minutes.' : 'Unraid still lists the plugin after the removal. ' . dau_clip(dau_redact(trim($r['err'] . ' ' . $r['out'])), 200);
    $res['next'] = 'Check Plugins in Unraid.';
    return $done();
  }
  $res['result'] = 'removed'; $res['stage'] = '';
  $res['next'] = 'Its settings folder on the flash drive (/boot/config/plugins/' . $inst[$file]['name'] . ') is left as the plugin\'s own removal leaves it.';
  return $done();
}

/* ---------- job ---------- */

/** $what: docker | plugins. Runs inside dau_job_main (holds the lock). */
function dau_job_remove(array &$J, $what, array $names, array $o) {
  $max = (int)$J['cfg']['history']['max_entries'];
  $sum = $what === 'docker' ? 'docker' : 'plugins'; $i = 0;
  foreach ($names as $n) {
    if (dau_stop_requested()) { dau_log('warn', 'job', '-', 'stop', 'info', 'Stopped on request; the rest was not removed.'); break; }
    dau_phase('Removing ' . $n, ['done' => $i++, 'total' => count($names), 'current' => $n]);
    $res = $what === 'docker' ? dau_docker_remove($n, $o) : dau_plugin_remove($n);
    $label = $what === 'docker' ? $n : preg_replace('/\.plg$/', '', $n);
    $parts = [];
    if (($res['image'] ?? '') !== '') $parts[] = 'image ' . $res['image'];
    if (($res['template'] ?? '') !== '') $parts[] = 'template ' . $res['template'];
    $msg = trim(($res['result'] === 'removed' && $parts ? ucfirst(implode(', ', $parts)) . '. ' : '') . $res['next']);
    dau_history_add(['type' => $what === 'docker' ? 'docker' : 'plugin', 'name' => $label, 'action' => 'remove', 'result' => $res['result'], 'started' => $res['started'], 'finished' => $res['finished'],
      'prev' => $res['prev'], 'new' => null, 'verification' => null, 'trigger' => $J['trigger'], 'error' => $res['error'], 'message' => $msg], $max);
    dau_log($res['result'] === 'removed' ? 'info' : ($res['result'] === 'blocked' ? 'warn' : 'error'), $what === 'docker' ? 'docker' : 'plugin', $label, 'remove', $res['result'], $res['error'] ?: $msg);
    if ($res['result'] === 'failed') $J['sum'][$sum]['failed']++; elseif ($res['result'] === 'blocked') $J['sum'][$sum]['skipped']++;
    $J['events'][] = dau_ev($what === 'docker' ? 'docker' : 'plugin', $label, $res['result'] === 'removed' ? 'removed' : 'remove_failed', ['error' => $res['error'], 'next' => $res['next'], 'silent' => $res['result'] === 'removed']);
  }
  if ($what === 'plugins') { $J['st']['plugins']['available'] = array_intersect_key($J['st']['plugins']['available'], dau_plugins_installed()); $J['st']['summary']['plugin_updates'] = count($J['st']['plugins']['available']); }
  dau_state_save($J['st']);
}
