/* Unraid Swiss Manager UI. Plain JS, no libraries; every string is inserted as text (never as HTML). */
(function () {
  'use strict';
  var API = '/plugins/unraid-swiss-manager/include/api.php';
  var root = document.getElementById('dau');
  var S = { copen: {}, hist: null, ad: null, cs: null, cresult: null, cerr: {}, qr: null, tp: null, clean: null, csel: { img: {}, con: {} }, tab: 'overview', ov: null, docker: null, plugins: null, os: null, hist: null, log: null, draft: null, sel: {}, psel: {}, open: {}, q: '', filter: 'all', sort: 'name', pq: '', pfilter: 'all', busy: false, poll: null };
  /* toasts and dialogs live next to the page, not in it: render() rebuilds the page and must not take them along */
  var layer = h('div', { class: 'dau dau-layer' });
  root.parentNode.insertBefore(layer, root.nextSibling);
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var FREQ = [['6h', 'Every 6 hours'], ['12h', 'Every 12 hours'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['biweekly', 'Every two weeks'], ['monthly', 'Monthly']];
  var POL = [['auto', 'Auto update'], ['notify', 'Notify only'], ['ignore', 'Ignore']];
  var DELAYS = [[0, 'Immediately'], [24, '24 hours'], [72, '3 days'], [168, '7 days']];
  var STATE = {
    external: ['Not managed by Unraid', ''], unknown: ['Status unknown', ''], up_to_date: ['Up to date', 'ok'], ignored: ['Ignored', ''], notify_only: ['Update available', 'acc'],
    stopped: ['Skipped: stopped', 'warn'], approval: ['Needs your approval', 'warn'], no_identity: ['Waiting: digest unknown', 'warn'], waiting_delay: ['Waiting for delay', 'warn'],
    retry_wait: ['Retry scheduled', 'warn'], retry_exhausted: ['Retries used up', 'bad'], window_closed: ['Waiting for window', 'warn'], ready: ['Ready to update', 'acc'],
    blocked: ['Blocked', 'bad'], self: ['This plugin', ''], pinned: ['Pinned (rolled back)', 'warn'], skipped: ['Version skipped', '']
  };

  function h(tag, p) {
    var e = document.createElement(tag); p = p || {};
    for (var k in p) {
      if (k === 'class') e.className = p[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), p[k]);
      else if (k === 'value') e.value = p[k];
      else if (k === 'checked' || k === 'disabled' || k === 'selected') e[k] = !!p[k];
      else if (p[k] !== null && p[k] !== undefined && p[k] !== false) e.setAttribute(k, p[k]);
    }
    for (var i = 2; i < arguments.length; i++) add(e, arguments[i]);
    return e;
  }
  function add(e, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(e, x); }); return; }
    e.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
  }
  function clear(e) { while (e.firstChild) e.removeChild(e.firstChild); }
  function ago(ts) { if (!ts) return 'never'; var s = Math.max(0, S.ov ? S.ov.now - ts : Date.now() / 1000 - ts); if (s < 90) return 'just now'; if (s < 5400) return Math.round(s / 60) + ' min ago'; if (s < 129600) return Math.round(s / 3600) + ' h ago'; return Math.round(s / 86400) + ' d ago'; }
  function when(ts) { return ts ? new Date(ts * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '-'; }
  function dur(s) { s = Math.round(s || 0); return s < 60 ? s + ' s' : Math.floor(s / 60) + ' min ' + (s % 60) + ' s'; }
  function toast(msg, bad) { var t = h('div', { class: 'toast' + (bad ? ' bad' : ''), role: bad ? 'alert' : 'status' }, msg); layer.appendChild(t); setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, bad ? 7000 : 3500); }

  function api(action, data, post) {
    var fd = new URLSearchParams(); fd.append('action', action);   // urlencoded, not multipart: multipart POSTs hang on Unraid's web server
    fd.append('csrf_token', window.DAU_CSRF || '');   // Unraid rejects any POST without it, reads included
    for (var k in (data || {})) fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
    return fetch(API, { method: post ? 'POST' : 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unexpected response (' + r.status + ')' }; }).then(function (j) { if (!r.ok && !j.error) j.error = 'Request failed (' + r.status + ')'; return j; });
    }).catch(function () { return { error: 'Could not reach the server.' }; });
  }
  /* while a change is on its way the buttons rest, so a double click cannot send it twice */
  var acting = 0;
  function wait(d) { acting += d; [root, layer].forEach(function (e) { e.classList.toggle('dau-wait', acting > 0); }); }
  function act(action, data, okMsg) { wait(1); return api(action, data, true).then(function (j) { wait(-1); if (j.error) toast(j.error, true); else if (okMsg) toast(okMsg); return j; }); }

  /* ---------- loading ---------- */
  function load(which) {
    var jobs = [];
    var want = which || S.tab;
    jobs.push(api('overview').then(function (j) { if (!j.error) { S.ov = j; if (!S.draft) S.draft = JSON.parse(JSON.stringify(j.config)); } else S.err = j.error; }));
    /* a failed request keeps what is on screen and says so; it never replaces the data with an error */
    function keep(k) { return function (j) { if (j.error) S.loadErr = j.error; else { S.loadErr = ''; S[k] = j; } }; }
    if (want === 'docker') jobs.push(api('docker').then(keep('docker')));
    if (want === 'plugins') jobs.push(api('plugins').then(keep('plugins')));
    if (want === 'os') jobs.push(api('os').then(keep('os')));
    if (want === 'backups') jobs.push(api('backups').then(keep('bk')));
    if (want === 'cleanup') jobs.push(api('history').then(function (j) { S.hist = j.rows || []; }));
    if (want === 'activity') { jobs.push(api('history').then(function (j) { S.hist = j.rows || []; })); jobs.push(api('log').then(function (j) { S.log = j.log || ''; })); }
    return Promise.all(jobs).then(render);
  }
  function startPoll() {
    if (S.poll) return;
    S.poll = setInterval(function () {
      if (document.hidden || S.pollBusy) return;      // nobody is looking, or the last answer has not come back yet
      S.pollBusy = true;
      api('status', S.runLog ? { log: '1' } : {}).then(function (j) {
        S.pollBusy = false;
        if (!j.run) return;
        var was = S.ov && S.ov.run.running; S.ov.run = j.run;
        if (j.log !== undefined) S.runLogText = j.log;
        if (j.run.running) { renderRunBar(); return; }
        clearInterval(S.poll); S.poll = null;      // also when a page reload saw the end first: the timer must not outlive the job
        if (!was) return;
        toast('Finished.');
        if (S.reloadAfter) { setTimeout(function () { location.reload(); }, 1500); return; }
        load();
      });
    }, 2000);
  }
  function runJob(what, names, okMsg, extra) {
    var d = { what: what, names: names || [] }; for (var k in (extra || {})) d[k] = extra[k];
    return act('run', d, okMsg || 'Started.').then(function (j) {
      if (j.ok) { S.ov.run = { running: true, progress: { phase: 'Starting' } }; render(); startPoll(); }
    });
  }

  /* ---------- appearance: theme, accent colour, custom CSS ---------- */
  function pageIsLight() {
    var t = String(window.DAU_THEME || '').toLowerCase();
    if (t === 'white' || t === 'azure') return true;
    if (t === 'black' || t === 'gray') return false;
    var m = /(\d+)[,\s]+(\d+)[,\s]+(\d+)/.exec(getComputedStyle(document.body).backgroundColor || '');      // a theme this version does not know
    return m ? 0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3] > 140 : false;
  }
  function applyTheme(ui) {
    ui = ui || {};
    var theme = ui.theme || 'auto', light = pageIsLight();
    [root, layer].forEach(function (e) {
      e.setAttribute('data-theme', theme === 'auto' ? (light ? 'light' : 'dark') : theme);
      if (theme === 'auto') e.setAttribute('data-native', ''); else e.removeAttribute('data-native');      // follows Unraid: the page keeps Unraid's own background
      if (theme === 'dark' && light) e.setAttribute('data-paint', ''); else e.removeAttribute('data-paint');      // chosen on a light Unraid theme: it needs its own background to stay readable
    });
    var st = document.getElementById('dau-user-css');
    if (!st) { st = document.createElement('style'); st.id = 'dau-user-css'; document.head.appendChild(st); }
    var acc = /^#[0-9a-f]{6}$/i.test(ui.accent || '') ? ui.accent : '#f15a2c';
    st.textContent = '.dau{--accent:' + acc + ';--accent-hi:' + acc + '}\n' + String(ui.css || '').replace(/<\/?style/gi, '');
  }

  /* ---------- shared actions ---------- */
  function skipAct(type, name, label, undo) {
    act(undo ? 'unskip' : 'skip', { type: type, name: name }, undo ? 'Will be offered again: ' + label : 'Skipping this version of ' + label + '. A newer one is announced again.').then(function () { load(); });
  }
  function notesModal(type, name, title) {
    var m = modal('What changed: ' + title);
    var body = h('pre', { class: 'log' }, 'Loading…'); m.body.appendChild(body); m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Close'));
    api('notes', { type: type, name: name }).then(function (j) {
      if (j.error) { body.textContent = j.error; return; }
      body.textContent = j.text || (type === 'docker' ? '' : 'No release notes were published with this update.');
      if (j.version) m.body.insertBefore(h('p', { class: 'muted' }, 'Version: ' + j.version), body);
      if (type === 'docker') {
        if (j.link) { body.textContent = 'This image publishes its notes with the project\'s releases.'; m.body.appendChild(h('p', null, h('a', { href: j.link, target: '_blank', rel: 'noopener noreferrer' }, 'Open the release notes'))); }
        else if (!j.text) body.textContent = 'This image does not say where its release notes are.';
      }
      if (type === 'os') m.body.appendChild(h('p', { class: 'muted sm' }, 'Back up your flash drive first and read the full release notes under Tools > Update OS.'));
    });
  }
  function rollbackConfirm(r) {
    var m = modal('Roll back ' + r.name + '?');
    m.body.appendChild(h('p', null, 'The container is recreated with the image it used before its last update' + (r.rollback.from || r.rollback.to ? ' (' + (r.rollback.to || '?') + ' → ' + (r.rollback.from || '?') + ')' : '') + '.'));
    m.body.appendChild(h('p', { class: 'muted' }, 'It restarts briefly. Afterwards it is pinned to that image and the newer version is skipped, so nothing updates it again until you click "Unpin". Data in its appdata folders is not changed, so after a major version jump an older program may not be able to read newer data: restore a backup if that happens.'));
    var cb = h('input', { type: 'checkbox' }); m.body.appendChild(h('label', { class: 'switch' }, cb, 'I understand and want to roll back'));
    var go = h('button', { class: 'btn pri', disabled: true, onclick: function () { m.close(); runJob('rollback', [], 'Rollback started', { name: r.name, confirm: '1' }); } }, 'Roll back');
    cb.addEventListener('change', function () { go.disabled = !cb.checked; });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
  }

  /* links from notifications: ?act=update|skip|approve|open&type=docker|plugin|os&name=… */
  function deepLink() {
    var q = new URLSearchParams(location.search), a = q.get('act'), tab = q.get('tab');
    if (!a && tab && TABS.some(function (t) { return t[0] === tab; })) {      // ?tab=docker|plugins|os|activity|… (Dashboard tile rows)
      try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignore */ }
      S.tab = tab; return load(tab);
    }
    if (!a) return;
    var type = q.get('type') || '', name = q.get('name') || '';
    try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignore */ }
    if (type === 'os') { S.tab = 'os'; if (a === 'skip') return load('os').then(function () { var m = modal('Skip this Unraid release?'); m.body.appendChild(h('p', null, 'No automatic action is taken for this release. A newer one is announced again.')); m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(h('button', { class: 'btn pri', onclick: function () { m.close(); skipAct('os', '', 'Unraid OS'); } }, 'Skip')); }); return load('os'); }
    if (type === 'docker') {
      S.tab = 'docker';
      return load('docker').then(function () {
        if (a === 'update') previewUpdate([name]);
        else if (a === 'approve') { var ma = modal('Approve this image of ' + name + '?'); ma.body.appendChild(h('p', null, 'The container is marked critical. Approving lets the next scheduled run update it to this image.')); ma.foot.appendChild(h('button', { class: 'btn', onclick: ma.close }, 'Cancel')); ma.foot.appendChild(h('button', { class: 'btn pri', onclick: function () { ma.close(); act('approve', { name: name }, 'Approved this image for ' + name).then(function () { load('docker'); }); } }, 'Approve')); }
        else if (a === 'skip') { var m = modal('Skip this version of ' + name + '?'); m.body.appendChild(h('p', null, 'This image is ignored; a newer one is announced again.')); m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(h('button', { class: 'btn pri', onclick: function () { m.close(); skipAct('docker', name, name); } }, 'Skip')); }
      });
    }
    if (type === 'plugin') {
      S.tab = 'plugins';
      return load('plugins').then(function () {
        var r = (S.plugins.rows || []).filter(function (x) { return x.name === name || x.file === name; })[0]; if (!r) return;
        if (a === 'update') confirmPlugins([r.file]);
        else if (a === 'skip') { var m = modal('Skip this version of ' + r.name + '?'); m.body.appendChild(h('p', null, 'This version is ignored; a newer one is announced again.')); m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(h('button', { class: 'btn pri', onclick: function () { m.close(); skipAct('plugin', r.file, r.name); } }, 'Skip')); }
      });
    }
  }

  /* ---------- shell ---------- */
  var TABS = [['overview', 'Overview'], ['docker', 'Docker'], ['plugins', 'Plugins'], ['os', 'Unraid OS'], ['cleanup', 'Cleanup'], ['backups', 'Backups'], ['settings', 'Settings'], ['activity', 'Activity']];
  var runBar = h('div');
  function renderRunBar() {
    clear(runBar);
    var r = S.ov && S.ov.run; if (!r || !r.running) return;
    var p = r.progress || {}, pct = p.total ? Math.round((p.done || 0) / p.total * 100) : null, spent = p.started && p.ts ? p.ts - p.started : 0;
    var box = h('div', { class: 'banner' }, h('div', { class: 'inl' }, h('b', null, 'Working: '), h('span', { class: 'grow' }, (p.phase || 'Running') + (p.total ? ' (' + (p.done || 0) + '/' + p.total + ')' : '') + (spent > 4 ? ' · ' + dur(spent) : '')),
      h('button', { class: 'btn sm', 'aria-expanded': S.runLog ? 'true' : 'false', onclick: function () { S.runLog = !S.runLog; renderRunBar(); if (S.runLog) api('status', { log: '1' }).then(function (j) { if (j.log !== undefined) { S.runLogText = j.log; renderRunBar(); } }); } }, S.runLog ? 'Hide log' : 'Show log'),
      h('button', { class: 'btn sm danger', onclick: function () { act('stop', {}, 'Stop requested: the job ends after the current step.'); } }, 'Stop after current step')));
    var st = stepper(p); if (st) box.appendChild(st);
    box.appendChild(h('div', { class: 'progress', role: 'progressbar', 'aria-valuenow': pct }, h('i', pct === null ? null : { class: 'det', style: 'width:' + pct + '%' })));
    if (S.runLog) box.appendChild(h('pre', { class: 'log runlog' }, S.runLogText || 'Nothing logged yet.'));
    runBar.appendChild(box);
    var lg = box.querySelector('.runlog'); if (lg) lg.scrollTop = lg.scrollHeight;
  }
  /* the steps of what is running, as the server names them: done, current, still to come */
  function stepper(p) {
    if (!p.steps || !p.steps.length) return null;
    var at = -1; p.steps.forEach(function (s, i) { if (s[0] === p.stage) at = i; });
    var w = h('div', { class: 'steps', 'aria-label': 'Progress' });
    p.steps.forEach(function (s, i) { w.appendChild(h('span', { class: 'step' + (i === at ? ' on' : i < at ? ' done' : ''), 'aria-current': i === at ? 'step' : null }, s[1])); });
    return w;
  }
  function render() {
    clear(root);
    if (!S.ov) { root.appendChild(h('div', { class: 'dau-loading' }, S.err || 'Loading')); return; }
    var c = S.ov.cards, busy = S.ov.run.running;
    if (busy) startPoll();      // also a job this page did not start: a scheduled run, or another browser tab
    root.appendChild(h('div', { class: 'head' }, h('div', { class: 'brand' }, h('img', { class: 'logo', src: '/plugins/unraid-swiss-manager/images/unraid-swiss-manager.svg' + (window.DAU_IMGV || ''), alt: '', onerror: function () { this.style.display = 'none'; } }), h('div', null, h('div', { class: 'dau-name' }, S.ov.name || 'Unraid Swiss Manager'), h('div', { class: 'ver' }, 'Plugin version ' + S.ov.version, S.ov.tick_installed ? null : h('span', { class: 'muted' }, ' · no schedule is active')))),
      h('div', { class: 'actions' }, h('button', { class: 'btn', disabled: busy, onclick: function () { runJob('check', [], 'Checking for updates'); } }, 'Check now'))));
    if (S.ov.config_status === 'corrupt') root.appendChild(h('div', { class: 'banner bad' }, 'The saved settings file could not be read, so safe defaults are shown. The damaged file was kept next to it as config.corrupt-*.json.'));
    if (S.loadErr) root.appendChild(h('div', { class: 'banner bad' }, h('div', { class: 'inl' }, h('span', { class: 'grow' }, 'This tab could not be refreshed: ' + S.loadErr), h('button', { class: 'btn sm', onclick: function () { load(); } }, 'Try again'))));
    if (!S.ov.docker_up && S.tab !== 'settings') root.appendChild(h('div', { class: 'banner' }, 'The Docker service is not running. Docker features are unavailable until it is started.'));
    root.appendChild(runBar); renderRunBar();
    var tabs = h('div', { class: 'tabs' });
    TABS.forEach(function (t) {
      var n = t[0] === 'docker' ? c.docker_updates : t[0] === 'plugins' ? c.plugin_updates : t[0] === 'os' && c.os_available ? 1 : t[0] === 'cleanup' ? cleanCount(c.cleanup) : 0;
      tabs.appendChild(h('button', { class: 'tab' + (S.tab === t[0] ? ' on' : ''), onclick: function () { S.tab = t[0]; if (t[0] === 'cleanup') { S.qr = null; S.tp = null; } load(t[0]); } }, t[1], n ? h('span', { class: 'n' }, n) : null, t[0] === 'settings' && isDirty() ? h('span', { class: 'dirty', title: 'Unsaved changes' }, ' •') : null));
    });
    root.appendChild(tabs);
    applyTheme(S.tab === 'settings' && S.draft ? S.draft.ui : S.ov.config.ui);
    var body;
    try { body = { overview: vOverview, docker: vDocker, plugins: vPlugins, os: vOS, cleanup: vCleanup, backups: vBackups, settings: vSettings, activity: vActivity }[S.tab](); }
    catch (e) { if (window.console) console.error(e); body = h('div', { class: 'banner bad' }, 'This tab could not be shown. Reload the page; if that does not help, the browser console has the details.'); }
    root.appendChild(body);
  }
  /* settings the page edits, without what other tabs and actions change */
  function editable(c) { var x = JSON.parse(JSON.stringify(c)); delete x.containers; delete x.plugins.items; delete x.appdata.ignore; delete x.discord.webhook_set; delete x.discord.icon_default; return JSON.stringify(x); }
  function isDirty() { return !!(S.draft && S.ov && editable(S.draft) !== editable(S.ov.config)); }
  window.addEventListener('beforeunload', function (e) { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------- overview ---------- */
  function card(k, v, s, cls) { return h('div', { class: 'card ' + (cls || '') }, h('div', { class: 'k' }, k), h('div', { class: 'v' }, v), h('div', { class: 's' }, s || '')); }
  /* last 30 days: green = updated, red = failed (plain bars; every bar has a text alternative) */
  function chart(days) {
    var max = 1; days.forEach(function (d) { max = Math.max(max, d.ok + d.failed); });
    var box = h('div', { class: 'chart', role: 'img', 'aria-label': 'Updates per day over the last 30 days' });
    days.forEach(function (d) {
      var tot = d.ok + d.failed;
      box.appendChild(h('div', { class: 'bar', title: d.day + ': ' + d.ok + ' updated, ' + d.failed + ' failed' },
        h('i', { class: 'f', style: 'height:' + (d.failed ? Math.max(4, d.failed / max * 100) : 0) + '%' }), h('i', { class: 'o', style: 'height:' + (d.ok ? Math.max(4, d.ok / max * 100) : 0) + '%' })));
    });
    return h('div', null, h('h4', null, 'Updates per day (last 30 days)'), box, h('div', { class: 'inl sm muted' }, h('span', { class: 'key o' }), 'updated', h('span', { class: 'key f' }), 'failed'));
  }
  function vOverview() {
    var c = S.ov.cards, w = h('div');
    w.appendChild(h('div', { class: 'cards' },
      card('Containers', c.containers, c.docker_auto + ' auto-update'),
      card('Docker updates', c.docker_updates, 'available now', c.docker_updates ? 'hot' : ''),
      card('Plugins', c.plugins, c.plugin_updates + ' with updates', c.plugin_updates ? 'hot' : ''),
      card('Unraid OS', c.os_installed || '?', c.os_available ? 'Update available: ' + c.os_available : (c.os_error ? 'Check failed' : 'Up to date'), c.os_available ? 'hot' : ''),
      card('Recent failures', c.failures, 'in the last 3 days', c.failures ? 'bad' : ''),
      card('Last run', ago(c.last_run), c.last_result && c.last_result.status ? 'Result: ' + c.last_result.status : '')));
    var nx = h('div');
    if (!c.next.length) nx.appendChild(h('div', { class: 'muted' }, 'Nothing is scheduled. Turn on a schedule in Settings, or set a container to "Auto update".'));
    c.next.forEach(function (n) { nx.appendChild(h('div', { class: 'inl' }, h('b', null, n.label), h('span', { class: 'muted' }, n.text + (n.count > 1 || n.label === 'Docker' ? ' · ' + n.count + ' container(s)' : '')), h('span', { class: 'grow' }), h('span', null, n.next ? when(n.next) : '-'))); });
    w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Next scheduled checks'), nx));
    if (c.queued) w.appendChild(h('div', { class: 'banner' }, c.queued + ' notification(s) are on hold' + (c.quiet_now ? ' during quiet hours and will be sent afterwards.' : ' and will go out with the next run.')));
    var st = S.ov.stats;
    w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Statistics'), h('div', { class: 'grid2' }, ['docker', 'plugin'].map(function (t) {
      var x = (st.by_type || {})[t] || {}; return h('div', null, h('b', null, t === 'docker' ? 'Docker' : 'Plugins'), h('div', { class: 'muted sm' }, (x.ok || 0) + ' updated · ' + (x.failed || 0) + ' failed · average ' + dur(x.avg_duration || 0)));
    })), chart(st.daily || []), h('div', { class: 'grid2' },
      h('div', null, h('h4', null, 'Most active'), (st.top || []).length ? (st.top || []).slice(0, 5).map(function (p) { return h('div', { class: 'inl sm' }, h('span', { class: 'grow' }, p.name), h('span', { class: 'muted' }, p.ok + ' ok' + (p.failed ? ' · ' + p.failed + ' failed' : ''))); }) : h('div', { class: 'muted sm' }, 'Nothing recorded yet.')),
      h('div', null, h('h4', null, 'Most failures'), (st.top_failing || []).length ? st.top_failing.map(function (p) { return h('div', { class: 'inl sm' }, h('span', { class: 'grow' }, p.name), h('span', { class: 'badge bad' }, p.failed + ' failed')); }) : h('div', { class: 'muted sm' }, 'No failures recorded.')))));
    var d = S.ov.discord, cfg = S.ov.config.discord;
    if (cfg.enabled) w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Discord'), h('div', { class: d.invalid || d.last_error ? 'badge bad' : 'badge ok' }, d.invalid ? 'Webhook rejected: save it again' : d.last_error ? 'Last send failed' : 'Connected'), h('span', { class: 'muted sm' }, ' last success: ' + ago(d.last_ok) + (d.last_error ? ' · ' + d.last_error : ''))));
    w.appendChild(h('div', { class: 'muted sm' }, 'Checked: Docker ' + ago(S.ov.checked.docker) + ' · Plugins ' + ago(S.ov.checked.plugins) + ' · OS ' + ago(S.ov.checked.os)));
    return w;
  }

  /* ---------- Docker ---------- */
  function polSel(val, cb, allowDefault) {
    var s = h('select', { onchange: function () { cb(s.value); } });
    POL.forEach(function (p) { s.appendChild(h('option', { value: p[0], selected: p[0] === val }, p[1])); });
    return s;
  }
  function shortState(r) {
    var st = STATE[r.state] || [r.state, ''];
    return h('span', { class: 'badge ' + st[1], title: r.reason || '' }, st[0]);
  }
  function rowMatches(r) {
    if (S.q && (r.name + ' ' + r.image).toLowerCase().indexOf(S.q.toLowerCase()) < 0) return false;
    switch (S.filter) {
      case 'updates': return r.update === true;
      case 'auto': return r.cfg.policy === 'auto'; case 'notify': return r.cfg.policy === 'notify'; case 'ignore': return r.cfg.policy === 'ignore';
      case 'critical': return !!r.cfg.critical;
      case 'problems': return r.last && r.last.result === 'failed' || r.state === 'retry_exhausted' || r.state === 'blocked';
      case 'running': return r.run_state === 'running';
    }
    return true;
  }
  function vDocker() {
    var w = h('div');
    if (!S.docker) { w.appendChild(h('div', { class: 'dau-loading' }, 'Loading containers')); return w; }
    var rows = S.docker.rows.filter(rowMatches);
    var key = { name: function (r) { return r.name.toLowerCase(); }, update: function (r) { return r.update === true ? 0 : 1; }, policy: function (r) { return r.cfg.policy; }, state: function (r) { return r.state; }, last: function (r) { return -((r.last && r.last.ts) || 0); } }[S.sort];
    rows.sort(function (a, b) { var x = key(a), y = key(b); return x < y ? -1 : x > y ? 1 : 0; });
    var names = rows.map(function (r) { return r.name; }), selNames = names.filter(function (n) { return S.sel[n]; });
    var q = h('input', { type: 'search', placeholder: 'Search…', value: S.q, oninput: function () { S.q = q.value; var p = q.selectionStart; render(); var n = root.querySelector('input[type=search]'); if (n) { n.focus(); n.setSelectionRange(p, p); } } });
    var f = h('select', { onchange: function () { S.filter = f.value; render(); } }); [['all', 'All'], ['updates', 'Update available'], ['auto', 'Auto update'], ['notify', 'Notify only'], ['ignore', 'Ignored'], ['critical', 'Critical'], ['problems', 'Problems']].forEach(function (o) { f.appendChild(h('option', { value: o[0], selected: o[0] === S.filter }, o[1])); });
    var so = h('select', { onchange: function () { S.sort = so.value; render(); } }); [['name', 'Sort: name'], ['update', 'Sort: updates first'], ['policy', 'Sort: policy'], ['state', 'Sort: status'], ['last', 'Sort: last update']].forEach(function (o) { so.appendChild(h('option', { value: o[0], selected: o[0] === S.sort }, o[1])); });
    var bulk = h('select', { disabled: !selNames.length, onchange: function () { if (!bulk.value) return; var v = bulk.value; act('set_container', { names: selNames, data: { policy: v } }, 'Policy set for ' + selNames.length + ' container(s)').then(function () { load('docker'); }); } },
      h('option', { value: '' }, 'Set policy for selected…'), POL.map(function (p) { return h('option', { value: p[0] }, p[1]); }));
    var upd = h('button', { class: 'btn pri', disabled: S.ov.run.running || !selNames.length, onclick: function () { previewUpdate(selNames); } }, 'Update selected…');
    var all = h('button', { class: 'btn', disabled: S.ov.run.running, onclick: function () { previewUpdate(S.docker.rows.filter(function (r) { return r.update === true && r.managed; }).map(function (r) { return r.name; })); } }, 'Update all pending…');
    var rmSel = h('button', { class: 'btn danger', disabled: S.ov.run.running || !selNames.length, onclick: function () { removeContainers(selNames); } }, 'Remove selected…');
    w.appendChild(h('div', { class: 'tools' }, q, f, so, bulk, h('span', { class: 'grow' }), upd, all, rmSel));
    var t = h('table', { class: 'resp' });
    var all_cb = h('input', { type: 'checkbox', checked: names.length && selNames.length === names.length, onchange: function () { names.forEach(function (n) { S.sel[n] = all_cb.checked; }); render(); } });
    t.appendChild(h('thead', null, h('tr', null, h('th', null, all_cb), h('th', null, 'Container'), h('th', null, 'Status'), h('th', null, 'Image'), h('th', null, 'Policy'), h('th', null, 'Schedule / delay'), h('th', null, 'Last update'), h('th', null, ''))));
    var tb = h('tbody'); t.appendChild(tb);
    if (!rows.length) tb.appendChild(h('tr', null, h('td', { colspan: 8, class: 'muted' }, S.docker.docker_up ? 'No containers match.' : 'Docker is not running.')));
    rows.forEach(function (r) {
      var open = !!S.open[r.name];
      tb.appendChild(h('tr', { class: 'row' + (open ? ' open' : '') },
        h('td', null, h('input', { type: 'checkbox', checked: !!S.sel[r.name], onchange: function (e) { S.sel[r.name] = e.target.checked; render(); } })),
        h('td', { class: 'name', 'data-l': 'Container' }, r.icon && /^https?:\/\//.test(r.icon) ? h('img', { class: 'ico', src: r.icon, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }) : null, h('span', { class: 'nm' }, r.name), h('div', { class: 'img' }, r.run_state || '')),
        h('td', { 'data-l': 'Status' }, shortState(r), r.cfg.critical ? h('span', { class: 'badge warn', title: 'Needs manual approval' }, ' critical') : null, r.major ? h('div', { class: 'badge warn', title: 'Version ' + (r.major.from || '?') + ' to ' + (r.major.to || '?') }, 'major update held: ' + (r.major.from || '?') + ' → ' + (r.major.to || '?')) : null, r.eta ? h('div', { class: 'muted sm' }, 'until ' + when(r.eta)) : null),
        h('td', { 'data-l': 'Image' }, h('div', { class: 'img muted sm' }, r.image), r.update === true ? h('div', { class: 'digest' }, (r.local || '').slice(7, 19) + ' → ' + (r.remote || '').slice(7, 19)) : null),
        h('td', { 'data-l': 'Policy' }, r.managed ? polSel(r.cfg.policy, function (v) { act('set_container', { names: [r.name], data: { policy: v } }).then(function () { load('docker'); }); }) : h('span', { class: 'muted' }, '-')),
        h('td', { 'data-l': 'Schedule' }, h('div', { class: 'sm' }, r.schedule_text || 'Default schedule'), h('div', { class: 'muted sm' }, 'Delay: ' + (r.delay_hours ? r.delay_hours + ' h' : 'none'))),
        h('td', { 'data-l': 'Last update' }, r.last ? h('span', null, h('span', { class: 'badge ' + (r.last.result === 'updated' ? 'ok' : 'bad') }, r.last.result), h('div', { class: 'muted sm' }, ago(r.last.ts), r.last.verification ? ' · ' + r.last.verification.result : '')) : h('span', { class: 'muted' }, '-')),
        h('td', null, h('div', { class: 'inl' },
          r.update === true && r.managed ? h('button', { class: 'btn sm pri', disabled: S.ov.run.running, onclick: function () { previewUpdate([r.name]); } }, 'Update') : null,
          r.major && r.managed ? h('button', { class: 'btn sm warn', disabled: S.ov.run.running, title: 'Install it although it changes the major version', onclick: function () { previewUpdate([r.name], { allow_major: true }); } }, 'Update anyway') : null,
          r.state === 'approval' ? h('button', { class: 'btn sm', disabled: S.ov.run.running, onclick: function () { act('approve', { name: r.name }, 'Approved this image for ' + r.name).then(function () { load('docker'); }); } }, 'Approve') : null,
          r.update === true && r.managed ? h('button', { class: 'btn sm', onclick: function () { notesModal('docker', r.name, r.name); } }, 'Notes') : null,
          r.update === true && r.managed && !r.skipped ? h('button', { class: 'btn sm', disabled: S.ov.run.running, title: 'Ignore this version; a newer one is announced again', onclick: function () { skipAct('docker', r.name, r.name); } }, 'Skip') : null,
          r.skipped ? h('button', { class: 'btn sm', disabled: S.ov.run.running, onclick: function () { skipAct('docker', r.name, r.name, true); } }, 'Un-skip') : null,
          r.rollback && !r.pinned ? h('button', { class: 'btn sm', disabled: S.ov.run.running, title: 'Go back to the image before the last update', onclick: function () { rollbackConfirm(r); } }, 'Roll back') : null,
          r.pinned ? h('button', { class: 'btn sm', disabled: S.ov.run.running, title: 'Follow updates again', onclick: function () { act('unpin', { name: r.name }, 'Unpinned ' + r.name).then(function () { load('docker'); }); } }, 'Unpin') : null,
          h('button', { class: 'btn sm', onclick: function () { S.open[r.name] = !open; render(); } }, open ? 'Close' : 'Settings'),
          h('button', { class: 'btn sm danger', disabled: S.ov.run.running, title: 'Stop and remove this container', onclick: function () { removeContainers([r.name]); } }, 'Remove')))));
      if (open) tb.appendChild(h('tr', { class: 'drawer' }, h('td', { colspan: 8 }, containerDrawer(r))));
    });
    w.appendChild(t);
    return w;
  }
  function containerDrawer(r) {
    var c = JSON.parse(JSON.stringify(r.cfg)), own = !!c.schedule; if (!c.schedule) c.schedule = { frequency: 'daily', time: '04:00', weekday: 0, monthday: 1 };
    var inner = h('div', { class: 'in' });
    var del = h('select'); del.appendChild(h('option', { value: '' , selected: c.delay_hours === null }, 'Use global default'));
    DELAYS.forEach(function (d) { del.appendChild(h('option', { value: d[0], selected: c.delay_hours === d[0] }, d[1])); });
    var cust = h('input', { type: 'number', min: 0, max: 720, placeholder: 'custom h', style: 'width:90px', value: c.delay_hours !== null && !DELAYS.some(function (d) { return d[0] === c.delay_hours; }) ? c.delay_hours : '' });
    var notify = h('input', { type: 'checkbox', checked: c.notify }), crit = h('input', { type: 'checkbox', checked: c.critical }), bk = h('input', { type: 'checkbox', checked: c.backup });
    var ownCb = h('input', { type: 'checkbox', checked: own });
    var wm = h('input', { type: 'checkbox', checked: !!c.watch_major });
    var after = h('select', { multiple: 'multiple', size: Math.min(6, Math.max(3, (S.docker.rows || []).length)), 'aria-label': 'Update after' });
    (S.docker.rows || []).forEach(function (o) { if (o.name !== r.name) after.appendChild(h('option', { value: o.name, selected: (c.after || []).indexOf(o.name) >= 0 }, o.name)); });
    var sch = schedFields(c.schedule, true);
    inner.appendChild(h('div', null, h('label', { class: 'switch' }, notify, 'Send notifications for this container'), h('label', { class: 'switch' }, crit, 'Critical: always ask me before updating'), h('label', { class: 'switch' }, bk, 'Back up before updating (needs backups enabled in Settings)'), h('label', { class: 'switch' }, wm, 'Watch major versions: hold back an update that jumps to a new major version (databases, for example)')));
    inner.appendChild(h('div', null, h('div', { class: 'fld' }, h('label', null, 'Update after (dependencies)'), after, h('span', { class: 'hint' }, 'Hold ctrl / cmd to pick several. This container is updated only after the selected ones, and is left alone if one of them fails to update.'))));
    inner.appendChild(h('div', null, h('div', { class: 'fld' }, h('label', null, 'Wait after a new image appears'), h('div', { class: 'inl' }, del, cust), h('span', { class: 'hint' }, 'Counted from when this image was first seen, and not restarted by repeated checks.'))));
    inner.appendChild(h('div', null, h('label', { class: 'switch' }, ownCb, 'Use its own schedule'), h('div', { style: own ? '' : 'opacity:.5;pointer-events:none', id: 'own-' + r.name }, sch.el)));
    ownCb.addEventListener('change', function () { var e = inner.querySelector('[id="own-' + r.name + '"]'); e.style.opacity = ownCb.checked ? 1 : .5; e.style.pointerEvents = ownCb.checked ? 'auto' : 'none'; });
    inner.appendChild(h('div', null, h('button', { class: 'btn pri', onclick: function () {
      var d = cust.value !== '' ? parseInt(cust.value, 10) : (del.value === '' ? null : parseInt(del.value, 10));
      act('set_container', { names: [r.name], data: { notify: notify.checked, critical: crit.checked, backup: bk.checked, watch_major: wm.checked, after: Array.prototype.filter.call(after.options, function (o) { return o.selected; }).map(function (o) { return o.value; }), delay_hours: d, schedule: ownCb.checked ? sch.get() : null } }, 'Saved ' + r.name).then(function () { S.open[r.name] = false; load('docker'); });
    } }, 'Save'), ' ', h('button', { class: 'btn', onclick: function () { S.open[r.name] = false; render(); } }, 'Cancel')));
    return inner;
  }
  function schedFields(o, compact) {
    var fr = h('select'); FREQ.forEach(function (f) { fr.appendChild(h('option', { value: f[0], selected: o.frequency === f[0] }, f[1])); });
    var tm = h('input', { type: 'time', value: o.time || '04:00' });
    var wd = h('select'); DAYS.forEach(function (d, i) { wd.appendChild(h('option', { value: i, selected: +o.weekday === i }, d)); });
    var md = h('select'); for (var i = 1; i <= 28; i++) md.appendChild(h('option', { value: i, selected: +o.monthday === i }, 'Day ' + i));
    var wr = h('span', null, wd), mr = h('span', null, md), hr = h('span', { class: 'muted sm' }, 'starting at the time on the left');
    function sync() { wr.style.display = (fr.value === 'weekly' || fr.value === 'biweekly') ? '' : 'none'; mr.style.display = fr.value === 'monthly' ? '' : 'none'; }
    fr.addEventListener('change', sync); sync();
    var get = function () { return { frequency: fr.value, time: tm.value || '04:00', weekday: +wd.value, monthday: +md.value }; };
    return { el: h('div', null, h('div', { class: 'inl' }, fr, wr, mr, tm), schedPreview(get, [fr, wd, md, tm])), get: get };
  }
  /* the schedule in words, with its next runs: asked from the server, because its clock and time zone decide */
  function schedPreview(get, inputs) {
    var el = h('div', { class: 'hint muted sm schedtext' }), t = null;
    function upd() {
      clearTimeout(t);
      t = setTimeout(function () {
        var v = get(); if (!v) { el.textContent = ''; return; }
        api('sched_preview', v).then(function (j) { el.textContent = j.error ? '' : j.text + ' · next: ' + j.next.slice(0, 2).map(when).join(', then '); });
      }, 250);
    }
    inputs.forEach(function (i) { i.addEventListener('change', upd); }); upd();
    return el;
  }

  /* ---------- update preview ---------- */
  function previewUpdate(names, opts) {
    opts = opts || {};
    if (!names.length) { toast('Nothing to update.'); return; }
    api('preview', { names: names.join(',') }).then(function (j) {
      if (j.error) { toast(j.error, true); return; }
      if (!j.rows.length) { toast('None of these containers has an update.'); return; }
      var m = modal('Update ' + j.rows.length + ' container(s)?');
      m.body.appendChild(h('p', { class: 'muted' }, 'Each container is stopped, recreated from its Unraid template with the new image, and started again. Short downtime is expected.' + (j.verify ? ' Afterwards its health is checked.' : '')));
      if (opts.allow_major) m.body.appendChild(h('div', { class: 'banner' }, 'This installs a different MAJOR version. Back up its data first: databases usually cannot go back to an older version afterwards.'));
      var t = h('table'); t.appendChild(h('thead', null, h('tr', null, h('th', null, 'Container'), h('th', null, 'Current → new'), h('th', null, 'Notes'))));
      var tb = h('tbody'); j.rows.forEach(function (r) {
        tb.appendChild(h('tr', null, h('td', null, r.name, h('div', { class: 'muted sm' }, r.image)), h('td', { class: 'digest' }, r.current.slice(7) + ' → ' + r.new.slice(7)),
          h('td', null, !r.managed ? h('span', { class: 'badge bad' }, 'cannot be updated here') : null, r.critical ? h('span', { class: 'badge warn' }, 'critical') : null, r.backup ? h('span', { class: 'badge' }, 'backup first') : null, 'will restart')));
      }); t.appendChild(tb); m.body.appendChild(t);
      var go = h('button', { class: 'btn pri', onclick: function () { m.close(); runJob('docker', j.rows.filter(function (r) { return r.managed; }).map(function (r) { return r.name; }), 'Update started', opts.allow_major ? { allow_major: '1' } : null); } }, opts.allow_major ? 'Update anyway' : 'Update now');
      m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
    });
  }
  function modal(title) {
    var bg = h('div', { class: 'modal-bg' }), body = h('div'), foot = h('div', { class: 'foot' });
    function close() { if (bg.parentNode) bg.parentNode.removeChild(bg); }
    bg.appendChild(h('div', { class: 'modal', role: 'dialog' }, h('h3', null, title), body, foot));
    bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
    layer.appendChild(bg); return { body: body, foot: foot, close: close };
  }

  /* ---------- Plugins ---------- */
  function vPlugins() {
    var w = h('div');
    if (!S.plugins) { w.appendChild(h('div', { class: 'dau-loading' }, 'Loading plugins')); return w; }
    var auto = S.plugins.auto_update;
    w.appendChild(h('div', { class: 'banner' + (auto ? ' ok' : '') }, auto ? 'Automatic plugin updates are ON. Only plugins set to "Auto update" are installed, after safety checks.' : 'Automatic plugin updates are OFF (default). Updates are only reported. Turn them on in Settings > Plugins.'));
    var q = h('input', { type: 'search', placeholder: 'Search…', value: S.pq, oninput: function () { S.pq = q.value; var p = q.selectionStart; render(); var n = root.querySelector('input[type=search]'); if (n) { n.focus(); n.setSelectionRange(p, p); } } });
    var f = h('select', { onchange: function () { S.pfilter = f.value; render(); } }); [['all', 'All'], ['updates', 'Update available'], ['auto', 'Auto update'], ['blocked', 'Blocked']].forEach(function (o) { f.appendChild(h('option', { value: o[0], selected: o[0] === S.pfilter }, o[1])); });
    var rows = S.plugins.rows.filter(function (r) { return (!S.pq || r.name.toLowerCase().indexOf(S.pq.toLowerCase()) >= 0) && (S.pfilter === 'all' || (S.pfilter === 'updates' && r.latest) || (S.pfilter === 'auto' && r.policy === 'auto') || (S.pfilter === 'blocked' && r.state === 'blocked')); });
    var sel = rows.map(function (r) { return r.file; }).filter(function (n) { return S.psel[n]; });
    var bulk = h('select', { disabled: !sel.length, onchange: function () { if (!bulk.value) return; act('set_plugin', { files: sel, data: { policy: bulk.value } }, 'Policy set').then(function () { load('plugins'); }); } }, h('option', { value: '' }, 'Set policy for selected…'), POL.map(function (p) { return h('option', { value: p[0] }, p[1]); }));
    var up = h('button', { class: 'btn pri', disabled: S.ov.run.running || !sel.length, onclick: function () { confirmPlugins(sel); } }, 'Update selected…');
    var rmSel = h('button', { class: 'btn danger', disabled: S.ov.run.running || !sel.length, onclick: function () { removePlugins(sel); } }, 'Remove selected…');
    w.appendChild(h('div', { class: 'tools' }, q, f, bulk, h('span', { class: 'grow' }), up, rmSel));
    var t = h('table', { class: 'resp' });
    t.appendChild(h('thead', null, h('tr', null, h('th', null, ''), h('th', null, 'Plugin'), h('th', null, 'Installed'), h('th', null, 'Available'), h('th', null, 'Status'), h('th', null, 'Policy'), h('th', null, 'Last update'), h('th', null, ''))));
    var tb = h('tbody'); t.appendChild(tb);
    if (!rows.length) tb.appendChild(h('tr', null, h('td', { colspan: 8, class: 'muted' }, 'No plugins match.')));
    rows.forEach(function (r) {
      var st = STATE[r.state] || [r.state, ''];
      tb.appendChild(h('tr', { class: 'row' },
        h('td', null, r.self ? null : h('input', { type: 'checkbox', checked: !!S.psel[r.file], onchange: function (e) { S.psel[r.file] = e.target.checked; render(); } })),
        h('td', { class: 'name', 'data-l': 'Plugin' }, h('span', { class: 'nm' }, r.name), r.self ? h('div', { class: 'muted sm' }, 'this plugin - updated from Plugins only') : null),
        h('td', { 'data-l': 'Installed' }, r.version), h('td', { 'data-l': 'Available' }, r.latest || '-'),
        h('td', { 'data-l': 'Status' }, h('span', { class: 'badge ' + st[1], title: r.reason }, st[0]), r.eta ? h('div', { class: 'muted sm' }, 'until ' + when(r.eta)) : null, r.state === 'blocked' ? h('div', { class: 'muted sm' }, r.reason) : null),
        h('td', { 'data-l': 'Policy' }, r.self ? h('span', { class: 'muted' }, 'manual only') : polSel(r.policy, function (v) { act('set_plugin', { files: [r.file], data: { policy: v } }).then(function () { load('plugins'); }); })),
        h('td', { 'data-l': 'Last update' }, r.last ? h('span', null, h('span', { class: 'badge ' + (r.last.result === 'updated' ? 'ok' : 'bad') }, r.last.result), h('div', { class: 'muted sm' }, ago(r.last.ts))) : '-'),
        h('td', null, h('div', { class: 'inl' }, r.latest && !r.self ? h('button', { class: 'btn sm pri', disabled: S.ov.run.running, onclick: function () { confirmPlugins([r.file]); } }, 'Update') : null,
          r.latest ? h('button', { class: 'btn sm', onclick: function () { notesModal('plugin', r.file, r.name); } }, 'Notes') : null,
          r.latest && !r.self && !r.skipped ? h('button', { class: 'btn sm', disabled: S.ov.run.running, title: 'Ignore this version; a newer one is announced again', onclick: function () { skipAct('plugin', r.file, r.name); } }, 'Skip') : null,
          r.skipped ? h('button', { class: 'btn sm', disabled: S.ov.run.running, onclick: function () { skipAct('plugin', r.file, r.name, true); } }, 'Un-skip') : null,
          r.self ? null : h('button', { class: 'btn sm danger', disabled: S.ov.run.running, title: 'Uninstall this plugin', onclick: function () { removePlugins([r.file]); } }, 'Remove')))));
    });
    w.appendChild(t);
    return w;
  }
  function confirmPlugins(files) {
    var rows = S.plugins.rows.filter(function (r) { return files.indexOf(r.file) >= 0 && r.latest; });
    if (!rows.length) { toast('None of the selected plugins has an update.'); return; }
    var m = modal('Update ' + rows.length + ' plugin(s)?');
    m.body.appendChild(h('p', { class: 'muted' }, 'Plugins can run code as root. The plugin file is validated first (same source URL, no new download hosts, compatible with this Unraid version) and then installed with Unraid\'s own plugin tool. There is no automatic rollback.'));
    rows.forEach(function (r) { m.body.appendChild(h('div', null, h('b', null, r.name), ' ', r.version + ' → ' + r.latest)); });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel'));
    m.foot.appendChild(h('button', { class: 'btn pri', onclick: function () { m.close(); runJob('plugins', rows.map(function (r) { return r.file; }), 'Plugin update started'); } }, 'Update'));
  }

  /* ---------- remove containers / plugins (explicit, confirmed, run as a job) ---------- */
  function removeContainers(names) {
    var rows = S.docker.rows.filter(function (r) { return names.indexOf(r.name) >= 0; });
    if (!rows.length) { toast('Nothing selected.'); return; }
    var m = modal(rows.length === 1 ? 'Remove ' + rows[0].name + '?' : 'Remove ' + rows.length + ' containers?');
    rows.forEach(function (r) { m.body.appendChild(h('div', null, h('b', null, r.name), ' ', h('span', { class: 'muted sm' }, r.image), r.run_state === 'running' ? h('span', { class: 'badge warn' }, 'running: will be stopped') : null, !r.managed ? h('span', { class: 'badge' }, 'not from an Unraid template') : null)); });
    m.body.appendChild(h('p', { class: 'muted' }, 'Each container is stopped and removed. Its appdata folders are NOT deleted: afterwards the Cleanup tab can move unused ones to quarantine. A container that others use as their network is not removed.'));
    var img = h('input', { type: 'checkbox', checked: true }), tpl = h('input', { type: 'checkbox' }), ok = h('input', { type: 'checkbox' });
    m.body.appendChild(h('label', { class: 'switch' }, img, 'Also delete its image (kept automatically if another container uses it)'));
    m.body.appendChild(h('label', { class: 'switch' }, tpl, 'Archive its saved template'));
    m.body.appendChild(h('div', { class: 'muted sm' }, 'Off: the template stays, so you can reinstall it with the same settings from Apps > Previous Apps. On: it is moved to Cleanup > Saved templates, where it can be restored.'));
    m.body.appendChild(h('label', { class: 'switch' }, ok, rows.length === 1 ? 'I want to remove this container' : 'I want to remove these ' + rows.length + ' containers'));
    var go = h('button', { class: 'btn pri', disabled: true, onclick: function () { m.close(); rows.forEach(function (r) { S.sel[r.name] = false; }); runJob('remove_docker', rows.map(function (r) { return r.name; }), 'Removal started', { confirm: '1', image: img.checked ? '1' : '', template: tpl.checked ? '1' : '' }); } }, 'Remove');
    ok.addEventListener('change', function () { go.disabled = !ok.checked; });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
  }
  function removePlugins(files) {
    var rows = S.plugins.rows.filter(function (r) { return files.indexOf(r.file) >= 0 && !r.self; });
    if (!rows.length) { toast('Nothing to remove.'); return; }
    var m = modal(rows.length === 1 ? 'Remove the plugin ' + rows[0].name + '?' : 'Remove ' + rows.length + ' plugins?');
    rows.forEach(function (r) { m.body.appendChild(h('div', null, h('b', null, r.name), ' ', h('span', { class: 'muted sm' }, r.version))); });
    m.body.appendChild(h('p', { class: 'muted' }, 'Each plugin is uninstalled with Unraid\'s own plugin tool, the same as Plugins > Remove. What it leaves behind (usually its settings folder on the flash drive) is up to the plugin. Other plugins that need it may stop working.'));
    var ok = h('input', { type: 'checkbox' });
    m.body.appendChild(h('label', { class: 'switch' }, ok, rows.length === 1 ? 'I want to remove this plugin' : 'I want to remove these ' + rows.length + ' plugins'));
    var go = h('button', { class: 'btn pri', disabled: true, onclick: function () { m.close(); rows.forEach(function (r) { S.psel[r.file] = false; }); runJob('remove_plugins', rows.map(function (r) { return r.file; }), 'Removal started', { confirm: '1' }); } }, 'Remove');
    ok.addEventListener('change', function () { go.disabled = !ok.checked; });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
  }

  /* ---------- OS ---------- */
  var OSSTATE = { up_to_date: ['Up to date', 'ok'], notify_only: ['Notify only', ''], not_confirmed: ['Not confirmed', 'warn'], prerelease: ['Pre-release: manual only', 'warn'], blocked: ['Blocked', 'bad'],
    waiting_delay: ['Waiting', 'warn'], window_closed: ['Waiting for window', 'warn'], skipped: ['Release skipped', ''], staged: ['Installed, reboot needed', 'warn'], awaiting_confirmation: ['Waiting for your confirmation', 'warn'], ready: ['Ready', 'ok'] };
  function confirmOsInstall(reboot) {
    var o = S.os.os, m = modal(reboot ? 'Install Unraid ' + o.available + ' and reboot now?' : 'Install Unraid ' + o.available + ' now?');
    m.body.appendChild(h('p', null, 'Installed: ' + (S.os.installed || '?') + '  →  ' + o.available));
    m.body.appendChild(h('p', { class: 'muted' }, 'This runs Unraid\'s own "Update OS" install. The new release is written to the flash drive and the previous one is kept (Tools > Update OS > Restore).' + (reboot ? ' The server then REBOOTS immediately: containers and VMs are stopped and your network connection to the server drops.' : ' The new release only becomes active after a reboot, which you do yourself.')));
    m.body.appendChild(h('p', { class: 'muted' }, 'Read the release notes first and make sure you have a recent flash drive backup.' + (S.os.stable === false ? ' This is a PRE-RELEASE.' : '')));
    var rb = S.os.reboot, ar = S.os.array;
    if (reboot && rb && !rb.ok) m.body.appendChild(h('div', { class: 'banner bad' }, 'A reboot would be postponed right now: ' + rb.reason));
    var pre = S.os.pre || {}, pp = null, pd = null;
    if (!o.pending) {
      pp = h('input', { type: 'checkbox', checked: !!pre.plugins }); pd = h('input', { type: 'checkbox', checked: !!pre.docker });
      m.body.appendChild(h('div', { class: 'panel' }, h('b', null, 'Before the OS update'),
        h('label', { class: 'switch' }, pp, 'Update plugins first (only those set to Auto update, safety-checked as usual)'),
        h('label', { class: 'switch' }, pd, 'Update Docker containers first (' + (S.os.pre_containers || 0) + ' set to Auto update, critical ones excluded)'),
        h('div', { class: 'muted sm' }, 'The OS update only starts if the required steps succeeded and the system check passes. You can abort at any time before the install.')));
    }
    var cb = h('input', { type: 'checkbox' });
    m.body.appendChild(h('label', { class: 'switch' }, cb, 'I have read this and want to continue'));
    var go = h('button', { class: 'btn pri', disabled: true, onclick: function () { m.close(); var x = { confirm: '1', reboot: reboot ? '1' : '0' }; if (pp) { x.pre_plugins = pp.checked ? '1' : '0'; x.pre_docker = pd.checked ? '1' : '0'; } runJob('os_install', [], 'OS update started', x); } }, reboot ? 'Install and reboot' : 'Install');
    cb.addEventListener('change', function () { go.disabled = !cb.checked; });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
  }
  function confirmWorkflow(wf) {
    var m = modal('Install Unraid ' + wf.version + (wf.reboot ? ' and reboot?' : '?'));
    m.body.appendChild(h('p', { class: 'muted' }, 'The prerequisites passed. This installs the OS update through Unraid\'s own installer' + (wf.reboot ? ' and then REBOOTS the server (never during a parity check).' : '; it becomes active when you reboot.') + ' The previous release stays on the flash drive.'));
    var cb = h('input', { type: 'checkbox' }); m.body.appendChild(h('label', { class: 'switch' }, cb, 'I want to continue'));
    var go = h('button', { class: 'btn pri', disabled: true, onclick: function () { m.close(); runJob('os_confirm', [], 'OS update started', { confirm: '1' }); } }, 'Install');
    cb.addEventListener('change', function () { go.disabled = !cb.checked; });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
  }
  function vOS() {
    var w = h('div');
    if (!S.os) { w.appendChild(h('div', { class: 'dau-loading' }, 'Loading')); return w; }
    var o = S.os.os, mode = S.os.mode, es = S.os.eval || { state: 'up_to_date', reason: '' }, st = OSSTATE[es.state] || [es.state, ''];
    var modeText = { notify: 'Check and notify only. Nothing is installed and the server is never rebooted.', install: 'Install automatically, then wait for you to reboot.', install_reboot: 'Install automatically and reboot automatically (inside the maintenance window, never during a parity check).' }[mode] || '';
    w.appendChild(h('div', { class: 'banner' + (mode === 'notify' ? '' : ' warn') }, h('b', null, 'Mode: '), modeText, ' Change it in Settings.'));
    var wf = S.os.workflow, rp = S.os.report;
    if (wf && wf.state === 'awaiting_confirmation') {
      w.appendChild(h('div', { class: 'banner warn' }, h('b', null, 'Ready to install Unraid ' + wf.version + '. '), 'The plugins were updated and the system check passed. Nothing has been installed yet.',
        h('div', { class: 'actions' }, h('button', { class: 'btn pri', disabled: S.ov.run.running, onclick: function () { confirmWorkflow(wf); } }, wf.reboot ? 'Confirm: install and reboot…' : 'Confirm: install…'),
          h('button', { class: 'btn', onclick: function () { act('os_abort', {}, 'Workflow cancelled. Nothing was installed.').then(function () { load('os'); }); } }, 'Abort'))));
    } else if (wf && wf.state === 'running') {
      w.appendChild(h('div', { class: 'banner' }, 'Pre-OS maintenance is running (plugins, then Docker if enabled). The OS update starts only if it succeeds. ', h('button', { class: 'btn sm danger', onclick: function () { act('os_abort', {}, 'Abort requested: stops after the current step. Nothing will be installed.'); } }, 'Abort')));
    }
    if (rp) w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Last pre-OS workflow'), h('div', { class: 'inl' }, h('span', { class: 'badge ' + ({ ok: 'ok', failed: 'bad', aborted: 'warn', waiting: 'warn' }[rp.result] || '') }, rp.result === 'ok' ? 'passed' : rp.result), h('span', { class: 'muted' }, 'for ' + rp.version + ' · ' + when(rp.at))),
      h('pre', { class: 'log' }, 'Plugins: ' + (rp.plugins ? rp.plugins.updated + ' updated, ' + rp.plugins.failed + ' failed, ' + rp.plugins.skipped + ' skipped' : 'not part of this workflow') + '\nDocker: ' + (rp.docker ? rp.docker.updated + ' updated, ' + rp.docker.failed + ' failed, ' + rp.docker.skipped + ' skipped' : 'not part of this workflow') + (rp.health ? '\nSystem check: ' + rp.health : '') + (rp.issues && rp.issues.length ? '\nIssues: ' + rp.issues.join(' ') : ''))));
    if (o.pending) w.appendChild(h('div', { class: 'banner warn' }, 'Unraid ' + o.pending.to + ' is installed and waiting for a reboot (installed ' + when(o.pending.at) + '). After the reboot this plugin checks that the new release is running.'));
    w.appendChild(h('div', { class: 'cards' }, card('Installed', S.os.installed || '?'), card('Available', o.available || 'None', o.available ? 'since ' + when(o.first_seen) + (S.os.stable === false ? ' · pre-release' : '') : 'Up to date', o.available ? 'hot' : ''), card('Last check', ago(o.last_check), o.last_error ? 'Failed: ' + o.last_error : 'OK', o.last_error ? 'bad' : '')));
    if (o.available) {
      w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Automatic handling'),
        h('div', { class: 'inl' }, h('span', { class: 'badge ' + st[1] }, st[0]), h('span', { class: 'muted' }, es.reason || ''), es.eta ? h('span', { class: 'muted' }, 'earliest ' + when(es.eta)) : null),
        mode !== 'notify' ? h('div', { class: 'muted sm' }, 'Maintenance window: ' + S.os.window_text) : null));
      w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Release notes (excerpt)'), h('pre', { class: 'log' }, o.notes || 'Not available. Open Tools > Update OS to read the full release notes.'),
        h('p', { class: 'muted sm' }, 'Back up your flash drive first and read the full release notes.')));
    }
    var busy = S.ov.run.running, acts = h('div', { class: 'actions' }, h('button', { class: 'btn', disabled: busy, onclick: function () { runJob('os', [], 'Checking'); } }, 'Check for OS update'));
    if (o.available && !o.pending) acts.appendChild(S.os.skipped ? h('button', { class: 'btn', disabled: busy, onclick: function () { skipAct('os', '', 'Unraid OS', true); } }, 'Un-skip this release') : h('button', { class: 'btn', disabled: busy, title: 'No automatic action for this release; a newer one is announced again', onclick: function () { skipAct('os', '', 'Unraid OS'); } }, 'Skip this release'));
    if (o.available && !o.pending) {
      acts.appendChild(h('button', { class: 'btn', disabled: busy, onclick: function () { confirmOsInstall(false); } }, 'Install update…'));
      acts.appendChild(h('button', { class: 'btn danger', disabled: busy, onclick: function () { confirmOsInstall(true); } }, 'Install and reboot…'));
    }
    if (o.pending) acts.appendChild(h('button', { class: 'btn danger', disabled: busy, onclick: function () { confirmOsInstall(true); } }, 'Reboot now…'));
    w.appendChild(acts);
    return w;
  }

  /* ---------- Cleanup ---------- */
  function bytes(b) { b = +b || 0; return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' kB'; }
  function ageText(sec) { sec = +sec || 0; return sec < 86400 ? 'today' : sec < 86400 * 60 ? Math.round(sec / 86400) + ' days ago' : sec < 86400 * 730 ? Math.round(sec / 86400 / 30) + ' months ago' : Math.round(sec / 86400 / 365) + ' years ago'; }
  function cleanSum() { var c = S.ov.cards.cleanup || {}; return c; }
  function cleanCount(c) { return c ? ((c.appdata || {}).n || 0) + ((c.images || {}).n || 0) + ((c.volumes || {}).n || 0) + ((c.containers || {}).n || 0) : 0; }
  var CCAT = [
    ['app', 'Appdata folders', 'Folders in your appdata share that no container uses any more.'],
    ['img', 'Docker images', 'Images that no container uses: leftovers from updates and old downloads.'],
    ['vol', 'Docker volumes', 'Volumes that no container uses. They can hold data, so none is pre-selected.'],
    ['con', 'Stopped containers', 'Stopped containers that Unraid did not create.'],
    ['tpl', 'Saved templates', 'Saved templates of containers that were removed.'],
    ['qua', 'Quarantine', 'Folders you moved aside. Restore them, or delete them for good.']
  ];
  var STATUS = { eligible: ['ok', 'Eligible'], review: ['warn', 'Review first'], protected: ['off', 'Protected'], inuse: ['info', 'In use'] };
  function sel() { return S.cs = S.cs || { app: {}, img: {}, vol: {}, con: {} }; }
  /* every candidate in one shape: key, name, type, reason, status, size, details */
  function items(cat) {
    var out = [];
    if (cat === 'app' && S.ad && S.ad.orphans) {
      S.ad.orphans.forEach(function (o) { out.push({ cat: cat, key: o.id, name: o.name, type: 'Appdata folder', reason: o.reason, status: o.status, size: o.size, details: [['Location', o.path], ['Last changed', ageText(o.age)], ['Saved template', o.tpl.length ? o.tpl.join(', ') : 'none']], raw: o }); });
      (S.ad.protected || []).forEach(function (o) { out.push({ cat: cat, key: o.id, name: o.name, type: 'Appdata folder', reason: o.reason, status: 'protected', size: null, details: [['Location', o.path]], raw: o }); });
    }
    if (cat === 'img' && S.clean) S.clean.images.forEach(function (i) { out.push({ cat: cat, key: i.id, name: i.tags.length ? i.tags.join(', ') : '(no name)', type: i.dangling ? 'Dangling image' : 'Unused image', reason: i.reason, status: i.status, size: i.size, details: [['Image ID', i.id], ['Downloaded', i.created]], raw: i }); });
    if (cat === 'vol' && S.clean) (S.clean.volumes || []).forEach(function (v) { out.push({ cat: cat, key: v.name, name: v.anonymous ? v.name.slice(0, 12) + '…' : v.name, type: v.anonymous ? 'Unnamed volume' : 'Named volume', reason: v.reason, status: v.status, size: v.size, details: [['Volume name', v.name]], raw: v }); });
    if (cat === 'con' && S.clean) S.clean.containers.forEach(function (x) { out.push({ cat: cat, key: x.name, name: x.name, type: 'Stopped container', reason: x.reason, status: x.status, size: x.size, details: [['Image', x.image], ['State', x.state], ['Stopped', x.finished ? x.finished.slice(0, 10) : '-']], raw: x }); });
    return out;
  }
  function pickable(it) { return it.status === 'eligible' || it.status === 'review'; }
  function selected() {
    var r = { app: [], img: [], vol: [], con: [], count: 0, size: 0 };
    ['app', 'img', 'vol', 'con'].forEach(function (c) { items(c).forEach(function (it) { if (pickable(it) && sel()[c][it.key]) { r[c].push(it); r.count++; r.size += it.size || 0; } }); });
    return r;
  }
  function scanAll() {
    if (S.cscanning || S.cbusy) return Promise.resolve();
    S.cscanning = true; render();
    return api('cleanup_all', {}, true).then(function (j) {
      S.cscanning = false;
      if (j.error) { S.cerr = { all: j.error }; toast(j.error, true); render(); return; }
      S.cerr = { app: j.appdata && j.appdata.ok === false ? j.appdata.error : '', docker: j.docker && j.docker.ok === false ? j.docker.error : '' };
      S.ad = j.appdata && j.appdata.ok !== false ? j.appdata : null; S.clean = j.docker && j.docker.ok !== false ? j.docker : null; S.cscan = { at: Date.now() / 1000 };
      S.cs = { app: {}, img: {}, vol: {}, con: {} };
      if (j.summary) S.ov.cards.cleanup = Object.assign({}, j.summary, { quarantine: S.ov.cards.cleanup.quarantine });
      S.qr = null; S.tp = null; render();
    });
  }
  /* a section that folds away; empty ones start folded */
  function csec(id, title, status, open, kids) {
    var on = S.copen && id in S.copen ? S.copen[id] : open;
    var d = h('details', { class: 'csec', id: 'csec-' + id, open: on ? 'open' : null });
    d.addEventListener('toggle', function () { S.copen = S.copen || {}; S.copen[id] = d.open; });
    d.appendChild(h('summary', null, h('b', null, title), h('span', { class: 'muted' }, status)));
    d.appendChild(h('div', { class: 'cbody' }, kids));
    return d;
  }
  function statusBadge(st) { var s = STATUS[st] || ['', st]; return h('span', { class: 'badge ' + s[0] }, s[1]); }
  function moreInfo(rows) { var d = h('details', { class: 'more' }, h('summary', null, 'Technical details')); rows.forEach(function (r) { d.appendChild(h('div', { class: 'sm' }, h('span', { class: 'muted' }, r[0] + ': '), h('span', { class: 'mono' }, r[1]))); }); return d; }
  function ctable(cat, list) {
    var t = h('table', { class: 'resp' }); t.appendChild(h('thead', null, h('tr', null, h('th', null, ''), h('th', null, 'Item'), h('th', null, 'Status'), h('th', null, 'Size'), h('th', null, ''))));
    var tb = h('tbody'); t.appendChild(tb);
    list.forEach(function (it) {
      var can = pickable(it) && !S.cbusy;
      var cb = h('input', { type: 'checkbox', checked: !!sel()[cat][it.key], disabled: !can, 'aria-label': 'Select ' + it.name, title: can ? '' : it.status === 'protected' ? 'Protected items cannot be cleaned' : '', onchange: function (e) { sel()[cat][it.key] = e.target.checked; render(); } });
      var acts = h('td', { class: 'acts' });
      if (cat === 'app' && it.status !== 'protected') {
        acts.appendChild(h('button', { class: 'btn sm', onclick: function () { peekFolder(it.raw); } }, 'Contents'));
        acts.appendChild(h('button', { class: 'btn sm', title: 'Never list this folder again. You can undo this from the Ignored list.', onclick: function () { act('appdata_ignore', { name: it.name, add: '1' }, it.name + ' is excluded from future scans').then(scanAll); } }, 'Exclude'));
      }
      tb.appendChild(h('tr', { class: 'row' }, h('td', null, cb), h('td', { class: 'name', 'data-l': 'Item' }, h('span', { class: 'nm' }, it.name), h('div', { class: 'muted sm' }, it.type + ' · ' + it.reason), moreInfo(it.details)),
        h('td', { 'data-l': 'Status' }, statusBadge(it.status)), h('td', { 'data-l': 'Size' }, it.size == null ? '-' : bytes(it.size)), acts));
    });
    return t;
  }
  function catRows(cat) { return items(cat); }
  function catStat(cat) {   // count and size of what can be reviewed (not protected)
    if (cat === 'tpl') return S.tp && S.tp.templates ? { n: S.tp.templates.length, size: null } : null;
    if (cat === 'qua') return S.qr && S.qr.rows ? { n: S.qr.rows.length, size: S.qr.rows.reduce(function (a, e) { return a + (+e.size || 0); }, 0) } : null;
    var l = catRows(cat).filter(pickable), has = cat === 'app' ? !!S.ad : !!S.clean;
    return has ? { n: l.length, size: l.reduce(function (a, x) { return a + (x.size || 0); }, 0) } : null;
  }
  function stage() { return S.cscanning ? 1 : !S.cscan ? 1 : selected().count ? 3 : 2; }
  function vCleanup() {
    var w = h('div'), busy = S.ov.run.running || S.cscanning || S.cbusy, sm = cleanSum();
    /* result of the last cleanup, until dismissed */
    if (S.cresult) {
      var r = S.cresult;
      var p = h('div', { class: 'panel' }, h('h3', null, r.fail ? 'Cleanup finished with problems' : 'Cleanup finished'),
        h('div', { class: 'cards' }, card('Cleaned', r.done.length, 'items'), card('Skipped', r.skipped.length, 'no longer valid'), card('Failed', r.failed.length, r.failed.length ? 'see below' : 'none', r.failed.length ? 'bad' : ''), card('Size of cleaned items', bytes(r.size), 'measured before removal')));
      p.appendChild(h('p', { class: 'muted sm' }, 'This is the size of what was cleaned, read before it was removed, not space read back from the disk. ' + (r.moved ? 'Folders moved to quarantine free no space until you delete them there. ' : '') + 'Images that share layers free less than their sizes added up.'));
      r.skipped.concat(r.failed).forEach(function (x) { p.appendChild(h('div', { class: 'sm' }, h('span', { class: 'badge ' + (r.failed.indexOf(x) >= 0 ? 'bad' : 'warn') }, r.failed.indexOf(x) >= 0 ? 'Failed' : 'Skipped'), ' ', h('b', null, x.what), ' ', h('span', { class: 'muted' }, x.why))); });
      p.appendChild(h('div', { class: 'actions' }, h('button', { class: 'btn', onclick: function () { S.cresult = null; render(); } }, 'Dismiss')));
      w.appendChild(p);
    }
    /* overview: what the last scan found, one primary action */
    var scanned = !!S.cscan, at = scanned ? S.cscan.at : sm.at, cands = 0, size = 0, sizeKnown = scanned;
    ['app', 'img', 'vol', 'con'].forEach(function (c) { var st = catStat(c); if (st) { cands += st.n; size += st.size || 0; } });
    if (!scanned) { cands = cleanCount(sm); size = ((sm.appdata || {}).size || 0) + ((sm.images || {}).size || 0) + ((sm.volumes || {}).size || 0) + ((sm.containers || {}).size || 0); }
    var statusText = S.cscanning ? 'Scanning…' : S.cbusy ? 'Cleaning…' : S.ov.run.running ? 'Another job is running' : 'Idle';
    var st3 = stage(), steps = h('div', { class: 'steps', 'aria-label': 'Cleanup steps' });
    [['1', 'Scan'], ['2', 'Review'], ['3', 'Clean']].forEach(function (x, i) { steps.appendChild(h('span', { class: 'step' + (st3 === i + 1 ? ' on' : st3 > i + 1 ? ' done' : ''), 'aria-current': st3 === i + 1 ? 'step' : null }, x[0] + '. ' + x[1])); });
    w.appendChild(h('div', { class: 'panel' }, steps, h('div', { class: 'inl' }, h('div', { class: 'grow' },
      h('div', { class: 'k muted' }, at ? (scanned ? 'Last scan: just now' : 'Last scan: ' + ago(at)) : 'Not scanned yet'),
      h('div', { class: 'big' }, at ? cands + ' item' + (cands === 1 ? '' : 's') + ' to review' : '–'),
      h('div', { class: 'muted sm' }, at ? 'Estimated size: ' + bytes(size) + (scanned ? '' : ' (from the last scan)') + ' · Status: ' + statusText : 'Scanning only looks. Nothing is removed until you select items and confirm. Status: ' + statusText)),
      h('button', { class: 'btn pri', disabled: busy, title: busy ? 'Wait until the current job has finished' : '', onclick: scanAll }, S.cscanning ? 'Scanning…' : 'Scan for Cleanup Items'))));
    if (S.cerr && S.cerr.all) w.appendChild(h('div', { class: 'banner bad' }, h('b', null, 'The scan could not run. '), S.cerr.all + ' Wait for the other job to finish, or reload the page, then scan again.'));
    if (S.cerr && S.cerr.docker) w.appendChild(h('div', { class: 'banner bad' }, h('b', null, 'Docker could not be inspected. '), S.cerr.docker + ' Images, volumes and containers (and the check which appdata folders are in use) need the Docker service. Start it under Settings > Docker, then scan again.'));
    else if (S.cerr && S.cerr.app) w.appendChild(h('div', { class: 'banner bad' }, h('b', null, 'Appdata could not be inspected. '), S.cerr.app));
    /* categories */
    var cards = h('div', { class: 'ccats' });
    CCAT.forEach(function (c) {
      var st = catStat(c[0]);
      cards.appendChild(h('div', { class: 'ccat' }, h('h4', null, c[1]), h('div', { class: 'cn' }, st ? st.n : '–'), h('div', { class: 'muted sm' }, st ? (st.size == null ? '' : bytes(st.size)) : 'not scanned'), h('div', { class: 'muted sm cdesc' }, c[2]),
        h('button', { class: 'btn sm', onclick: function () { S.copen = S.copen || {}; S.copen[c[0]] = true; render(); var e = document.getElementById('csec-' + c[0]); if (e && e.scrollIntoView) e.scrollIntoView({ block: 'start' }); } }, 'Review')));
    });
    w.appendChild(cards);
    if (scanned && !cands && !S.cerr.docker && !S.cerr.app) w.appendChild(h('div', { class: 'banner ok' }, h('b', null, 'Nothing to clean up. '), 'No unused appdata folders, images, volumes or leftover containers were found.'));
    /* review sections */
    var a = S.ad, d = S.clean;
    var secApp = h('div');
    secApp.appendChild(h('div', { class: 'banner' }, 'These folders are not used by any container, running or stopped. Safe mode is ' + (a ? (a.safe_mode ? 'on: selected folders are moved to a hidden quarantine folder and can be restored.' : 'off: selected folders are deleted for good.') : 'checked when you scan.')));
    if (a) {
      var la = catRows('app');
      if (!la.length) secApp.appendChild(h('p', { class: 'muted' }, 'No unused appdata folders.')); else secApp.appendChild(ctable('app', la));
      if (a.in_use) {
        var iu = h('details', { class: 'more' }, h('summary', null, 'Currently in use (' + a.in_use + '): not listed above, cannot be cleaned'));
        (a.in_use_rows || []).forEach(function (x) { iu.appendChild(h('div', { class: 'sm' }, h('b', null, x.name), h('span', { class: 'muted' }, ' used by ' + x.by.join(', ')))); });
        secApp.appendChild(iu);
      }
      if (a.broad && a.broad.length) secApp.appendChild(h('p', { class: 'muted sm' }, 'These containers map a whole parent folder (for example /mnt/user): ' + a.broad.join(', ') + '. That does not count as using one appdata folder, so it does not block cleanup.'));
      if (a.ignored && a.ignored.length) {
        var ig = h('div', { class: 'inl' }, h('span', { class: 'muted sm' }, 'Excluded from scans:'));
        a.ignored.forEach(function (n) { ig.appendChild(h('button', { class: 'btn sm', title: 'Include this folder in scans again', onclick: function () { act('appdata_ignore', { name: n, add: '0' }).then(scanAll); } }, n + ' ✕')); });
        secApp.appendChild(ig);
      }
    } else secApp.appendChild(h('p', { class: 'muted' }, scanned ? 'Not available (see the message above).' : 'Press "Scan for Cleanup Items" to look.'));
    var secImg = h('div'); secImg.appendChild(h('div', { class: 'banner' }, 'Dangling images have no name; unused images were downloaded once and no container uses them now. Images that a container uses (' + (d ? d.in_use_images : 'N') + ' right now) are never listed. Volumes and appdata are never touched by image cleanup.'));
    secImg.appendChild(d ? (catRows('img').length ? ctable('img', catRows('img')) : h('p', { class: 'muted' }, 'No unused images.')) : h('p', { class: 'muted' }, scanned ? 'Not available (see the message above).' : 'Press "Scan for Cleanup Items" to look.'));
    var secVol = h('div'); secVol.appendChild(h('div', { class: 'banner warn' }, h('b', null, 'Volumes hold data. '), 'Removing one deletes its data and cannot be undone. None is selected for you and Select all eligible never picks them. Docker refuses to remove a volume that is in use.'));
    secVol.appendChild(d ? (catRows('vol').length ? ctable('vol', catRows('vol')) : h('p', { class: 'muted' }, 'No unused volumes.')) : h('p', { class: 'muted' }, scanned ? 'Not available (see the message above).' : 'Press "Scan for Cleanup Items" to look.'));
    var secCon = h('div'); secCon.appendChild(d ? (catRows('con').length ? ctable('con', catRows('con')) : h('p', { class: 'muted' }, 'No leftover containers. A running container is never listed.')) : h('p', { class: 'muted' }, scanned ? 'Not available (see the message above).' : 'Press "Scan for Cleanup Items" to look.'));
    var q = S.qr && S.qr.rows ? S.qr.rows : null;
    function stx(c) { var st = catStat(c); return st ? st.n + ' to review' + (st.size ? ' · ' + bytes(st.size) : '') : 'not scanned'; }
    w.appendChild(csec('app', 'Appdata folders', stx('app'), !!(a && a.orphans && a.orphans.length), secApp));
    w.appendChild(csec('img', 'Docker images', stx('img'), !!(d && d.images.length), secImg));
    w.appendChild(csec('vol', 'Docker volumes', stx('vol'), !!(d && (d.volumes || []).length), secVol));
    w.appendChild(csec('con', 'Stopped containers', stx('con'), !!(d && d.containers.length), secCon));
    w.appendChild(csec('tpl', 'Saved templates', S.tp && S.tp.templates ? S.tp.templates.length + ' of removed containers' + (S.tp.backups.length ? ' · ' + S.tp.backups.length + ' backup(s)' : '') : 'loading', !!(S.tp && S.tp.templates && (S.tp.templates.length || S.tp.backups.length)), vTemplates()));
    w.appendChild(csec('qua', 'Quarantine', q ? q.length + ' folder(s)' : 'loading', !!(q && q.length), vQuarantine()));
    var hist = (S.hist || []).filter(function (e) { return e.type === 'cleanup'; }).slice(0, 10);
    if (hist.length) {
      var t = h('table', { class: 'resp' }); t.appendChild(h('thead', null, h('tr', null, h('th', null, 'Date'), h('th', null, 'Result'), h('th', null, 'What happened'))));
      var tb = h('tbody'); t.appendChild(tb);
      hist.forEach(function (e) { tb.appendChild(h('tr', { class: 'row' }, h('td', { 'data-l': 'Date' }, when(e.ts)), h('td', { 'data-l': 'Result' }, h('span', { class: 'badge ' + (e.result === 'failed' ? 'bad' : 'ok') }, e.result === 'failed' ? 'Failed' : 'Completed')), h('td', { 'data-l': 'What happened', class: 'name' }, h('span', { class: 'nm' }, e.name), e.message ? h('div', { class: 'muted sm' }, e.message) : null))); });
      w.appendChild(csec('hist', 'Cleanup history', hist.length + ' recent', false, h('div', { class: 'panel' }, t)));
    }
    /* action bar: the same place for the same actions */
    var s = selected(), eligibleN = 0; ['app', 'img'].forEach(function (c) { catRows(c).forEach(function (it) { if (it.status === 'eligible') eligibleN++; }); });
    var why = busy ? 'Wait until the current job has finished' : !s.count ? 'Select items to review first' : '';
    w.appendChild(h('div', { class: 'cbar' }, h('div', { class: 'grow' }, h('b', null, s.count + ' selected'), h('span', { class: 'muted' }, s.count ? ' · estimated ' + bytes(s.size) : (scanned ? ' · nothing selected' : ' · scan first'))),
      h('button', { class: 'btn', disabled: busy || !eligibleN, title: !eligibleN ? 'There is no eligible item (items marked "Review first" are never picked for you)' : 'Selects only items marked Eligible', onclick: function () { selectEligible(); } }, 'Select all eligible'),
      h('button', { class: 'btn', disabled: !s.count || busy, title: why, onclick: function () { S.cs = { app: {}, img: {}, vol: {}, con: {} }; render(); } }, 'Clear selection'),
      h('button', { class: 'btn', disabled: !s.count || busy, title: why, onclick: function () { runCleanup(true); } }, 'Preview (dry run)'),
      h('button', { class: 'btn danger', disabled: !s.count || busy, title: why, onclick: function () { runCleanup(false); } }, 'Clean Selected Items')));
    return w;
  }
  /* only items the server marked Eligible; "Review first", protected and in-use items are never picked */
  function selectEligible() { S.cs = { app: {}, img: {}, vol: {}, con: {} }; ['app', 'img'].forEach(function (c) { catRows(c).forEach(function (it) { if (it.status === 'eligible') S.cs[c][it.key] = true; }); }); render(); }
  function peekFolder(o) {
    api('appdata_peek', { id: o.id }).then(function (j) {
      var m = modal('Inside ' + o.name);
      if (j.error || !j.ok) { m.body.appendChild(h('p', null, j.error || 'Could not look inside.')); }
      else {
        m.body.appendChild(h('p', { class: 'muted' }, j.count + ' item(s) directly inside. The biggest:'));
        j.items.forEach(function (i) { m.body.appendChild(h('div', null, i.dir ? '📁 ' : i.link ? '↪ ' : '', h('b', null, i.name), ' ', h('span', { class: 'muted' }, bytes(i.size)))); });
        if (j.more) m.body.appendChild(h('div', { class: 'muted' }, '… and ' + j.more + ' more'));
      }
      m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Close'));
    });
  }
  /* Step 3: preview (dry run) or clean. One summary lists every operation, whether it can be undone, and the warnings. */
  function runCleanup(dry) {
    var s = selected(), safe = S.ad ? S.ad.safe_mode : true, del = s.app.length && !safe;
    var m = modal(dry ? 'Preview (dry run): nothing will be changed' : 'Clean ' + s.count + ' selected item(s)?');
    var ul = h('ul', { class: 'cops' });
    if (s.app.length) ul.appendChild(h('li', null, h('b', null, s.app.length + ' appdata folder(s): '), safe ? 'moved to the hidden quarantine folder. ' : 'deleted for good. ', h('span', { class: 'badge ' + (safe ? 'ok' : 'bad') }, safe ? 'Can be restored' : 'Cannot be undone')));
    if (s.img.length) ul.appendChild(h('li', null, h('b', null, s.img.length + ' image(s): '), 'removed from Docker (never forced). ', h('span', { class: 'badge ok' }, 'Can be downloaded again')));
    if (s.con.length) ul.appendChild(h('li', null, h('b', null, s.con.length + ' stopped container(s): '), 'removed; their appdata stays. ', h('span', { class: 'badge bad' }, 'Cannot be undone')));
    if (s.vol.length) ul.appendChild(h('li', null, h('b', null, s.vol.length + ' volume(s): '), 'removed together with their data. ', h('span', { class: 'badge bad' }, 'Data cannot be recovered')));
    m.body.appendChild(ul);
    m.body.appendChild(h('p', { class: 'muted sm' }, 'Estimated size of the selection: ' + bytes(s.size) + '. ' + (s.app.length && safe ? 'Quarantined folders still use their space until you delete them in the Quarantine section.' : '')));
    var warn = [];
    var rec = s.app.filter(function (o) { return o.raw.recent; }); if (rec.length) warn.push('Changed in the last 30 days: ' + rec.map(function (o) { return o.name; }).join(', ') + '. Make sure nothing still uses ' + (rec.length > 1 ? 'them' : 'it') + '.');
    var rv = s.app.concat(s.img, s.vol, s.con).filter(function (o) { return o.status === 'review'; }); if (rv.length) warn.push(rv.length + ' selected item(s) are marked "Review first". Check them before you continue.');
    if (s.vol.length) warn.push('Volumes are removed with all their data.');
    warn.forEach(function (x) { m.body.appendChild(h('div', { class: 'banner warn' }, x)); });
    if (dry) {
      var out = h('div', { class: 'muted sm' }, 'Checking…'); m.body.appendChild(out);
      m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Close'));
      var calls = [];
      if (s.app.length) calls.push(api('appdata_run', { dry: '1', mode: safe ? 'quarantine' : 'delete', ids: s.app.map(function (o) { return o.key; }) }, true));
      if (s.img.length || s.con.length || s.vol.length) calls.push(api('cleanup_run', { dry: '1', images: s.img.map(function (o) { return o.key; }), containers: s.con.map(function (o) { return o.key; }), volumes: s.vol.map(function (o) { return o.key; }) }, true));
      Promise.all(calls).then(function (rs) {
        clear(out); var n = 0, sk = 0;
        rs.forEach(function (r) { if (r.error || r.ok === false) { out.appendChild(h('div', { class: 'banner bad' }, r.error || 'The preview failed.')); return; } (r.would || []).forEach(function (x) { n++; out.appendChild(h('div', null, '✓ ', typeof x === 'string' ? x : x.name + (x.to ? ' → quarantine' : ''))); }); (r.skipped || []).forEach(function (x) { sk++; out.appendChild(h('div', { class: 'muted' }, '– ' + x.what + ': ' + x.why)); }); });
        out.insertBefore(h('div', { class: 'banner ok' }, h('b', null, 'Preview: '), n + ' item(s) would be cleaned, ' + sk + ' would be skipped. Nothing was changed.'), out.firstChild);
      });
      return;
    }
    var cb = h('input', { type: 'checkbox' }); m.body.appendChild(h('label', { class: 'switch' }, cb, del || s.con.length || s.vol.length ? 'I understand that this cannot be undone' : 'I want to clean these items'));
    var go = h('button', { class: 'btn danger', disabled: true, onclick: function () { m.close(); doCleanup(s, safe, del); } }, 'Clean Selected Items');
    cb.addEventListener('change', function () { go.disabled = !cb.checked; });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
  }
  function doCleanup(s, safe, del) {
    S.cbusy = true; render();
    var res = { done: [], skipped: [], failed: [], size: 0, moved: false, fail: false }, jobs = Promise.resolve();
    if (s.app.length) jobs = jobs.then(function () {
      return api('appdata_run', { confirm: '1', confirm_delete: del ? '1' : '', mode: del ? 'delete' : 'quarantine', ids: s.app.map(function (o) { return o.key; }) }, true).then(function (r) {
        if (r.error || r.ok === false) { s.app.forEach(function (o) { res.failed.push({ what: o.name, why: r.error || 'failed' }); }); return; }
        (r.done || []).forEach(function (n) { res.done.push('appdata ' + n); s.app.forEach(function (o) { if (o.name === n) res.size += o.size; }); }); if (!del && (r.done || []).length) res.moved = true;
        (r.skipped || []).forEach(function (x) { res.skipped.push(x); }); (r.failed || []).forEach(function (x) { res.failed.push(x); });
      });
    });
    if (s.img.length || s.con.length || s.vol.length) jobs = jobs.then(function () {
      return api('cleanup_run', { confirm: '1', images: s.img.map(function (o) { return o.key; }), containers: s.con.map(function (o) { return o.key; }), volumes: s.vol.map(function (o) { return o.key; }) }, true).then(function (r) {
        if (r.error || r.ok === false) { s.img.concat(s.con, s.vol).forEach(function (o) { res.failed.push({ what: o.name, why: r.error || 'failed' }); }); return; }
        (r.removed || []).forEach(function (n) { res.done.push(n); }); res.size += r.size_removed || 0; (r.skipped || []).forEach(function (x) { res.skipped.push(x); }); (r.failed || []).forEach(function (x) { res.failed.push(x); });
      });
    });
    jobs.then(function () {
      S.cbusy = false; res.fail = res.failed.length > 0; S.cresult = res;
      toast(res.done.length + ' cleaned, ' + res.skipped.length + ' skipped, ' + res.failed.length + ' failed.', res.fail);
      api('history').then(function (h2) { S.hist = h2.rows || []; });
      scanAll();
    });
  }
  function loadQ() { if (S.qBusy) return; S.qBusy = true; return api('quarantine_list').then(function (j) { S.qBusy = false; S.qr = j; render(); }); }
  function vQuarantine() {
    var w = h('div'), q = S.qr;
    w.appendChild(h('div', { class: 'banner' }, 'Folders moved here are only hidden away. Restore one to put it back exactly where it was, or delete it for good to free the space. Nothing is overwritten when restoring.'));
    if (!q) { loadQ(); w.appendChild(h('p', { class: 'muted' }, 'Loading…')); return w; }
    if (q.error || !q.rows) { w.appendChild(h('div', { class: 'banner bad' }, q.error || 'Could not load')); return w; }
    if (!q.rows.length) { w.appendChild(h('p', { class: 'muted' }, 'Nothing is in quarantine.')); return w; }
    var t = h('table', { class: 'resp' }); t.appendChild(h('thead', null, h('tr', null, h('th', null, 'Folder'), h('th', null, 'Size'), h('th', null, 'Moved'), h('th', null, 'Status'), h('th', null, ''))));
    var tb = h('tbody'); t.appendChild(tb);
    q.rows.forEach(function (e) {
      var st = !e.exists ? ['bad', 'missing'] : !e.known_root ? ['warn', 'appdata folder not set up'] : e.free ? ['ok', 'can be restored'] : ['warn', 'original place is taken'];
      var btns = h('td', { class: 'acts' });
      if (e.exists && e.known_root) {
        btns.appendChild(h('button', { class: 'btn', disabled: S.ov.run.running, onclick: function () { restoreQ(e, false); } }, 'Restore'));
        btns.appendChild(h('button', { class: 'btn danger', disabled: S.ov.run.running, onclick: function () { purgeQ(e); } }, 'Delete…'));
      } else if (!e.exists) btns.appendChild(h('button', { class: 'btn', onclick: function () { act('quarantine_act', { act: 'purge', id: e.id, confirm: '1' }).then(loadQ); } }, 'Forget'));
      tb.appendChild(h('tr', { class: 'row' }, h('td', { class: 'name', 'data-l': 'Folder' }, h('span', { class: 'nm' }, e.name), h('div', { class: 'img' }, e.original)), h('td', { 'data-l': 'Size' }, bytes(e.size)), h('td', { 'data-l': 'Moved' }, ageText(e.age)),
        h('td', { 'data-l': 'Status' }, h('span', { class: 'badge ' + st[0] }, st[1])), btns));
    });
    w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'In quarantine'), t));
    return w;
  }
  function restoreQ(e, suffix) {
    api('quarantine_act', { act: 'restore', id: e.id, confirm: '1', suffix: suffix ? '1' : '' }, true).then(function (j) {
      if (j.conflict) {
        var m = modal('The original place is taken');
        m.body.appendChild(h('p', null, 'There is already a folder or file named ' + e.name + '. Nothing was overwritten. You can restore the quarantined folder next to it with "-restored" added to its name.'));
        m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Skip')); m.foot.appendChild(h('button', { class: 'btn pri', onclick: function () { m.close(); restoreQ(e, true); } }, 'Restore with a new name'));
        return;
      }
      if (j.error) { toast(j.error, true); return; }
      toast('Restored ' + e.name + (j.to ? ' as ' + j.to.split('/').pop() : '')); S.ad = null; loadQ();
    });
  }
  function purgeQ(e) {
    var m = modal('Delete ' + e.name + ' for good?');
    m.body.appendChild(h('p', null, 'About ' + bytes(e.size) + ' will be deleted and cannot be brought back.'));
    var cb = h('input', { type: 'checkbox' }); m.body.appendChild(h('label', { class: 'switch' }, cb, 'I want to delete it'));
    var go = h('button', { class: 'btn danger', disabled: true, onclick: function () { m.close(); act('quarantine_act', { act: 'purge', id: e.id, confirm: '1' }, 'Deleted').then(loadQ); } }, 'Delete');
    cb.addEventListener('change', function () { go.disabled = !cb.checked; });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go);
  }
  function loadT() { if (S.tBusy) return; S.tBusy = true; return api('tpl_scan').then(function (j) { S.tBusy = false; S.tp = j; render(); }); }
  function vTemplates() {
    var w = h('div'), t = S.tp;
    w.appendChild(h('div', { class: 'banner' }, 'Unraid keeps a saved template for every container you ever created, also after the container was removed. Archiving stores a verified backup first and then removes the saved template. Appdata, images and containers are not touched, and you can restore a template while its name is free.'));
    if (!t) { loadT(); w.appendChild(h('p', { class: 'muted' }, 'Loading…')); return w; }
    if (t.error) { w.appendChild(h('div', { class: 'banner bad' }, t.error)); return w; }
    var t1 = h('table', { class: 'resp' }); t1.appendChild(h('thead', null, h('tr', null, h('th', null, 'Template'), h('th', null, 'Image'), h('th', null, ''))));
    var b1 = h('tbody'); t1.appendChild(b1);
    if (!t.templates.length) b1.appendChild(h('tr', null, h('td', { colspan: 3, class: 'muted' }, 'No saved templates of removed containers.')));
    t.templates.forEach(function (x) {
      b1.appendChild(h('tr', { class: 'row' }, h('td', { class: 'name', 'data-l': 'Template' }, h('span', { class: 'nm' }, x.name)), h('td', { 'data-l': 'Image' }, x.repo || '-'),
        h('td', { class: 'acts' }, h('button', { class: 'btn', disabled: S.ov.run.running, onclick: function () { act('tpl_act', { act: 'archive', name: x.name, confirm: '1' }, 'Archived ' + x.name).then(loadT); } }, 'Archive'))));
    });
    w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Templates of removed containers'), t1));
    if (t.backups.length) {
      var t2 = h('table', { class: 'resp' }); t2.appendChild(h('thead', null, h('tr', null, h('th', null, 'Backup'), h('th', null, 'Saved'), h('th', null, ''))));
      var b2 = h('tbody'); t2.appendChild(b2);
      t.backups.forEach(function (x) {
        b2.appendChild(h('tr', { class: 'row' }, h('td', { class: 'name', 'data-l': 'Backup' }, h('span', { class: 'nm' }, x.name)), h('td', { 'data-l': 'Saved' }, when(x.at)),
          h('td', { class: 'acts' }, h('button', { class: 'btn', disabled: !x.free, title: x.free ? '' : 'A template with this name exists', onclick: function () { act('tpl_act', { act: 'restore', file: x.file, confirm: '1' }, 'Restored ' + x.name).then(loadT); } }, 'Restore'),
            h('button', { class: 'btn danger', onclick: function () { act('tpl_act', { act: 'drop', file: x.file, confirm: '1' }).then(loadT); } }, 'Delete backup'))));
      });
      w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Template backups'), t2));
    }
    return w;
  }

  /* ---------- Settings ---------- */
  function path(o, p) { var a = p.split('.'), x = o; for (var i = 0; i < a.length - 1; i++) x = x[a[i]]; return [x, a[a.length - 1]]; }
  function fSwitch(label, p, hint) { var r = path(S.draft, p); var cb = h('input', { type: 'checkbox', checked: !!r[0][r[1]], onchange: function () { r[0][r[1]] = cb.checked; } }); return h('div', null, h('label', { class: 'switch' }, cb, label), hint ? h('div', { class: 'hint muted sm' }, hint) : null); }
  function fNum(label, p, min, max, hint, unit) { var r = path(S.draft, p); var i = h('input', { type: 'number', min: min, max: max, value: r[0][r[1]], style: 'width:90px', onchange: function () { r[0][r[1]] = Math.max(min, Math.min(max, parseInt(i.value, 10) || 0)); } }); return h('div', { class: 'fld' }, h('label', null, label), h('div', { class: 'inl' }, i, unit ? h('span', { class: 'muted' }, unit) : null), hint ? h('span', { class: 'hint' }, hint) : null); }
  function fText(label, p, hint, ph) { var r = path(S.draft, p); var i = h('input', { type: 'text', value: r[0][r[1]] || '', placeholder: ph || '', style: 'width:100%;max-width:420px', onchange: function () { r[0][r[1]] = i.value; } }); return h('div', { class: 'fld' }, h('label', null, label), i, hint ? h('span', { class: 'hint' }, hint) : null); }
  function fSched(label, base) { var o = path(S.draft, base)[0][base.split('.').pop()]; var s = schedFields(o); var upd = function () { var v = s.get(); o.frequency = v.frequency; o.time = v.time; o.weekday = v.weekday; o.monthday = v.monthday; }; s.el.addEventListener('change', upd); s.el.addEventListener('input', upd); return h('div', { class: 'fld' }, h('label', null, label), s.el); }
  function fWindow(label, base, swLabel, hintText) {
    var w = path(S.draft, base)[0][base.split('.').pop()];
    var en = h('input', { type: 'checkbox', checked: !!w.enabled, onchange: function () { w.enabled = en.checked; box.style.opacity = en.checked ? 1 : .5; } });
    var st = h('input', { type: 'time', value: w.start, onchange: function () { w.start = st.value; } }), ed = h('input', { type: 'time', value: w.end, onchange: function () { w.end = ed.value; } });
    var days = h('div', { class: 'inl' }); DAYS.forEach(function (d, i) { var cb = h('input', { type: 'checkbox', checked: w.days.indexOf(i) >= 0, onchange: function () { var s = w.days.filter(function (x) { return x !== i; }); if (cb.checked) s.push(i); w.days = s.sort(); } }); days.appendChild(h('label', { class: 'switch' }, cb, d.slice(0, 3))); });
    var box = h('div', { style: 'opacity:' + (w.enabled ? 1 : .5) }, h('div', { class: 'inl' }, 'from', st, 'to', ed), days);
    return h('div', { class: 'fld' }, h('label', null, label), h('label', { class: 'switch' }, en, swLabel || 'Only update inside this window'), box, h('span', { class: 'hint' }, hintText || 'Outside the window, updates wait for it to open. A window like 22:00-04:00 crosses midnight.'));
  }
  function section(title, kids) { return h('div', { class: 'panel' }, h('h3', null, title), kids); }
  function osModeField() {
    var d = S.draft, sel = h('select', null,
      h('option', { value: 'notify' }, 'Check and notify only (default)'), h('option', { value: 'install' }, 'Install updates, I reboot myself'), h('option', { value: 'install_reboot' }, 'Install updates and reboot automatically'));
    sel.value = d.os.mode;
    sel.addEventListener('change', function () {
      var v = sel.value;
      if (v === 'notify') { d.os.mode = 'notify'; d.os.confirmed = ''; return; }
      var m = modal(v === 'install' ? 'Install Unraid OS updates automatically?' : 'Install Unraid OS updates and reboot automatically?');
      m.body.appendChild(h('p', null, 'A new Unraid release will be installed without you being present, ' + (v === 'install_reboot' ? 'and the server will REBOOT by itself afterwards (Docker containers and VMs stop for a few minutes).' : 'but it only becomes active when you reboot.')));
      m.body.appendChild(h('p', { class: 'muted' }, 'Safeguards: only inside the maintenance window, only after the waiting period, only stable releases (unless you turn that off), the update is validated first, a reboot is postponed during a parity check or rebuild, and an update that fails is not retried by itself. Unraid keeps the previous release for a restore.'));
      var cb = h('input', { type: 'checkbox' });
      m.body.appendChild(h('label', { class: 'switch' }, cb, 'I understand and want this'));
      var ok = h('button', { class: 'btn pri', disabled: true, onclick: function () { d.os.mode = v; d.os.confirmed = v; d.os.check_enabled = true; if (!d.os.window.enabled) d.os.window.enabled = true; m.close(); render(); toast('Remember to press Save.'); } }, 'Enable');
      cb.addEventListener('change', function () { ok.disabled = !cb.checked; });
      var cancel = function () { sel.value = d.os.mode; m.close(); };
      m.foot.appendChild(h('button', { class: 'btn', onclick: cancel }, 'Cancel')); m.foot.appendChild(ok);
    });
    return h('div', { class: 'fld' }, h('label', null, 'When an update is found'), sel, h('span', { class: 'hint' }, 'Default: only check and notify. An automatic mode needs your explicit confirmation.'));
  }
  /* ---------- backups: appdata and boot drive ---------- */
  function bkKind(k) {
    var info = S.bk && S.bk.backups ? S.bk.backups[k] : null, b = S.draft.backups[k], busy = S.ov.run.running;
    var saved = S.ov.config.backups[k], dirty = JSON.stringify(b) !== JSON.stringify(saved);
    var p = h('div', { class: 'panel' }, h('h3', null, k === 'flash' ? 'Boot drive (USB flash)' : 'Appdata folders'));
    var last = info && info.last;
    var st = h('div', { class: 'inl' });
    if (last && last.at) st.appendChild(h('span', { class: 'badge ' + (last.ok ? 'ok' : 'bad') }, last.ok ? 'Last backup OK' : 'Last backup failed'));
    else st.appendChild(h('span', { class: 'badge off' }, 'No backup yet'));
    if (last && last.at) st.appendChild(h('span', { class: 'muted sm' }, ago(last.at) + (last.ok ? ' · ' + bytes(last.size) : '') + (last.reason ? ' · ' + last.reason : '')));
    if (info && info.next) st.appendChild(h('span', { class: 'muted sm' }, 'Next: ' + when(info.next) + ' (' + info.text + ')'));
    p.appendChild(st);
    if (last && last.at && !last.ok && last.error) p.appendChild(h('div', { class: 'banner bad' }, h('b', null, 'What went wrong: '), last.error));
    p.appendChild(h('p', { class: 'muted sm' }, k === 'flash'
      ? 'Copies everything on the boot drive (/boot): your licence key, array and share settings, plugins and Docker templates. Keep this off the boot drive itself.'
      : 'Archives your appdata folder' + (info && info.sources.length > 1 ? 's' : '') + (info && info.sources.length ? ' (' + info.sources.join(', ') + ')' : '') + '. ' + (b.stop_containers ? 'The containers that use them are stopped while it is read.' : 'Containers keep running while it is read, so a database may be captured mid-write. Turn on "Stop the containers" below for a consistent copy.')));
    /* when */
    var sc = h('select', { 'aria-label': 'When to back up' }, h('option', { value: 'off' }, 'Not on a schedule'), h('option', { value: 'weekly' }, 'Every week'), h('option', { value: 'monthly' }, 'Every month'));
    sc.value = b.schedule;
    var wd = h('select', { 'aria-label': 'Day of the week', onchange: function () { b.weekday = +wd.value; } }); DAYS.forEach(function (d, i) { wd.appendChild(h('option', { value: i, selected: +b.weekday === i }, d)); });
    var md = h('select', { 'aria-label': 'Day of the month', onchange: function () { b.monthday = +md.value; } }); for (var i = 1; i <= 28; i++) md.appendChild(h('option', { value: i, selected: +b.monthday === i }, 'Day ' + i));
    var tm = h('input', { type: 'time', value: b.time, 'aria-label': 'Time', onchange: function () { if (tm.value) b.time = tm.value; } });
    var wr = h('span', null, wd), mr = h('span', null, md), tr = h('span', null, 'at ', tm);
    function sync() { b.schedule = sc.value; wr.style.display = sc.value === 'weekly' ? '' : 'none'; mr.style.display = sc.value === 'monthly' ? '' : 'none'; tr.style.display = sc.value === 'off' ? 'none' : ''; }
    sc.addEventListener('change', sync); sync();
    p.appendChild(h('div', { class: 'fld' }, h('label', null, 'When'), h('div', { class: 'inl' }, sc, wr, mr, tr), schedPreview(function () { return sc.value === 'off' ? null : { frequency: sc.value, time: b.time, weekday: +b.weekday, monthday: +b.monthday }; }, [sc, wd, md, tm])));
    if (k === 'appdata') {
      p.appendChild(fSwitch('Also back up right before updates are installed', 'backups.appdata.before_update', 'Runs once before a run that installs Docker or plugin updates, and only when there really is something to install. Nothing is backed up when there is nothing to update.'));
      p.appendChild(fSwitch('Do not install the updates if this backup fails', 'backups.appdata.required', 'Off: a failed backup is reported and the updates go on. On: the updates wait until a backup works.'));
      p.appendChild(fSwitch('Stop the containers while the backup runs', 'backups.appdata.stop_containers', 'Recommended for databases. Running containers that use the appdata folders are stopped for the backup and started again afterwards (also when the backup fails), in their "update after" order. They are offline for as long as the backup takes.'));
    }
    p.appendChild(fText('Save backups to (a folder under /mnt)', 'backups.' + k + '.dest', 'Example: /mnt/user/backups. Backups go into a folder named Unraid-Swiss-Manager inside it. Use another disk or share than the data being backed up, ideally not the same disk.', '/mnt/user/backups'));
    var kp = h('select', { 'aria-label': 'Backups to keep', style: 'max-width:220px', onchange: function () { b.keep = +kp.value; } });
    [1, 2, 3].forEach(function (n) { kp.appendChild(h('option', { value: n, selected: +b.keep === n }, n === 1 ? 'Keep 1 backup' : 'Keep ' + n + ' backups')); });
    p.appendChild(h('div', { class: 'fld' }, h('label', null, 'How many to keep'), kp, h('span', { class: 'hint' }, 'After a new backup has finished successfully, older ones beyond this number are deleted. The newest one is never deleted, and a failed backup deletes nothing. Room for one extra copy is needed while it runs.')));
    if (k === 'appdata') {
      var t = h('textarea', { rows: 3, spellcheck: 'false', 'aria-label': 'Folders to leave out', style: 'width:100%;max-width:420px;font-family:ui-monospace,Menlo,Consolas,monospace', oninput: function () { b.exclude = t.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean); } }, (b.exclude || []).join('\n'));
      p.appendChild(h('div', { class: 'fld' }, h('label', null, 'Leave these folders out (optional)'), t, h('span', { class: 'hint' }, 'One folder name per line, directly inside the appdata folder, for example a media cache that can be rebuilt.')));
    }
    /* actions */
    var why = info && info.problem ? info.problem : busy ? 'Another job is running. Wait until it has finished.' : dirty ? 'Save your changes first.' : '';
    p.appendChild(h('div', { class: 'actions' },
      h('button', { class: 'btn pri', onclick: saveBackups }, 'Save backup settings'),
      h('button', { class: 'btn', disabled: !!why, title: why, onclick: function () { runJob('backup', [], 'Backup started.', { kind: k }); } }, 'Back up now'),
      why ? h('span', { class: 'muted sm' }, why) : null));
    /* what exists */
    var files = info ? info.files : [];
    p.appendChild(h('h4', null, 'Backups on disk'));
    if (info && info.folder) p.appendChild(h('div', { class: 'muted sm' }, h('span', { class: 'mono' }, info.folder + '/'), info.free != null ? ' · ' + bytes(info.free) + ' free' : ''));
    if (!files.length) p.appendChild(h('p', { class: 'muted' }, info && info.folder ? 'No backup there yet.' : 'Choose a folder to see your backups.'));
    else {
      var tb = h('table', { class: 'resp' }); tb.appendChild(h('thead', null, h('tr', null, h('th', null, 'Date'), h('th', null, 'File'), h('th', null, 'Size'))));
      var bd = h('tbody'); tb.appendChild(bd);
      files.forEach(function (f, i) { bd.appendChild(h('tr', { class: 'row' }, h('td', { 'data-l': 'Date' }, when(f.ts), i === 0 ? h('span', { class: 'badge ok' }, 'Newest') : null), h('td', { 'data-l': 'File', class: 'mono' }, f.name), h('td', { 'data-l': 'Size' }, bytes(f.size)))); });
      p.appendChild(tb);
    }
    var how = h('details', { class: 'more' }, h('summary', null, 'How to restore'));
    how.appendChild(h('p', { class: 'muted sm' }, k === 'flash'
      ? 'Each file is a normal .tar.gz of the boot drive. To restore, prepare a fresh USB drive the usual way, then unpack the backup onto it (for example: tar -xzf flash-DATE.tar.gz -C /tmp/restore, then copy the boot folder contents onto the drive). Restore your licence key and config files from it.'
      : 'Each file is a normal .tar.gz that keeps the original folder paths. Stop the container first, then unpack, for example: tar -xzf appdata-DATE.tar.gz -C / . To restore only one folder, name it at the end of the command (the paths start without a leading slash). Restoring over a running container can corrupt its data.'));
    p.appendChild(how);
    return p;
  }
  function saveBackups() {
    /* only this tab's settings: unfinished edits on the Settings tab stay unfinished */
    act('save_config', { config: { backups: S.draft.backups } }, 'Backup settings saved').then(function (j) { if (j.ok) { S.draft.backups = JSON.parse(JSON.stringify(j.config.backups)); load('backups'); } });
  }
  function vBackups() {
    var w = h('div');
    if (!S.bk) return h('p', { class: 'muted' }, 'Loading…');
    w.appendChild(h('div', { class: 'banner' }, 'These backups are separate from the small safety copies that can be made for a single container right before it updates (Settings > Docker). Backups are only made when the settings below say so, and old ones are removed only down to the number you choose.'));
    w.appendChild(bkKind('appdata')); w.appendChild(bkKind('flash'));
    return w;
  }
  function vSettings() {
    var d = S.draft, w = h('div');
    w.appendChild(section('Docker: default schedule', h('div', { class: 'grid2' },
      h('div', null, fSwitch('Check and update on a schedule', 'global.enabled', 'Applies to containers set to "Auto update" that have no schedule of their own.'), fSched('Schedule', 'global'), fSwitch('Skip stopped containers', 'global.skip_stopped'), fSwitch('Send notifications', 'global.notify', 'Master switch; each container can also be muted.')),
      h('div', null, fNum('Update delay (hours)', 'global.update_delay_hours', 0, 720, '0 = immediately. 24 = one day, 72 = three days, 168 = seven days. Counted from when an image was first seen.'), fNum('Pause between updates', 'global.delay', 0, 600, null, 'seconds'), fWindow('Maintenance window', 'global.window')))));
    w.appendChild(section('Docker: safety', h('div', { class: 'grid2' },
      h('div', null, fSwitch('Verify after update (state and health)', 'global.verify.enabled', 'A container without a health check is not treated as failed.'), fNum('Verification timeout', 'global.verify.timeout', 10, 900, null, 'seconds'), fSwitch('Check free disk space before pulling', 'global.disk_check'), fNum('Warn below', 'global.min_free_gb', 0, 500, null, 'GB free')),
      h('div', null, fNum('Retry failed pulls up to', 'global.retry.max', 0, 5, 'Only network/registry errors that left the container untouched are retried. Never retries once the container was changed.', 'times'), fNum('Retry interval', 'global.retry.interval_min', 5, 1440, null, 'minutes'), fSwitch('Remove the old image after a verified update', 'global.cleanup', 'Only that one image, and only if nothing else uses it. Never a broad prune.')))));
    w.appendChild(section('Docker: backups before update (optional)', h('div', null,
      h('p', { class: 'muted sm' }, 'Turn on per container in its Settings. "Config" saves the template and container definition (small). "Image" saves the current image as a tar file (large). "Appdata" archives the container\'s host folders while it is still running, so databases may not be consistent: use a dedicated backup plugin for those.'),
      h('div', { class: 'grid2' }, h('div', null, fSwitch('Enable backups', 'global.backup.enabled'), fText('Destination folder (under /mnt)', 'global.backup.dest', 'Example: /mnt/user/backups/unraid-swiss-manager', '/mnt/user/backups'), fNum('Keep newest', 'global.backup.retention', 1, 20, null, 'backups per container')),
        h('div', null, fSwitch('Config (template + settings)', 'global.backup.config'), fSwitch('Image (docker save)', 'global.backup.image'), fSwitch('Appdata folders (not application-consistent)', 'global.backup.appdata'), fSwitch('Backup is required: do not update if it fails', 'global.backup.required'))))));
    w.appendChild(section('Plugins', h('div', { class: 'grid2' },
      h('div', null, fSwitch('Check for plugin updates on a schedule', 'plugins.check_enabled'), fSched('Schedule', 'plugins'), fSwitch('Install updates automatically (plugins set to Auto update)', 'plugins.auto_update', 'OFF by default. Plugins run as root: only enable this for plugins you trust.'),
        (function () { var s = h('select', { onchange: function () { d.plugins.default_policy = s.value; } }); POL.forEach(function (p) { s.appendChild(h('option', { value: p[0], selected: d.plugins.default_policy === p[0] }, p[1])); }); return h('div', { class: 'fld' }, h('label', null, 'Policy for plugins without their own'), s); })()),
      h('div', null, fNum('Wait after a new version appears', 'plugins.delay_hours', 0, 720, null, 'hours'), fNum('Pause between plugins', 'plugins.delay', 0, 600, null, 'seconds'), fWindow('Maintenance window', 'plugins.window')))));
    w.appendChild(section('Unraid OS', h('div', null,
      h('div', { class: 'grid2' },
        h('div', null, fSwitch('Check for OS updates on a schedule', 'os.check_enabled', 'Needed for everything below.'), fSched('Schedule', 'os'), osModeField()),
        h('div', null, fNum('Wait after a release appears', 'os.delay_days', 0, 90, 'Counted from when the release was first seen. Applies to automatic installs only.', 'days'), fSwitch('Stable releases only', 'os.stable_only', 'Never install -rc, beta or next releases automatically.'), fWindow('Maintenance window (required for automatic installs)', 'os.window'))),
      h('h4', null, 'Before an OS update (pre-OS maintenance)'),
      h('div', { class: 'grid2' },
        h('div', null, fSwitch('Update plugins before the OS update', 'os.pre.plugins', 'Only plugins set to Auto update (or every non-ignored plugin, below). Each update is safety-checked.'), fSwitch('Require successful plugin updates', 'os.pre.require_plugins', 'If a plugin update fails, the OS update is NOT installed.'),
          (function () { var s = h('select', { onchange: function () { d.os.pre.scope = s.value; } }, h('option', { value: 'auto' }, 'Only plugins set to Auto update'), h('option', { value: 'all' }, 'Every plugin that is not ignored')); s.value = d.os.pre.scope; return h('div', { class: 'fld' }, h('label', null, 'Which plugins'), s); })()),
        h('div', null, fSwitch('Update Docker containers before the OS update', 'os.pre.docker', 'OFF by default. Only containers set to Auto update; critical containers are never included.'), fSwitch('Require successful Docker updates', 'os.pre.require_docker', 'If a container update fails, the OS update is NOT installed.'),
          fSwitch('Ask for confirmation before the OS is installed', 'os.pre.confirm', 'The workflow pauses after the prerequisites and waits for you (Unraid OS tab). Manual installs are already confirmed.'), fSwitch('Notify when the workflow starts', 'os.pre.notify'))))));
    var dc = S.ov.config.discord;
    var url = h('input', { type: 'password', placeholder: dc.webhook_set ? 'A webhook is saved (hidden). Paste a new one to replace it.' : 'https://discord.com/api/webhooks/…', style: 'width:100%;max-width:520px', autocomplete: 'off' });
    var ev = function (k, l) { return fSwitch(l, 'discord.events.' + k); }, ty = function (k, l) { return fSwitch(l, 'discord.types.' + k); };
    var bat = h('select', { onchange: function () { d.discord.batching = bat.value; } }, h('option', { value: 'combine', selected: d.discord.batching === 'combine' }, 'Combine into few messages'), h('option', { value: 'separate', selected: d.discord.batching === 'separate' }, 'One message per notification'));
    w.appendChild(section('Discord notifications', h('div', null,
      fSwitch('Send to Discord', 'discord.enabled'),
      fText('Address of this server\'s web GUI (optional)', 'discord.ui_url', 'Used for the "Update now / Skip this version" links in messages. Empty = http://' + (S.ov.name ? '<server name>' : '') + '. Example: http://192.168.50.50', 'http://192.168.50.50'),
      fSwitch('Add "Update now / Skip this version" links to update-available messages', 'discord.action_links', 'Discord webhooks cannot show real buttons, so these are links that open this plugin; they need your Unraid login and ask for confirmation.'),
      fText('Icon image URL (optional)', 'discord.icon_url', 'https image used as the webhook avatar and in the message header. Empty = the bundled orange-on-grey icon: ' + (dc.icon_default || ''), 'https://…'),
      h('div', { class: 'fld' }, h('label', null, 'Webhook URL'), url, h('span', { class: 'hint' }, 'Stored on the server only in a separate file and never shown again. Messages never contain secrets.')),
      h('div', { class: 'inl' }, h('button', { class: 'btn', onclick: function () { act('test_discord', { url: url.value }).then(function (j) { if (j.ok) toast('Test message sent.'); else if (!j.error) toast('Test failed', true); }); } }, 'Send test message'),
        dc.webhook_set ? h('button', { class: 'btn danger', onclick: function () { if (confirm('Remove the saved webhook?')) act('clear_webhook', {}, 'Webhook removed').then(function () { S.draft = null; load('settings'); }); } }, 'Remove webhook') : null),
      h('div', { class: 'grid2' }, h('div', null, h('h4', null, 'Categories'), ev('docker', 'Docker'), ev('plugins', 'Unraid plugins'), ev('os', 'Unraid OS')),
        h('div', null, h('h4', null, 'Message types'), ty('available', 'Update available'), ty('success', 'Update succeeded'), ty('failed', 'Update failed / retries'), ty('summary', 'Run summary'))),
      h('div', { class: 'fld' }, h('label', null, 'Batching'), bat), fNum('Maximum messages per run', 'discord.max_messages', 1, 20))));
    w.appendChild(section('Unraid notifications', h('div', null, fSwitch('Docker events', 'notify_unraid.docker'), fSwitch('Plugin events', 'notify_unraid.plugins'), fSwitch('OS events', 'notify_unraid.os'))));
    w.appendChild(section('Quiet hours', h('div', null, h('div', { class: 'grid2' }, h('div', null, fWindow('Hold notifications during these hours', 'quiet', 'Enable quiet hours', 'Discord and Unraid notifications wait and are sent together when quiet hours end. Updates themselves still run on their schedule. A window like 23:00-07:00 crosses midnight.')),
      h('div', null, fSwitch('Let urgent messages through', 'quiet.urgent', 'Failures, and an OS update that needs a reboot, are sent right away.'))))));
    w.appendChild(section('Cleanup (appdata)', h('div', null,
      (function () { var t = h('textarea', { rows: 3, spellcheck: 'false', style: 'width:100%;max-width:420px;font-family:ui-monospace,Menlo,Consolas,monospace', 'aria-label': 'Appdata folders', oninput: function () { d.appdata.roots = t.value.split(/\n/).map(function (x) { return x.trim(); }).filter(Boolean); } }); t.value = (d.appdata.roots || []).join('\n'); return h('div', { class: 'fld' }, h('label', null, 'Appdata folders to check'), t, h('span', { class: 'hint' }, 'One folder per line, under /mnt. The Docker appdata share is /mnt/user/appdata on most servers.')); })(),
      fNum('Delete quarantined folders after', 'appdata.purge_days', 0, 365, '0 = never. Folders that stay in quarantine longer than this are deleted for good by the scheduled run.', 'days'),
      fSwitch('Weekly report of what could be freed', 'appdata.report', 'A Discord / Unraid notification (it follows your Docker notification settings) when there is something to clean up. It only looks; nothing is removed.'),
      fSwitch('Safe mode', 'appdata.safe_mode', 'On: unused folders are moved to a hidden quarantine folder and can be restored. Off: the Cleanup tab can delete them for good (it still asks for confirmation).'))));
    w.appendChild(section('Appearance', h('div', { class: 'grid2' },
      h('div', null, (function () { var s = h('select', { onchange: function () { d.ui.theme = s.value; applyTheme(d.ui); } }, h('option', { value: 'auto' }, 'Automatic (follows the Unraid theme)'), h('option', { value: 'dark' }, 'Dark (Unraid grey)'), h('option', { value: 'midnight' }, 'Midnight (blue-grey)'), h('option', { value: 'light' }, 'Light')); s.value = d.ui.theme; return h('div', { class: 'fld' }, h('label', null, 'Theme'), s); })(),
        (function () { var c = h('input', { type: 'color', value: d.ui.accent || '#f15a2c', 'aria-label': 'Accent colour', onchange: function () { d.ui.accent = c.value; applyTheme(d.ui); } }); return h('div', { class: 'fld' }, h('label', null, 'Accent colour'), h('div', { class: 'inl' }, c, h('button', { class: 'btn sm', onclick: function () { d.ui.accent = '#f15a2c'; render(); } }, 'Reset'))); })(),
        fSwitch('Show the tile on the Unraid Dashboard', 'ui.widget', 'Takes effect the next time the Dashboard is opened.'),
        fSwitch('Show Swiss Manager in the Unraid top menu', 'ui.menu_tab', 'Takes effect the next time a page is opened. It stays under Settings > User Utilities either way.')),
      h('div', null, (function () { var t = h('textarea', { rows: 8, spellcheck: 'false', placeholder: '.dau .card { border-radius: 12px; }\n.dau .btn { text-transform: uppercase; }', style: 'width:100%;font-family:ui-monospace,Menlo,Consolas,monospace', oninput: function () { d.ui.css = t.value; applyTheme(d.ui); } }); t.value = d.ui.css || ''; return h('div', { class: 'fld' }, h('label', null, 'Custom CSS (optional)'), t, h('span', { class: 'hint' }, 'Applied to this plugin\'s pages only, for every browser. Start rules with .dau so nothing else changes. Colours are variables: --bg, --panel, --text, --muted, --accent, --ok, --bad. Up to 8000 characters; @import and markup are refused when saving.')); })()))));
    w.appendChild(section('Data', h('div', null, fNum('History entries to keep', 'history.max_entries', 20, 1000), fNum('Log size before rotation', 'log.max_kb', 64, 8192, null, 'KB'),
      h('div', { class: 'inl' }, h('button', { class: 'btn', onclick: saveSettingsFile }, 'Save settings to a file…'),
        h('button', { class: 'btn', onclick: importSettings }, 'Load settings from a file…'), h('button', { class: 'btn danger', onclick: function () { if (confirm('Delete the update history?')) act('clear_history', {}, 'History cleared'); } }, 'Clear history')),
      h('p', { class: 'muted sm' }, 'The file holds every setting, including per-container ones, but never the Discord webhook.'))));
    w.appendChild(vChannel());
    var mark = h('span');
    function markUp() { var on = isDirty(); mark.className = on ? 'badge warn' : 'muted sm'; mark.textContent = on ? 'Unsaved changes' : 'All changes saved'; }
    markUp(); w.addEventListener('change', markUp); w.addEventListener('input', markUp);
    w.appendChild(h('div', { class: 'cbar' }, h('div', { class: 'grow' }, mark), h('button', { class: 'btn pri', onclick: function () {
      var cfg = JSON.parse(JSON.stringify(d)); delete cfg.containers; if (url.value) cfg.discord.webhook_url = url.value;
      act('save_config', { config: cfg }, 'Settings saved').then(function (j) { if (j.ok) { S.draft = null; load('settings'); } });
    } }, 'Save settings'), h('button', { class: 'btn', onclick: function () { S.draft = JSON.parse(JSON.stringify(S.ov.config)); render(); } }, 'Discard changes')));
    return w;
  }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function todayStamp() { var d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
  /* a safe file name: letters, digits, space, dot, dash, underscore; the .json ending is added for you */
  function cleanFileName(n) { n = String(n || '').trim().replace(/\.json$/i, '').replace(/[^A-Za-z0-9 ._-]+/g, '-').replace(/^[.\s-]+/, '').slice(0, 80).replace(/[\s.]+$/, ''); return n; }
  function saveSettingsFile() {
    var m = modal('Save settings to a file');
    var inp = h('input', { type: 'text', value: 'Unraid-Swiss-Manager-Settings-' + todayStamp(), maxlength: 80, style: 'width:100%', 'aria-label': 'File name' });
    var pv = h('div', { class: 'muted sm' });
    function upd() { var n = cleanFileName(inp.value); pv.textContent = n ? 'Will be saved as: ' + n + '.json (in your browser\'s download folder)' : 'Enter a name'; go.disabled = !n; }
    var go = h('button', { class: 'btn pri', onclick: function () {
      var n = cleanFileName(inp.value); if (!n) return;
      api('export').then(function (j) {
        if (j.error) { toast(j.error, true); return; }
        var blob = new Blob([JSON.stringify(j, null, 2)], { type: 'application/json' }), a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = n + '.json'; document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000); m.close(); toast('Saved ' + n + '.json');
      });
    } }, 'Save');
    m.body.appendChild(h('div', { class: 'fld' }, h('label', null, 'File name'), inp, pv, h('span', { class: 'hint' }, 'Leave it as it is to use the default name with today\'s date. The Discord webhook is never included.')));
    inp.addEventListener('input', upd); inp.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !go.disabled) go.click(); });
    m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel')); m.foot.appendChild(go); upd(); setTimeout(function () { inp.focus(); inp.select(); }, 50);
  }
  function importSettings() {
    var inp = h('input', { type: 'file', accept: 'application/json,.json' });
    inp.addEventListener('change', function () {
      var f = inp.files[0]; if (!f) return; var rd = new FileReader();
      rd.onload = function () {
        var j; try { j = JSON.parse(rd.result); } catch (e) { toast('That file is not valid JSON.', true); return; }
        var cfg = j && j.config ? j.config : j;
        if (!cfg || typeof cfg !== 'object' || !(cfg.global || cfg.plugins || cfg.os || cfg.discord)) { toast('That does not look like a settings file from this plugin.', true); return; }
        var nc = cfg.containers ? Object.keys(cfg.containers).length : 0;
        var m = modal('Load settings from ' + f.name);
        m.body.appendChild(h('p', null, 'This replaces the current settings. A copy of the current settings is kept on the server.'));
        m.body.appendChild(h('div', { class: 'muted sm' }, (j.exported_at ? 'Saved: ' + new Date(j.exported_at).toLocaleString() + ' · ' : '') + (j.plugin_version ? 'plugin version ' + j.plugin_version + ' · ' : '') + nc + ' container setting(s)'));
        m.body.appendChild(h('p', { class: 'muted sm' }, 'Settings this version does not know are ignored; invalid values are replaced by safe ones. The Discord webhook is not part of the file, so it stays as it is.'));
        m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel'));
        m.foot.appendChild(h('button', { class: 'btn pri', onclick: function () { m.close(); act('import', { config: j }, 'Settings loaded').then(function (r) { if (!r.error) { S.draft = null; load('settings'); } }); } }, 'Load settings'));
      };
      rd.readAsText(f);
    }); inp.click();
  }

  /* ---------- plugin branch: stable / beta, and force update ---------- */
  function vChannel() {
    var cur = (S.ov && S.ov.channel) || 'stable', pick = S.chPick || cur, ok = !!S.chOk;
    var box = h('div');
    box.appendChild(h('p', null, 'This server follows the ', h('b', null, cur === 'beta' ? 'Beta' : 'Stable'), ' branch (installed version ', h('b', null, S.ov.version), ').',
      S.chv ? h('span', { class: 'muted' }, ' Available now: Stable ' + (S.chv.stable || '?') + ' · Beta ' + (S.chv.beta || '?') + '.') : null, ' ',
      h('button', { class: 'btn sm', onclick: function () { api('channel_check').then(function (j) { if (j.error) { toast(j.error, true); return; } S.chv = j.versions; render(); }); } }, 'Check versions')));
    var opts = h('div', { class: 'inl' });
    [['stable', 'Stable (recommended)'], ['beta', 'Beta (test servers only)']].forEach(function (o) {
      var r = h('input', { type: 'radio', name: 'dau-ch', value: o[0], checked: pick === o[0], onchange: function () { S.chPick = o[0]; S.chOk = false; render(); } });
      opts.appendChild(h('label', { class: 'switch' }, r, o[1]));
    });
    box.appendChild(opts);
    if (pick === 'beta') {
      box.appendChild(h('div', { class: 'banner bad' }, h('b', null, 'Warning: '), 'The beta branch is for test servers only. It holds unfinished features and can break your setup, including automatic updates. Do not use it on a server you depend on. Back up your flash drive first. You can go back to Stable here at any time; settings are kept, but settings only the beta understands are ignored by Stable.'));
      box.appendChild(h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: ok, onchange: function (e) { S.chOk = e.target.checked; render(); } }), 'I understand and want to use the beta branch on this test server'));
    }
    var busy = S.ov.run.running;
    function go(ch, force) {
      var m = modal(force ? 'Force update from the ' + (ch === 'beta' ? 'Beta' : 'Stable') + ' branch?' : 'Switch to the ' + (ch === 'beta' ? 'Beta' : 'Stable') + ' branch?');
      m.body.appendChild(h('p', null, force ? 'The plugin is reinstalled from its ' + (ch === 'beta' ? 'beta' : 'stable') + ' branch even if the version is the same. Your settings are kept.' : 'The plugin is installed from the ' + (ch === 'beta' ? 'beta' : 'stable') + ' branch now. Your settings, history and Discord webhook are kept.'));
      m.body.appendChild(h('p', { class: 'muted sm' }, 'The page reloads when it is done. A job that is already running blocks this.'));
      m.foot.appendChild(h('button', { class: 'btn', onclick: m.close }, 'Cancel'));
      m.foot.appendChild(h('button', { class: 'btn danger', onclick: function () { m.close(); S.reloadAfter = true; runJob('channel', [], 'Installing…', { channel: ch, confirm: '1', beta_ok: ch === 'beta' ? '1' : '', force: force ? '1' : '' }); } }, force ? 'Force update' : 'Switch'));
    }
    box.appendChild(h('div', { class: 'actions' },
      h('button', { class: 'btn pri', disabled: busy || pick === cur || (pick === 'beta' && !ok), onclick: function () { go(pick, false); } }, 'Switch branch…'),
      h('button', { class: 'btn danger', disabled: busy, title: 'Reinstall the plugin from the branch it follows now', onclick: function () { go(cur, true); } }, 'Force update…')));
    box.appendChild(h('p', { class: 'muted sm' }, 'Force update reinstalls the current branch even when Unraid says the plugin is up to date, for example to repair a broken install.'));
    return section('Plugin branch and force update', box);
  }

  /* ---------- Activity ---------- */
  function vActivity() {
    var w = h('div');
    if (S.hist === null) { w.appendChild(h('div', { class: 'dau-loading' }, 'Loading')); return w; }
    var t = h('table', { class: 'resp' }); t.appendChild(h('thead', null, h('tr', null, ['When', 'Type', 'Name', 'Result', 'Versions / images', 'Verification', 'Took', 'Details'].map(function (x) { return h('th', null, x); }))));
    var tb = h('tbody'); t.appendChild(tb);
    if (!S.hist.length) tb.appendChild(h('tr', null, h('td', { colspan: 8, class: 'muted' }, 'No updates recorded yet.')));
    S.hist.forEach(function (e) {
      var p = e.prev || {}, n = e.new || {};
      var from = p.version || p.id || '', to = n.version || n.id || '';
      tb.appendChild(h('tr', { class: 'row' }, h('td', { 'data-l': 'When' }, when(e.ts)), h('td', { 'data-l': 'Type' }, e.type), h('td', { 'data-l': 'Name', class: 'name' }, h('span', { class: 'nm' }, e.name), e.trigger ? h('div', { class: 'muted sm' }, e.trigger) : null),
        h('td', { 'data-l': 'Result' }, h('span', { class: 'badge ' + ((e.result === 'updated' || e.result === 'cleaned' || e.result === 'completed') ? 'ok' : (e.result === 'failed' || e.result === 'interrupted') ? 'bad' : 'warn') }, e.result), e.attempts > 1 ? h('div', { class: 'muted sm' }, e.attempts + ' attempts') : null),
        h('td', { class: 'digest', 'data-l': 'Versions' }, from || to ? from + ' → ' + to : '-'), h('td', { 'data-l': 'Verification' }, e.verification ? h('span', { title: e.verification.detail }, e.verification.result) : '-'), h('td', { 'data-l': 'Took' }, e.duration ? dur(e.duration) : '-'),
        h('td', { 'data-l': 'Details' }, h('div', { class: 'sm' }, e.error || ''), e.message ? h('div', { class: 'muted sm' }, e.message) : null)));
    });
    w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Update history'), t));
    w.appendChild(h('div', { class: 'panel' }, h('h3', null, 'Log'), h('pre', { class: 'log' }, S.log || 'The log is empty.')));
    return w;
  }

  load('overview').then(function () { if (S.ov && S.ov.run.running) startPoll(); return deepLink(); });
})();
