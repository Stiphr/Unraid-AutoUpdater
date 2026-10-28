<?php
/*
 * Unraid Swiss Manager - Discord webhook notifications.
 *  - The URL is read from secrets.json only at send time; it is never logged, never returned by the API and is
 *    scrubbed from every message by dau_redact().
 *  - Only https://discord.com/api/webhooks/... style URLs are ever contacted (see dau_webhook_valid()).
 *  - Certificates are always verified. There is no option to turn that off.
 */

/* Default avatar: an original icon (orange glyph on grey) published in this repository. Replace it in Settings with any https image. */
const DAU_DEFAULT_ICON = 'https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/main/assets/icon.png';
const DAU_DISCORD_EMBEDS_PER_MSG = 10;
const DAU_DISCORD_CHARS_PER_MSG  = 5600;   // hard limit is 6000 characters across all embeds of a message

function dau_type_label($t) {
  return ['docker' => 'Docker container', 'plugin' => 'Unraid plugin', 'os' => 'Unraid OS', 'cleanup' => 'Cleanup', 'backup' => 'Backup'][$t] ?? $t;
}

/* ---------- transport ---------- */

/** POST JSON. Returns ['code'=>int(0 on network error),'headers'=>[lowercase=>value],'body'=>string,'error'=>string]. */
function dau_http_post($url, $body, $timeout = 10) {
  if (isset($GLOBALS['DAU_HTTP_MOCK']) && is_callable($GLOBALS['DAU_HTTP_MOCK'])) {
    $r = call_user_func($GLOBALS['DAU_HTTP_MOCK'], $url, $body);
    return $r + ['code' => 0, 'headers' => [], 'body' => '', 'error' => ''];
  }
  $res = ['code' => 0, 'headers' => [], 'body' => '', 'error' => ''];
  $ca = is_file('/etc/ssl/certs/ca-certificates.crt') ? '/etc/ssl/certs/ca-certificates.crt' : null;
  $ua = 'UnraidAutoUpdater/' . DAU_VERSION;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $opts = [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ' . $ua],
      CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$res) {
        $p = explode(':', $h, 2);
        if (count($p) === 2) $res['headers'][strtolower(trim($p[0]))] = trim($p[1]);
        return strlen($h);
      },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if ($ca) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $out = curl_exec($ch);
    $res['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($out === false) $res['error'] = curl_error($ch); else $res['body'] = (string)$out;
    curl_close($ch);
    return $res;
  }
  $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
  if ($ca) $ssl['cafile'] = $ca;
  $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
      'header' => "Content-Type: application/json\r\nUser-Agent: $ua\r\n", 'content' => $body], 'ssl' => $ssl]);
  $out = @file_get_contents($url, false, $ctx);
  foreach (($http_response_header ?? []) as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $res['code'] = (int)$m[1];
    elseif (strpos($h, ':') !== false) { $p = explode(':', $h, 2); $res['headers'][strtolower(trim($p[0]))] = trim($p[1]); }
  }
  if ($out === false && !$res['code']) $res['error'] = 'connection failed'; else $res['body'] = (string)$out;
  return $res;
}

/**
 * Send one webhook payload with bounded retries.
 * 429 -> wait the server-provided time (max 30 s) and retry; 5xx/network -> short backoff; other 4xx -> permanent.
 * Returns ['ok'=>bool,'code'=>int,'error'=>string,'permanent'=>bool].
 */
function dau_discord_post($url, array $payload) {
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  $backoff = [2, 5];
  $r = ['ok' => false, 'code' => 0, 'error' => 'not sent', 'permanent' => false];
  for ($try = 0; $try < 4; $try++) {
    $h = dau_http_post($url, $body);
    $code = (int)$h['code'];
    if ($code >= 200 && $code < 300) return ['ok' => true, 'code' => $code, 'error' => '', 'permanent' => false];
    if ($code === 429) {
      $j = json_decode((string)$h['body'], true);
      $wait = is_array($j) && isset($j['retry_after']) ? (float)$j['retry_after'] : (float)($h['headers']['retry-after'] ?? 2);
      $r = ['ok' => false, 'code' => 429, 'error' => 'rate limited by Discord', 'permanent' => false];
      if ($wait > 30 || $try >= 3) break;                 // do not stall a job for long; give up and log
      dau_sleep(max(0.5, $wait) + 0.2);
      continue;
    }
    if ($code === 0 || $code >= 500) {
      $r = ['ok' => false, 'code' => $code, 'error' => $code ? "Discord server error $code" : ('network error' . ($h['error'] !== '' ? ': ' . $h['error'] : '')), 'permanent' => false];
      if ($try < count($backoff)) { dau_sleep($backoff[$try]); continue; }
      break;
    }
    $j = json_decode((string)$h['body'], true);
    $detail = is_array($j) && isset($j['message']) ? ': ' . $j['message'] : '';
    $perm = in_array($code, [401, 403, 404], true);
    return ['ok' => false, 'code' => $code, 'permanent' => $perm,
            'error' => ($perm ? 'webhook rejected (' . $code . ')' : 'Discord refused the message (' . $code . ')') . dau_clip(dau_redact($detail), 160)];
  }
  return $r;
}

