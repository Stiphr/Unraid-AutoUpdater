<?php
/*
 * Docker Auto Update - schedule maths.
 * All times are the server's local time zone (Settings > Date and Time), exactly like Unraid's own schedules.
 * The dispatcher ("tick") runs every DAU_TICK_MIN minutes; a schedule is "due" when its latest occurrence has
 * passed, has not run yet and is not older than DAU_CATCHUP (so a reboot over 04:00 still runs at 04:07, but a
 * server that was off all day does not run yesterday's job at midnight).
 */

/** Normalise a schedule array or return null if it is not a valid schedule. */
function dau_clean_schedule($in) {
  if (!is_array($in)) return null;
  $f = $in['frequency'] ?? 'daily';
  if (!is_string($f) || !in_array($f, DAU_FREQS, true)) return null;
  return [
    'frequency' => $f,
    'time'      => dau_valid_time($in['time'] ?? null) ? $in['time'] : '04:00',
    'weekday'   => max(0, min(6, (int)($in['weekday'] ?? 0))),
    'monthday'  => max(1, min(28, (int)($in['monthday'] ?? 1))),
  ];
}

function dau_sched_of(array $a) { return dau_clean_schedule($a); }

/** Containers/categories with an identical schedule share one slot. */
function dau_slot_key(array $s) {
  $sig = $s['frequency'] . '|' . $s['time']
       . '|' . (in_array($s['frequency'], ['weekly', 'biweekly'], true) ? (int)$s['weekday'] : '')
       . '|' . ($s['frequency'] === 'monthly' ? (int)$s['monthday'] : '');
  return 'c' . substr(md5($sig), 0, 8);
}

function dau_dt($ts) {
  $d = new DateTime('@' . (int)$ts);
  $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
  return $d;
}

/** Latest scheduled occurrence at or before $ts, or null. */
function dau_occ_before(array $s, $ts) {
  list($h, $m) = array_map('intval', explode(':', $s['time']));
  $d = dau_dt($ts);
  switch ($s['frequency']) {
    case '6h':
    case '12h':
      /* anchored on the configured time: 04:15 with "every 6 hours" runs at 04:15, 10:15, 16:15, 22:15 */
      $n = $s['frequency'] === '6h' ? 6 : 12;
      $anchor = dau_dt($ts); $anchor->setTime($h, $m, 0);
      $base = $anchor->getTimestamp();
      return $base + (int)floor(($ts - $base) / ($n * 3600)) * $n * 3600;
    case 'weekly':
    case 'biweekly':
      for ($i = 0; $i < 8; $i++) {
        $c = clone $d; $c->setTime($h, $m, 0);
        if ($i) $c->modify('-' . $i . ' day');
        $c->setTime($h, $m, 0);
        if ((int)$c->format('w') === (int)$s['weekday'] && $c->getTimestamp() <= $ts) return $c->getTimestamp();
      }
      return null;
    case 'monthly':
      for ($i = 0; $i < 3; $i++) {
        $c = clone $d; $c->setDate((int)$d->format('Y'), (int)$d->format('n') - $i, (int)$s['monthday']); $c->setTime($h, $m, 0);
        if ($c->getTimestamp() <= $ts) return $c->getTimestamp();
      }
      return null;
    default: // daily
      $c = clone $d; $c->setTime($h, $m, 0);
      if ($c->getTimestamp() > $ts) { $c->modify('-1 day'); $c->setTime($h, $m, 0); }
      return $c->getTimestamp();
  }
}

/** First scheduled occurrence strictly after $ts. */
function dau_occ_after(array $s, $ts) {
  list($h, $m) = array_map('intval', explode(':', $s['time']));
  $d = dau_dt($ts);
  switch ($s['frequency']) {
    case '6h':
    case '12h':
      $b = dau_occ_before($s, $ts);
      return $b + ($s['frequency'] === '6h' ? 6 : 12) * 3600;
    case 'weekly':
    case 'biweekly':
      for ($i = 0; $i < 9; $i++) {
        $c = clone $d; if ($i) $c->modify('+' . $i . ' day'); $c->setTime($h, $m, 0);
        if ((int)$c->format('w') === (int)$s['weekday'] && $c->getTimestamp() > $ts) return $c->getTimestamp();
      }
      return null;
    case 'monthly':
      for ($i = 0; $i < 3; $i++) {
        $c = clone $d; $c->setDate((int)$d->format('Y'), (int)$d->format('n') + $i, (int)$s['monthday']); $c->setTime($h, $m, 0);
        if ($c->getTimestamp() > $ts) return $c->getTimestamp();
      }
      return null;
    default:
      $c = clone $d; $c->setTime($h, $m, 0);
      if ($c->getTimestamp() <= $ts) { $c->modify('+1 day'); $c->setTime($h, $m, 0); }
      return $c->getTimestamp();
  }
}

