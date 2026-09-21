/* Log Health: the Tools page. Everything that comes from a log is put on the page with textContent, never as
   HTML: a container can print anything, including markup. */
(function () {
  'use strict';
  var app = document.getElementById('lh-app');
  if (!app) return;
  var api = app.dataset.api, csrf = app.dataset.csrf;
  var view = null;                      // the last answer from the API
  var ui = { open: {}, allDocker: false, settingsOpen: false, notice: null, draft: null };
  var poll = null;

  /* ---------- small helpers ---------- */
  function h(tag, attrs, kids) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') e.className = v;
      else if (k === 'text') e.textContent = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
      else e.setAttribute(k, v === true ? '' : v);
    });
    [].concat(kids === undefined ? [] : kids).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return e;
  }
  function bytes(n) {
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 || n >= 100 ? Math.round(n) : Math.round(n * 10) / 10) + ' ' + u[i];
  }
  function num(n) { return Number(n).toLocaleString(); }
  function rate(r) { return (r >= 10 ? Math.round(r) : Math.round(r * 10) / 10).toLocaleString() + '/min'; }
  function ago(seconds) {
    var m = Math.max(0, Math.round(seconds / 60));
    if (m < 1) return 'moments ago';
    if (m < 60) return m + (m === 1 ? ' minute ago' : ' minutes ago');
    var hr = Math.round(m / 60);
    if (hr < 48) return hr + (hr === 1 ? ' hour ago' : ' hours ago');
    return Math.round(hr / 24) + ' days ago';
  }
  function inMinutes(seconds) {
    var m = Math.max(1, Math.round(seconds / 60));
    if (m < 60) return 'in ' + m + (m === 1 ? ' minute' : ' minutes');
    var hr = Math.round(m / 60);
    return 'in ' + hr + (hr === 1 ? ' hour' : ' hours');
  }
  function whoLabel(key) {
    var i = key.indexOf(':'), kind = key.slice(0, i), name = key.slice(i + 1);
    if (kind === 'php' && name === 'nginx') return 'nginx error log';
    return ({ php: 'PHP', syslog: 'System log', docker: 'Docker', log: 'Log file' })[kind] + ' · ' + name;
  }
  var LEVELS = {
    flooding: 'About a line a second or faster. This is what fills logs and disks.',
    noisy: 'Repeating steadily. Worth a look.',
    chatty: 'Most of a small log, but slow: usually a healthcheck or access log. Harmless, and collapsed by default.'
  };

  /* ---------- talking to the API ---------- */
  function call(action, extra) {
    var body = new URLSearchParams(Object.assign({ action: action, csrf_token: csrf }, extra || {}));
    return fetch(api, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.json().then(function (j) { return { status: r.status, body: j }; });
    });
  }
  function load() {
    return fetch(api + '?action=state', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      view = j; draw(); watch();
    }).catch(function () {
      app.textContent = 'Could not reach the plugin. Reload the page.';
    });
  }
  function watch() {                    // while a scan runs, look again every two seconds
    clearTimeout(poll);
    if (view && view.scanning) poll = setTimeout(load, 2000);
  }
  function act(action, extra, notice) {
    return call(action, extra).then(function (r) {
      if (r.body && r.body.state !== undefined) view = r.body;
      ui.notice = r.status >= 400
        ? { kind: 'err', lines: r.body.errors || [r.body.error || 'That did not work.'] }
        : (notice ? { kind: 'ok', lines: [notice] } : null);
      draw(); watch();
      return r;
    });
  }

  /* ---------- pieces ---------- */
  function statusBar() {
    var s = view.state, c = view.cron, bits = [];
    if (s) bits.push('Last scan ' + ago(view.now - s.ts) + ', ' + num(s.lines_read) + ' new lines from ' + num(s.sources.length) + ' logs');
    else bits.push('No scan has run yet');
    bits.push(c.next ? 'next scan ' + c.next_at + ' (' + inMinutes(c.next - view.now) + ')' : 'scans only when you press Scan now');
    return h('div', { class: 'lh-row' }, [
      h('span', { class: 'lh-grow' }, bits.join(' · ')),
      h('button', { type: 'button', disabled: view.scanning, onclick: function () { act('scan', {}, null); } },
        view.scanning ? [h('span', { class: 'lh-spin' }), 'Scanning…'] : 'Scan now')
    ]);
  }

  function chips() {
    var s = view.state, n = { flooding: 0, noisy: 0, chatty: 0 };
    if (s) s.groups.forEach(function (g) { n[g.rep.level]++; });
    var list = [];
    [['flooding', 'flooding'], ['noisy', 'noisy'], ['chatty', 'chatty']].forEach(function (p) {
      list.push(h('span', { class: 'lh-chip' }, [h('b', { text: String(n[p[0]]) }), ' ' + p[1]]));
    });
    var muted = Object.keys(view.muted).length;
    if (muted) list.push(h('span', { class: 'lh-chip' }, [h('b', { text: String(muted) }), ' muted']));
    return h('div', { class: 'lh-chips' }, list);
  }

  function card(g) {
    var x = g.rep, s = view.state, stopped = (s.ts - (x.last_ts || s.ts)) > s.active_minutes * 60;
    var open = !!ui.open[g.id];
    var metrics = [
      h('span', { text: num(x.total) + ' lines' }),
      h('span', { text: rate(x.rate) }),
      h('span', { text: x.share + '% of its log' }),
      h('span', { class: 'lh-dim', text: stopped ? 'stopped ' + ago(s.ts - x.last_ts) : 'still happening' })
    ];
    var detail = null;
    if (open) {
      detail = h('div', { class: 'lh-detail lh-small' }, [
        h('div', {}, [h('b', { text: 'Example: ' }), h('span', { class: 'lh-mono', text: x.example })]),
        x.where ? h('div', {}, [h('b', { text: 'Where: ' }), h('span', { class: 'lh-mono', text: x.where })]) : null,
        h('div', {}, [h('b', { text: 'Log: ' }), h('span', { class: 'lh-mono', text: x.path })])
      ].concat(g.others.length ? [h('div', {}, [h('b', { text: 'Related lines in the same incident:' })])].concat(
        g.others.map(function (o) { return h('div', { class: 'lh-mono', text: '• ' + o }); })) : []));
    }
    return h('div', { class: 'lh-card ' + x.level + (stopped ? ' stopped' : '') }, [
      h('div', { class: 'lh-card-head' }, [
        h('span', { class: 'lh-badge ' + x.level, text: x.level }),
        h('span', { class: 'lh-who', text: whoLabel(x.key) }),
        x.where ? h('span', { class: 'lh-dim lh-small lh-mono', text: x.where }) : null
      ]),
      h('div', { class: 'lh-msg lh-mono', text: x.sig }),
      h('div', { class: 'lh-metrics lh-small' }, metrics.concat(g.related ? [h('span', { class: 'lh-dim', text: '+' + g.related + ' related lines' })] : [])),
      h('div', { class: 'lh-actions' }, [
        h('button', { type: 'button', class: 'lh-link', onclick: function () { ui.open[g.id] = !open; draw(); } }, open ? 'Hide details' : 'Details'),
        h('button', { type: 'button', class: 'lh-link', title: 'Stop listing this. You can unmute it below.', onclick: function () {
          act('mute', { ids: JSON.stringify(g.ids) }, 'Muted. It is listed under Muted below, where you can unmute it.');
        } }, 'Mute')
      ]),
      detail
    ]);
  }

  function incidents() {
    var s = view.state, box = h('div', {});
    box.appendChild(h('h3', { text: 'Spamming logs' }));
    if (!s) { box.appendChild(h('p', { class: 'lh-dim', text: 'Press Scan now to look at your logs. The first scan reads more than later ones and can take up to a minute.' })); return box; }
    if (!s.groups.length) { box.appendChild(h('p', { text: 'Nothing is spamming right now.' })); return box; }
    ['flooding', 'noisy', 'chatty'].forEach(function (level) {
      var gs = s.groups.filter(function (g) { return g.rep.level === level; });
      if (!gs.length) return;
      var head = h('p', { class: 'lh-dim lh-small', text: LEVELS[level] });
      if (level === 'chatty') {
        var d = h('details', ui.open._chatty ? { open: true } : {}, [h('summary', { text: gs.length + ' chatty (repetitive but slow)' }), head].concat(gs.map(card)));
        d.addEventListener('toggle', function () { ui.open._chatty = d.open; });
        box.appendChild(d);
      } else {
        box.appendChild(h('div', { class: 'lh-row' }, [h('b', { text: gs.length + ' ' + level }), h('span', { class: 'lh-dim lh-small', text: LEVELS[level] })]));
        gs.forEach(function (g) { box.appendChild(card(g)); });
      }
    });
    return box;
  }

  function dockerTable() {
    var s = view.state, box = h('div', {});
    if (!s || !s.docker || !s.docker.enabled) return box;
    var all = Object.keys(s.docker.containers).map(function (k) { return s.docker.containers[k]; })
      .sort(function (a, b) { return b.bytes - a.bytes; });
    var big = all.filter(function (c) { return c.level; });
    var rows = ui.allDocker ? all : (big.length ? big : all.slice(0, 5));
    box.appendChild(h('h3', { text: 'Docker logs' }));
    var d = s.docker.daemon || {};
    box.appendChild(h('p', { class: 'lh-dim lh-small', text: 'Docker default: ' + (d.max_size ? 'rotate at ' + d.max_size + ' × ' + (d.max_file || 1) + ' files' : 'no limit') +
      '. Each container can set its own; a limit of 1g × 3 lets one container reach 3 GB before old data is dropped.' }));
    var head = h('tr', {}, [h('th', { text: 'Container' }), h('th', { class: 'num', text: 'Size' }), h('th', { text: 'Rotates at' }), h('th', { text: 'Of first file' }), h('th', { class: 'num', text: 'Growing' })]);
    var body = rows.map(function (c) {
      return h('tr', {}, [
        h('td', { text: c.name }),
        h('td', { class: 'num', text: bytes(c.bytes) + (c.level ? ' (' + c.level + ')' : '') }),
        h('td', { text: c.limited ? c.max_size + ' × ' + c.max_file : 'never (no limit)' }),
        h('td', {}, c.pct_of_file === null ? '' : h('div', { class: 'lh-bar ' + (c.level || ''), title: c.pct_of_file + '%' }, h('i', { style: 'width:' + Math.min(100, c.pct_of_file) + '%' }))),
        h('td', { class: 'num', text: c.growth_per_hour === null ? '—' : (c.growth_per_hour === 0 ? 'not growing' : '+' + bytes(c.growth_per_hour) + '/h') })
      ]);
    });
    box.appendChild(h('div', { class: 'lh-scroll' }, h('table', { class: 'lh-table' }, [h('thead', {}, head), h('tbody', {}, body)])));
    if (all.length > rows.length || ui.allDocker) {
      box.appendChild(h('p', {}, h('button', { type: 'button', class: 'lh-link', onclick: function () { ui.allDocker = !ui.allDocker; draw(); } },
        ui.allDocker ? 'Show only large logs' : 'Show all ' + all.length + ' containers')));
    }
    return box;
  }

  function mutedList() {
    var ids = Object.keys(view.muted), box = h('div', {});
    if (!ids.length) return box;
    box.appendChild(h('h3', { text: 'Muted' }));
    box.appendChild(h('p', { class: 'lh-dim lh-small', text: 'Hidden from the list above. An unmuted message shows again at the next scan if it is still spamming.' }));
    box.appendChild(h('div', { class: 'lh-scroll' }, h('table', { class: 'lh-table' }, h('tbody', {}, ids.map(function (id) {
      var m = view.muted[id];
      return h('tr', {}, [
        h('td', {}, [h('span', { class: 'lh-mono', text: m.label }), h('div', { class: 'lh-dim lh-small', text: whoLabel(m.source) })]),
        h('td', { class: 'num' }, h('button', { type: 'button', class: 'lh-link', onclick: function () { act('unmute', { ids: JSON.stringify([id]) }, 'Unmuted.'); } }, 'Unmute'))
      ]);
    })))));
    return box;
  }

  /* ---------- settings ---------- */
  function field(label, control, help) {
    return h('div', { class: 'lh-field' }, [h('label', {}, label), control, help ? h('div', { class: 'lh-help lh-dim lh-small', text: help }) : null]);
  }
  function check(id, checked) { return h('input', { type: 'checkbox', id: id, checked: checked }); }

  function settingsForm() {
    var st = view.settings, S = st.sources, T = st.thresholds;
    var sel = h('select', { id: 'lh-every' }, view.schedules.map(function (o) {
      return h('option', { value: o.key, selected: o.key === st.schedule.every }, o.label);
    }));
    var at = h('input', { type: 'time', id: 'lh-at', value: st.schedule.daily_at });
    var atField = field('Time of day', at);
    function sync() { atField.style.display = sel.value === 'daily' ? '' : 'none'; }
    sel.addEventListener('change', sync); sync();

    var sched = h('fieldset', {}, [h('legend', { text: 'How often to scan' }),
      field('Scan', sel, 'A scan only reads what your logs gained since the last one, so it is cheap: a scan that finds nothing new takes a fraction of a second. Every 15 minutes finds a flood within a quarter of an hour without being a load. The first scan after installing reads more and can take up to a minute.'),
      atField]);

    var src = h('fieldset', {}, [h('legend', { text: 'What to look at' }),
      field('PHP errors (PHP log, nginx errors)', check('lh-php', S.php.enabled), 'Names the plugin and the file and line that raised each warning.'),
      field('System log', check('lh-syslog', S.syslog.enabled), 'Names the program.'),
      field('Docker container logs', check('lh-docker', S.docker.enabled), 'Size, growth, limit, and what is repeating in each container’s log.'),
      field(' … read what is inside them', check('lh-dockerc', S.docker.scan_content), 'Off watches only the sizes, which is cheaper.'),
      field('Other plugin logs (/var/log/*.log)', check('lh-plugins', S.plugins.enabled))]);

    var lim = view.limits, thr = h('details', ui.open._thr ? { open: true } : {}, [h('summary', { text: 'Sensitivity (advanced)' })]);
    thr.addEventListener('toggle', function () { ui.open._thr = thr.open; });
    var thrHelp = {
      min_count: 'A message must repeat at least this many times in a scan before it can be flagged.',
      min_rate: 'Repeating this fast or faster is called noisy.',
      flood_rate: 'Repeating this fast or faster is called flooding. 60 is about one line a second.',
      share_pct: 'A slow message that makes up this much of its log is called chatty.',
      min_total: 'Share only counts in logs with at least this many lines.'
    };
    Object.keys(lim).forEach(function (k) {
      thr.appendChild(field(lim[k][3], h('input', { type: 'number', id: 'lh-t-' + k, value: T[k], min: lim[k][1], max: lim[k][2], step: lim[k][0] === 'int' ? 1 : 0.1 }), thrHelp[k]));
    });
    thr.appendChild(field('Docker log is large at (MB)', h('input', { type: 'number', id: 'lh-warn', value: S.docker.warn_mb, min: 1, step: 1 })));
    thr.appendChild(field('Docker log is huge at (MB)', h('input', { type: 'number', id: 'lh-crit', value: S.docker.crit_mb, min: 1, step: 1 })));

    return h('div', {}, [sched, src, thr,
      h('div', { class: 'lh-row' }, [h('button', { type: 'button', onclick: saveSettings }, 'Save settings')])]);
  }

  function saveSettings() {
    var $ = function (id) { return document.getElementById(id); }, t = {};
    Object.keys(view.limits).forEach(function (k) { t[k] = $('lh-t-' + k).value; });
    var settings = {
      schedule: { every: $('lh-every').value, daily_at: $('lh-at').value || '03:00' },
      thresholds: t,
      sources: {
        php: { enabled: $('lh-php').checked }, syslog: { enabled: $('lh-syslog').checked }, plugins: { enabled: $('lh-plugins').checked },
        docker: { enabled: $('lh-docker').checked, scan_content: $('lh-dockerc').checked, warn_mb: $('lh-warn').value, crit_mb: $('lh-crit').value }
      }
    };
    ui.settingsOpen = true;
    act('save', { settings: JSON.stringify(settings) }, 'Saved.').then(function (r) {
      if (r.status < 400 && r.body.cron && r.body.cron.next) ui.notice.lines = ['Saved. The next scan is ' + r.body.cron.next_at + ' (server time).'];
      else if (r.status < 400) ui.notice.lines = ['Saved. Scheduled scans are off.'];
      draw();
    });
  }

  function noticeBox() {
    if (!ui.notice) return null;
    return h('div', { class: 'lh-msgbox ' + ui.notice.kind, role: ui.notice.kind === 'err' ? 'alert' : 'status' }, ui.notice.lines.map(function (l) { return h('div', { text: l }); }));
  }

  /* ---------- the page ---------- */
  // The settings form is rebuilt only when the saved settings change, so a redraw while a scan runs
  // (or a mute) does not wipe what someone is in the middle of typing.
  function settingsBlock() {
    var key = JSON.stringify(view.settings) + view.schedules.length;
    if (ui.settingsEl && ui.settingsKey === key) return ui.settingsEl;
    var d = h('details', ui.settingsOpen ? { open: true } : {}, [h('summary', {}, h('b', { text: 'Settings' })), settingsForm()]);
    d.addEventListener('toggle', function () { ui.settingsOpen = d.open; });
    ui.settingsEl = d; ui.settingsKey = key;
    return d;
  }

  function draw() {
    if (!view) return;
    app.replaceChildren.apply(app, [statusBar(), noticeBox(), chips(), incidents(), dockerTable(), mutedList(), settingsBlock(), h('p', { class: 'lh-dim lh-small', text: 'Log Health ' + view.version })].filter(Boolean));
  }

  load();
})();