/* ---------- embeds ---------- */

function dau_embed_size(array $e) {
  $n = strlen($e['title'] ?? '') + strlen($e['description'] ?? '') + strlen($e['footer']['text'] ?? '') + strlen($e['author']['name'] ?? '');
  foreach (($e['fields'] ?? []) as $f) $n += strlen($f['name']) + strlen($f['value']);
  return $n;
}

function dau_field($name, $value, $inline = true) {
  $value = trim((string)$value);
  return ['name' => dau_clip($name, 256), 'value' => dau_clip($value === '' ? '-' : $value, 1024), 'inline' => $inline];
}

function dau_clean_text($s, $max) { return dau_clip(dau_redact((string)$s), $max); }

/** Icon used for the webhook avatar and the embed header: the configured https URL, else the bundled default. */
function dau_discord_icon(array $cfg = null) {
  $u = $cfg['discord']['icon_url'] ?? '';
  return dau_icon_url_valid($u) ? $u : DAU_DEFAULT_ICON;
}

/** Brand fields shared by every embed (author line with the icon, footer with the version). */
function dau_discord_brand(array $e, $extraFooter = '') {
  $icon = $GLOBALS['DAU_ICON'] ?? DAU_DEFAULT_ICON;
  $e['author'] = ['name' => DAU_NAME, 'icon_url' => $icon];
  $e['footer'] = ['text' => DAU_NAME . ' ' . DAU_VERSION . $extraFooter, 'icon_url' => $icon];
  return $e;
}

