// Shared by the meeting notes and All tasks pages: tiny DOM/API helpers and the task quick-view
// panel ("peek"): stage, people, dates, project, latest updates and a quick comment, without
// leaving the page. "Open task" goes to the full panel on the project's board.
(() => {
  'use strict';

  const boot = JSON.parse(document.getElementById('boot').textContent);
  const members = new Map(boot.members.map(m => [m.id, m]));

  function h(tag, attrs = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v; // only for HTML the server already sanitized
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else if (v === true) el.setAttribute(k, '');
      else el.setAttribute(k, v);
    }
    for (const kid of kids.flat()) {
      if (kid === null || kid === undefined || kid === false) continue;
      el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
    }
    return el;
  }
  const svg = (path, cls = 'i') => {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 20 20');
    s.setAttribute('class', cls);
    s.innerHTML = path;
    return s;
  };
  const ICON = {
    x: '<path d="M5 5l10 10M15 5L5 15"/>',
    out: '<path d="M11 4h5v5M16 4l-7 7M14 11v4a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h4"/>',
    check: '<path d="M4.5 10.5l3.5 3.5 7.5-8"/>',
    task: '<rect x="3.5" y="3.5" width="13" height="13" rx="3"/><path d="M7 10.2l2.2 2.2L13.5 8"/>',
    cal: '<rect x="3" y="4.5" width="14" height="12" rx="2"/><path d="M3 8.5h14M7 3v3M13 3v3"/>',
    folder: '<path d="M3 6a1 1 0 011-1h4l2 2h6a1 1 0 011 1v7a1 1 0 01-1 1H4a1 1 0 01-1-1z"/>',
  };

  async function api(method, path, body) {
    const opts = { method, headers: { 'X-CSRF-Token': boot.csrf, Accept: 'application/json' }, keepalive: method !== 'GET' };
    if (body !== undefined) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    let res;
    try {
      res = await fetch(boot.base.replace(/\/$/, '') + path, opts);
    } catch {
      toast('You seem to be offline. That wasn’t saved.', 'error');
      throw new Error('offline');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      toast(data.error || 'Something went wrong.', 'error');
      if (res.status === 401) setTimeout(() => location.reload(), 1500);
      throw new Error(data.error || String(res.status));
    }
    return data;
  }

  function toast(msg, type = 'info') {
    let box = document.getElementById('toasts');
    if (!box) { box = h('div', { id: 'toasts', class: 'toasts' }); document.body.append(box); }
    const t = h('div', { class: `toast toast-${type}` }, msg);
    box.append(t);
    setTimeout(() => t.classList.add('is-hiding'), 3500);
    setTimeout(() => t.remove(), 4000);
  }

  const today = (boot.now || boot.today || new Date().toISOString()).slice(0, 10);
  const dateOf = s => new Date(String(s).slice(0, 10) + 'T00:00:00');
  const fmtDate = d => {
    if (!d) return '';
    const x = dateOf(d);
    return x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', ...(x.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }) });
  };
  const fmtTime = dt => new Date(String(dt).replace(' ', 'T')).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }).toLowerCase();
  const fmtWhen = dt => dt ? fmtDate(dt) + ', ' + fmtTime(dt) : '';
  const isOverdue = (due, done) => due && due < today && !done;

  function avatar(id, size = 'sm') {
    const m = members.get(id);
    return m ? h('span', { class: `avatar ${size}`, style: `--c:${m.color}`, title: m.name }, m.initials) : null;
  }
  function avatars(ids, size = 'sm') {
    const w = h('span', { class: 'avatars' });
    ids.slice(0, 3).forEach(id => { const a = avatar(id, size); if (a) w.append(a); });
    if (ids.length > 3) w.append(h('span', { class: `avatar ${size} more` }, '+' + (ids.length - 3)));
    return w;
  }

  // ---------- quick view ----------
  let panel = null;
  let backdrop = null;
  let current = null;
  let onChange = null;

  function close() {
    panel?.remove(); backdrop?.remove();
    panel = backdrop = null; current = null;
  }
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && panel && !e.target.closest('input, textarea, select')) close(); });

  async function open(taskId, opts = {}) {
    onChange = opts.onChange || null;
    if (!panel) {
      backdrop = h('div', { class: 'peek-backdrop', onclick: close });
      panel = h('aside', { class: 'peek', 'aria-label': 'Task' });
      document.body.append(backdrop, panel);
    }
    panel.replaceChildren(h('div', { class: 'peek-loading' }, 'Loading…'));
    try {
      const d = await api('GET', `/api/tasks/${taskId}`);
      current = d.task;
      draw();
    } catch {
      close();
    }
  }

  async function save(fields) {
    const d = await api('POST', `/api/tasks/${current.id}`, fields);
    current = d.task; draw(); onChange?.(current);
  }

  function draw() {
    const t = current;
    const st = t.statuses.find(s => s.id === t.status_id) || {};
    const statusSel = h('select', { class: 'status-select big', style: `--sc:${st.color}`, disabled: !t.can.move, onchange: e => save({ status_id: +e.target.value }) },
      t.statuses.map(s => h('option', { value: s.id, selected: s.id === t.status_id }, s.name)));
    const projects = boot.projects || [];
    const projectSel = h('select', {
      class: 'peek-project' + (t.project.inbox ? ' is-inbox' : ''), disabled: !t.can.edit || !projects.length,
      onchange: async e => {
        const d = await api('POST', `/api/tasks/${t.id}/project`, { project_id: +e.target.value });
        current = d.task; draw(); onChange?.(current);
        toast(`Moved to ${current.project.name}`);
      },
    }, (projects.some(p => p.id === t.project.id) ? projects : [t.project, ...projects]).map(p => h('option', { value: p.id, selected: p.id === t.project.id }, p.inbox ? 'Inbox (not in a project)' : p.name)));
    const due = h('input', { type: 'date', class: 'cf-input' + (isOverdue(t.due_date, st.type === 'done') ? ' is-overdue' : ''), value: t.due_date || '', disabled: !t.can.fields, onchange: e => save({ due_date: e.target.value || null }) });

    const feed = h('div', { class: 'peek-feed' });
    t.feed.slice(-8).forEach(item => feed.append(item.type === 'comment'
      ? h('div', { class: 'comment' },
          h('div', { class: 'comment-head' }, avatar(item.user_id), h('strong', {}, members.get(item.user_id)?.name || 'Someone'), h('time', {}, fmtWhen(item.at))),
          h('div', { class: 'comment-body', html: item.html }))
      : h('div', { class: 'event' }, h('span', {}, h('strong', {}, item.user_id === boot.me ? 'You' : (members.get(item.user_id)?.name || 'Someone')), ' ', item.text), h('time', {}, fmtWhen(item.at)))));

    const box = h('textarea', { rows: 2, placeholder: 'Add an update… (Ctrl+Enter to send)' });
    const send = async () => {
      if (!box.value.trim()) return;
      const fd = new FormData(); fd.append('body', box.value);
      const res = await fetch(boot.base.replace(/\/$/, '') + `/api/tasks/${t.id}/comments`, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': boot.csrf, Accept: 'application/json' } });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) return toast(d.error || 'Couldn’t add the comment.', 'error');
      current = d.task; draw(); onChange?.(current);
    };
    box.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); } });

    panel.replaceChildren(
      h('header', { class: 'peek-head' },
        h('span', { class: 'muted small row-center gap-s' }, svg(ICON.folder), t.project.inbox ? 'Inbox' : t.project.name),
        h('span', { class: 'row-center gap-s' },
          h('a', { class: 'btn btn-sm', href: t.url }, svg(ICON.out), 'Open task'),
          h('button', { type: 'button', class: 'icon-btn', title: 'Close (Esc)', onclick: close }, svg(ICON.x)))),
      h('div', { class: 'peek-body' },
        h('h2', { class: 'peek-title' }, t.title),
        h('div', { class: 'fields' },
          row('Stage', statusSel),
          row('Assignees', t.assignees.length ? h('span', { class: 'row-center gap-s' }, t.assignees.map(id => h('span', { class: 'person' }, avatar(id), members.get(id)?.name))) : h('span', { class: 'muted' }, 'Nobody yet')),
          row('Due', due),
          row('Project', projectSel),
          t.project.inbox ? h('p', { class: 'hint peek-hint' }, 'This task isn’t in a project yet. Pick one above to put it on that board.') : null),
        h('h3', { class: 'peek-sub' }, 'Latest updates'),
        feed.children.length ? feed : h('p', { class: 'muted small' }, 'No updates yet.'),
        h('div', { class: 'peek-comment' }, box, h('div', { class: 'actions' }, h('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: send }, 'Add update')))));
  }
  const row = (label, control) => h('div', { class: 'field-row' }, h('div', { class: 'field-label' }, label), h('div', { class: 'field-value' }, control));

  window.LPM = { boot, members, h, svg, ICON, api, toast, fmtDate, fmtTime, fmtWhen, isOverdue, avatar, avatars, today, peek: { open, close } };
})();