/**
 * Is this schedule due now?
 * $rec = ['seen'=>ts first time this slot existed, 'last'=>ts of its last real run]
 */
function dau_slot_due(array $s, array $rec, $now) {
  $occ = dau_occ_before($s, $now);
  if ($occ === null) return false;
  $since = max((int)($rec['seen'] ?? 0), (int)($rec['last'] ?? 0));
  if ($occ <= $since) return false;                      // already handled, or the slot is newer than this occurrence
  if ($now - $occ > DAU_CATCHUP) return false;           // too late; wait for the next occurrence
  if ($s['frequency'] === 'biweekly' && !empty($rec['last']) && $occ - (int)$rec['last'] < 12 * 86400) return false;
  return true;
}

/** Next time this schedule will run, honouring bi-weekly gating. */
function dau_next_run(array $s, array $rec, $now) {
  $t = $now;
  for ($i = 0; $i < 6; $i++) {
    $n = dau_occ_after($s, $t);
    if ($n === null) return null;
    if ($s['frequency'] === 'biweekly' && !empty($rec['last']) && $n - (int)$rec['last'] < 12 * 86400) { $t = $n; continue; }
    return $n;
  }
  return null;
}

/* ---------- maintenance window ---------- */

/** Is $ts inside the window? Days refer to the day the window opens; an overnight window (22:00-04:00) belongs to its start day. */
function dau_window_open(array $w, $ts) {
  if (empty($w['enabled'])) return true;
  $d = dau_dt($ts);
  $mins = (int)$d->format('G') * 60 + (int)$d->format('i');
  $dow = (int)$d->format('w');
  list($sh, $sm) = array_map('intval', explode(':', $w['start']));
  list($eh, $em) = array_map('intval', explode(':', $w['end']));
  $s = $sh * 60 + $sm; $e = $eh * 60 + $em;
  $days = $w['days'];
  if ($s === $e) return in_array($dow, $days, true);
  if ($s < $e) return in_array($dow, $days, true) && $mins >= $s && $mins < $e;
  if ($mins >= $s) return in_array($dow, $days, true);
  return $mins < $e && in_array(($dow + 6) % 7, $days, true);
}

/** Next time the window is open (now if already open), searched in 5 minute steps over 8 days. */
function dau_window_next_open(array $w, $ts) {
  if (dau_window_open($w, $ts)) return $ts;
  for ($t = $ts - ($ts % 300) + 300, $i = 0; $i < 8 * 288; $i++, $t += 300) if (dau_window_open($w, $t)) return $t;
  return null;
}

function dau_window_text(array $w) {
  if (empty($w['enabled'])) return 'any time';
  $n = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  $days = count($w['days']) === 7 ? 'every day' : implode(', ', array_map(function ($d) use ($n) { return $n[$d]; }, $w['days']));
  return $days . ' ' . $w['start'] . '-' . $w['end'];
}

function dau_sched_text(array $s) {
  $n = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  switch ($s['frequency']) {
    case '6h':  return 'Every 6 hours (:' . substr($s['time'], 3) . ')';
    case '12h': return 'Every 12 hours (:' . substr($s['time'], 3) . ')';
    case 'weekly':   return 'Weekly, ' . $n[$s['weekday']] . ' ' . $s['time'];
    case 'biweekly': return 'Every two weeks, ' . $n[$s['weekday']] . ' ' . $s['time'];
    case 'monthly':  return 'Monthly, day ' . $s['monthday'] . ' ' . $s['time'];
    default: return 'Daily ' . $s['time'];
  }
}