/** Embed for one event. Never includes secrets (values pass through dau_redact). */
function dau_discord_event_embed(array $ev, $server) {
  $kind = $ev['kind'];
  $style = [
    'available'       => ['🔔', 'Update available', 0xF1C40F],
    'updated'         => ['✅', 'Update successful', 0x2ECC71],
    'failed'          => ['❌', 'Update failed', 0xE74C3C],
    'retrying'        => ['🔁', 'Retry scheduled', 0xE67E22],
    'retry_exhausted' => ['❌', 'Retries exhausted', 0xE74C3C],
    'blocked'         => ['⚠️', 'Update not installed', 0xE67E22],
    'skipped'         => ['⚠️', 'Update skipped', 0xE67E22],
    'os_withdrawn'    => ['ℹ️', 'OS update no longer offered', 0x3498DB],
    'os_check_failed' => ['⚠️', 'OS update check failed', 0xE67E22],
    'os_installed'    => ['✅', 'Unraid OS updated', 0x2ECC71],
    'os_staged'       => ['📦', 'OS update installed, reboot needed', 0xF1C40F],
    'os_rebooting'    => ['🔄', 'Rebooting to finish the OS update', 0xE67E22],
    'os_reboot_blocked' => ['⚠️', 'Reboot postponed', 0xE67E22],
    'os_install_failed' => ['❌', 'OS update failed', 0xE74C3C],
    'os_workflow_started' => ['🧰', 'Pre-OS maintenance started', 0x3498DB],
    'os_workflow_done' => ['✅', 'Pre-OS checks passed', 0x2ECC71],
    'os_awaiting'     => ['⏸️', 'OS update waiting for your confirmation', 0xF1C40F],
    'os_prereq_failed' => ['❌', 'OS update stopped: a prerequisite failed', 0xE74C3C],
    'os_aborted'      => ['⏹️', 'OS update aborted', 0xE67E22],
    'os_verified'     => ['✅', 'OS update verified after reboot', 0x2ECC71],
    'os_unverified'   => ['❌', 'OS update not active after reboot', 0xE74C3C],
    'backup_done'     => ['💾', 'Backup completed', 0x2ECC71],
    'backup_failed'   => ['❌', 'Backup FAILED', 0xE74C3C],
    'cleanup_report'  => ['🧹', 'Cleanup: things you can free', 0x3498DB],
    'major_jump'      => ['🛑', 'Major version update held back', 0xE67E22],
    'rolled_back'     => ['⏪', 'Rolled back to the earlier image', 0xE67E22],
    'rollback_failed' => ['❌', 'Rollback failed', 0xE74C3C],
  ][$kind] ?? ['ℹ️', $kind, 0x95A5A6];
  $name = dau_clean_text($ev['name'] ?? '', 120);
  $f = [dau_field('Server', $server), dau_field('Component', dau_type_label($ev['type']))];
  if (!in_array($ev['type'], ['cleanup', 'backup'], true)) $f[] = dau_field(ucfirst($ev['type'] === 'os' ? 'System' : $ev['type']), $name);
  if (isset($ev['installed']) && $ev['installed'] !== '') $f[] = dau_field(in_array($kind, ['updated', 'os_installed', 'os_verified', 'os_staged', 'os_rebooting'], true) ? 'Previous' : 'Installed', dau_clean_text($ev['installed'], 200));
  if (isset($ev['available']) && $ev['available'] !== '') $f[] = dau_field(in_array($kind, ['updated', 'os_installed', 'os_verified', 'os_staged', 'os_rebooting'], true) ? 'New' : 'Available', dau_clean_text($ev['available'], 200));
  if (isset($ev['duration'])) $f[] = dau_field('Duration', dau_duration_text($ev['duration']));
  if (!empty($ev['versions'])) $f[] = dau_field('Version', dau_clean_text($ev['versions'], 120));
  if (!empty($ev['notes'])) $f[] = dau_field('What changed', "```\n" . str_replace('```', "'''", dau_clean_text($ev['notes'], 600)) . "\n```", false);
  if (!empty($ev['verification'])) $f[] = dau_field('Verification', dau_clean_text($ev['verification'], 300), false);
  if (!empty($ev['stage'])) $f[] = dau_field('Failed at', dau_clean_text($ev['stage'], 100));
  if (!empty($ev['reason'])) $f[] = dau_field($kind === 'available' ? 'Pending because' : 'Reason', dau_clean_text($ev['reason'], 500), false);
  if (!empty($ev['detail'])) $f[] = dau_field('Components', "```\n" . str_replace('```', "'''", dau_clean_text($ev['detail'], 700)) . "\n```", false);
  if (!empty($ev['error'])) $f[] = dau_field('Error', "```\n" . str_replace('```', "'''", dau_clean_text($ev['error'], 700)) . "\n```", false);
  if (!empty($ev['retry'])) $f[] = dau_field('Retry', dau_clean_text($ev['retry'], 200), false);
  if (!empty($ev['log'])) $f[] = dau_field('Log', "```\n" . str_replace('```', "'''", dau_clean_text($ev['log'], 600)) . "\n```", false);
  if (!empty($ev['next'])) $f[] = dau_field('Suggested next steps', dau_clean_text($ev['next'], 500), false);
  $links = dau_action_links($GLOBALS['DAU_CFG_NOW'] ?? dau_load_config(), $ev);
  if ($links !== '') $f[] = dau_field('Actions', $links, false);
  return dau_discord_brand([
    'title' => dau_clip($style[0] . ' ' . $style[1] . ' — ' . $name, 256),
    'color' => $style[2],
    'fields' => array_slice($f, 0, 25),
    'timestamp' => dau_ts_iso($ev['ts'] ?? dau_now()),
  ]);
}

function dau_duration_text($s) {
  $s = (int)$s;
  if ($s < 60) return $s . ' s';
  return floor($s / 60) . ' min ' . ($s % 60) . ' s';
}

/** Combined run summary, modelled on the example in the requirements. */
function dau_discord_summary_embed(array $sum, $server) {
  $lines = [];
  $row = function ($label, $a) {
    $parts = [];
    foreach (['updated' => 'Updated', 'failed' => 'Failed', 'skipped' => 'Skipped', 'available' => 'Update available'] as $k => $t) {
      if (!empty($a[$k]) || $k !== 'available') $parts[] = "$t: " . (int)($a[$k] ?? 0);
    }
    return "**$label**\n" . implode("\n", array_map(function ($p) { return '- ' . $p; }, $parts));
  };
  if (isset($sum['docker'])) $lines[] = $row('Docker', $sum['docker']);
  if (isset($sum['plugins'])) $lines[] = $row('Unraid Plugins', $sum['plugins']);
  if (isset($sum['os'])) {
    $o = $sum['os'];
    $lines[] = "**Unraid OS**\n- Update available: " . (($o['available'] ?? null) === null ? 'Unknown' : ($o['available'] ? 'Yes' . (!empty($o['version']) ? ' (' . dau_clean_text($o['version'], 40) . ')' : '') : 'No'))
             . "\n- Installed automatically: " . (!empty($o['installed']) ? 'Yes' : 'No');
  }
  $status = $sum['status'] ?? 'ok';
  $text = ['ok' => 'Completed', 'warnings' => 'Completed with warnings', 'failed' => 'Completed with failures'][$status] ?? 'Completed';
  $color = ['ok' => 0x2ECC71, 'warnings' => 0xF1C40F, 'failed' => 0xE74C3C][$status] ?? 0x3498DB;
  $lines[] = '**Status:** ' . $text;
  return dau_discord_brand([
    'title' => 'UNRAID AUTOUPDATER — UPDATE SUMMARY',
    'description' => dau_clip("**Server:** " . dau_clean_text($server, 64) . "\n\n" . implode("\n\n", $lines), 4000),
    'color' => $color,
    'timestamp' => dau_ts_iso(dau_now()),
  ], !empty($sum['trigger']) ? ' · ' . $sum['trigger'] . ' run' : '');
}

/** Split embeds into messages that respect Discord's limits (10 embeds, ~6000 characters). */
function dau_discord_chunk(array $embeds, $separate = false) {
  $msgs = []; $cur = []; $size = 0;
  foreach ($embeds as $e) {
    $s = dau_embed_size($e);
    if ($cur && ($separate || count($cur) >= DAU_DISCORD_EMBEDS_PER_MSG || $size + $s > DAU_DISCORD_CHARS_PER_MSG)) { $msgs[] = $cur; $cur = []; $size = 0; }
    $cur[] = $e; $size += $s;
  }
  if ($cur) $msgs[] = $cur;
  return $msgs;
}

/* ---------- which events go to Discord ---------- */

function dau_event_toggle($kind) {
  switch ($kind) {
    case 'updated': case 'os_installed': case 'os_verified': case 'rolled_back': return 'success';
    case 'failed': case 'os_prereq_failed': case 'retry_exhausted': case 'os_check_failed': case 'retrying': case 'os_install_failed': case 'os_unverified': case 'rollback_failed': return 'failed';
    default: return 'available';          // available, blocked, skipped, os_withdrawn
  }
}

function dau_discord_wants(array $dc, array $ev) {
  if (empty($dc['enabled']) || !empty($ev['silent'])) return false;
  $cat = ['docker' => 'docker', 'plugin' => 'plugins', 'os' => 'os', 'cleanup' => 'docker', 'backup' => 'docker'][$ev['type']] ?? null;
  if (!$cat || empty($dc['events'][$cat])) return false;
  if (in_array($ev['kind'], ['os_staged', 'os_rebooting', 'os_reboot_blocked', 'os_workflow_started', 'os_workflow_done', 'os_awaiting', 'os_aborted'], true)) return true;   // a pending reboot must never be missed
  return !empty($dc['types'][dau_event_toggle($ev['kind'])]);
}

function dau_summary_has_activity(array $sum) {
  foreach (['docker', 'plugins'] as $k) if (isset($sum[$k])) foreach ($sum[$k] as $n) if ((int)$n > 0) return true;
  if (isset($sum['os']) && (($sum['os']['available'] ?? false) === true)) return true;
  return false;
}

/**
 * Send notifications for one run. Never throws, never makes a job fail.
 * Returns ['messages'=>n sent,'error'=>string]. Updates state['discord'] (not saved here).
 */
function dau_discord_dispatch(array $cfg, array &$st, array $events, array $summary) {
  $dc = $cfg['discord'];
  $out = ['messages' => 0, 'error' => ''];
  if (empty($dc['enabled'])) return $out;
  $url = dau_secret_get('discord_url');
  if (!dau_webhook_valid($url)) { $out['error'] = 'no valid webhook URL saved'; return $out; }
  if (!empty($st['discord']['invalid'])) { $out['error'] = 'webhook was rejected earlier; save the URL again to retry'; return $out; }
  $server = dau_server_name();
  $GLOBALS['DAU_ICON'] = dau_discord_icon($cfg);
  $GLOBALS['DAU_CFG_NOW'] = $cfg;

  $embeds = [];
  if (!empty($dc['types']['summary']) && dau_summary_has_activity($summary)) $embeds[] = dau_discord_summary_embed($summary, $server);
  $prio = ['rollback_failed' => 0, 'major_jump' => 2, 'failed' => 0, 'os_prereq_failed' => 0, 'retry_exhausted' => 0, 'os_install_failed' => 0, 'os_unverified' => 0, 'os_check_failed' => 1, 'retrying' => 1, 'blocked' => 2, 'skipped' => 2, 'available' => 3];
  $wanted = array_values(array_filter($events, function ($e) use ($dc) { return dau_discord_wants($dc, $e); }));
  /* failures first; array_multisort with the original index keeps the order stable on PHP 7.4 */
  $keys = []; $idx = [];
  foreach ($wanted as $i => $e) { $keys[] = $prio[$e['kind']] ?? 4; $idx[] = $i; }
  array_multisort($keys, SORT_ASC, $idx, SORT_ASC, $wanted);
  foreach ($wanted as $e) $embeds[] = dau_discord_event_embed($e, $server);
  if (!$embeds) return $out;

  $msgs = dau_discord_chunk($embeds, ($dc['batching'] ?? 'combine') === 'separate');
  $max = max(1, (int)$dc['max_messages']);
  $dropped = 0;
  if (count($msgs) > $max) { foreach (array_slice($msgs, $max) as $m) $dropped += count($m); $msgs = array_slice($msgs, 0, $max); }

  foreach ($msgs as $i => $m) {
    $payload = ['username' => DAU_NAME, 'avatar_url' => $GLOBALS['DAU_ICON'], 'embeds' => $m, 'allowed_mentions' => ['parse' => []]];
    if ($dropped && $i === count($msgs) - 1) $payload['content'] = "+$dropped more notification(s) were not sent to stay under the message limit. See the plugin log.";
    $r = dau_discord_post($url, $payload);
    $st['discord']['last_attempt'] = dau_now();
    if ($r['ok']) { $out['messages']++; $st['discord']['last_ok'] = dau_now(); $st['discord']['last_error'] = ''; if ($i < count($msgs) - 1) dau_sleep(0.6); continue; }
    $out['error'] = $r['error'];
    $st['discord']['last_error'] = $r['error'];
    if ($r['permanent']) $st['discord']['invalid'] = true;
    dau_log('warn', 'discord', '-', 'send', 'fail', $r['error']);
    break;
  }
  if ($out['messages']) dau_log('info', 'discord', '-', 'send', 'ok', $out['messages'] . ' message(s)' . ($dropped ? ", $dropped dropped (limit)" : ''));
  return $out;
}

/** "Send test message" button. */
function dau_discord_test(array &$st, $urlOverride = null) {
  $url = $urlOverride !== null && $urlOverride !== '' ? $urlOverride : dau_secret_get('discord_url');
  if (!dau_webhook_valid($url)) return ['ok' => false, 'error' => 'That is not a valid Discord webhook URL (https://discord.com/api/webhooks/<id>/<token>).'];
  $server = dau_server_name();
  $e = ['title' => '🧪 Test message', 'color' => 0x3498DB, 'description' => "Webhook is working for **" . dau_clean_text($server, 64) . "**.\nNo update was installed.",
        'timestamp' => dau_ts_iso(dau_now())];
  $GLOBALS['DAU_ICON'] = dau_discord_icon(dau_load_config());
  $e = dau_discord_brand($e);
  $r = dau_discord_post($url, ['username' => DAU_NAME, 'avatar_url' => $GLOBALS['DAU_ICON'], 'embeds' => [$e], 'allowed_mentions' => ['parse' => []]]);
  $st['discord']['last_attempt'] = dau_now();
  if ($r['ok']) { $st['discord']['last_ok'] = dau_now(); $st['discord']['last_error'] = ''; $st['discord']['invalid'] = false; return ['ok' => true, 'error' => '']; }
  $st['discord']['last_error'] = $r['error'];
  if ($r['permanent']) $st['discord']['invalid'] = true;
  return ['ok' => false, 'error' => $r['error']];
}
