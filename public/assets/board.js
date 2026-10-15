// LeanPM board + list + task panel. Plain JS, no build step.
// Both views draw from the same `state.tasks`; every change goes through the JSON API,
// which checks permissions again on the server.
(() => {
  'use strict';

  // localStorage can be missing or blocked (private windows); the page works without it.
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* ignore */ } },
  };
  const boot = JSON.parse(document.getElementById('boot').textContent);
  const P = boot.priorities; // { urgent: 'Urgent', … }
  const PRIORITY_COLORS = { urgent: '#ef4444', high: '#f59e0b', normal: '#3b82f6', low: '#9ca3af' };
  const KEY = `lpm:${boot.org.slug}:${boot.project.id}`;

  const state = {
    tasks: boot.tasks,
    statuses: boot.statuses,
    view: store.get(KEY + ':view') || 'board',
    sort: { key: 'position', dir: 1 },
    collapsed: new Set(JSON.parse(store.get(KEY + ':collapsed') || '[]')),
    filters: { q: '', assignees: [], priority: '', due: '', subtasks: store.get(KEY + ':subtasks') === '1', hideDone: store.get(KEY + ':hideDone') === '1' },
    openId: null,
    detail: null,
  };
  const members = new Map(boot.members.map(m => [m.id, m]));
  const activeMembers = () => boot.members.filter(m => m.active);
  const statusById = id => state.statuses.find(s => s.id === id);
  const taskById = id => state.tasks.find(t => t.id === id);

  // ---------- small helpers ----------
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
    flag: '<path d="M5 17V3.5M5 4h9l-2 3.5 2 3.5H5"/>',
    cal: '<rect x="3" y="4.5" width="14" height="12" rx="2"/><path d="M3 8.5h14M7 3v3M13 3v3"/>',
    sub: '<path d="M5 3v8a3 3 0 003 3h7M12 11l3 3-3 3"/>',
    chat: '<path d="M4 4h12v9H8l-4 3z"/>',
    clip: '<path d="M14.5 9.5l-5 5a3 3 0 01-4.2-4.2l6-6a2 2 0 012.8 2.8l-6 6a1 1 0 01-1.4-1.4l5-5"/>',
    x: '<path d="M5 5l10 10M15 5L5 15"/>',
    plus: '<path d="M10 4v12M4 10h12"/>',
    trash: '<path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10"/>',
    link: '<path d="M8.5 11.5a3 3 0 004.2 0l2.5-2.5a3 3 0 00-4.2-4.2l-1 1M11.5 8.5a3 3 0 00-4.2 0L4.8 11a3 3 0 004.2 4.2l1-1"/>',
    user: '<circle cx="10" cy="7" r="3"/><path d="M4 17c1-3.5 3.5-5 6-5s5 1.5 6 5"/>',
    chev: '<path d="M7 5l5 5-5 5"/>',
    up: '<path d="M10 15V5M5 10l5-5 5 5"/>',
  };

  const today = boot.today;
  const addDays = (d, n) => { const x = new Date(d + 'T00:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
  const fmtDate = d => {
    if (!d) return '';
    const x = new Date(d.slice(0, 10) + 'T00:00:00');
    const sameYear = x.getFullYear() === new Date().getFullYear();
    return x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) });
  };
  const fmtWhen = dt => {
    if (!dt) return '';
    const x = new Date(dt.replace(' ', 'T'));
    return x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' at ' + x.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }).toLowerCase();
  };
  const dueLabel = d => d === today ? 'Today' : d === addDays(today, 1) ? 'Tomorrow' : d === addDays(today, -1) ? 'Yesterday' : fmtDate(d);
  const isDone = t => statusById(t.status_id)?.type === 'done';
  const isOverdue = t => t.due_date && t.due_date < today && !isDone(t);

  function avatar(id, size = '') {
    const m = members.get(id);
    if (!m) return null;
    return h('span', { class: `avatar ${size}${m.active ? '' : ' is-inactive'}`, style: `--c:${m.color}`, title: m.name + (m.active ? '' : ' (inactive)') }, m.initials);
  }
  function avatars(ids, size = 'sm') {
    const wrap = h('span', { class: 'avatars' });
    ids.slice(0, 4).forEach(id => { const a = avatar(id, size); if (a) wrap.append(a); });
    if (ids.length > 4) wrap.append(h('span', { class: `avatar ${size} more` }, '+' + (ids.length - 4)));
    return wrap;
  }

  // ---------- API ----------
  async function api(method, path, body) {
    const opts = { method, headers: { 'X-CSRF-Token': boot.csrf, Accept: 'application/json' } };
    if (body instanceof FormData) opts.body = body;
    else if (body !== undefined) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    let res;
    try {
      res = await fetch(boot.base.replace(/\/$/, '') + path, opts);
    } catch {
      toast('You seem to be offline. The change wasn’t saved.', 'error');
      throw new Error('offline');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      toast(data.error || 'Something went wrong.', 'error');
      if (res.status === 401) setTimeout(() => location.reload(), 1500);
      throw new Error(data.error || res.status);
    }
    (data.errors || []).forEach(e => toast(e, 'error'));
    return data;
  }
  async function reloadTasks() {
    const data = await api('GET', `/api/projects/${boot.project.id}/tasks`);
    state.tasks = data.tasks;
    state.statuses = data.statuses.map(s => ({ ...s, id: +s.id }));
    render();
  }
  /** Merge a task (from the API) into local state. */
  function upsert(t) {
    const i = state.tasks.findIndex(x => x.id === t.id);
    const slim = { ...t };
    ['description', 'feed', 'attachments', 'subtask_list', 'parent', 'project_id'].forEach(k => delete slim[k]);
    if (i >= 0) state.tasks[i] = { ...state.tasks[i], ...slim };
    else state.tasks.push(slim);
  }

  function toast(msg, type = 'info') {
    let box = document.getElementById('toasts');
    if (!box) { box = h('div', { id: 'toasts', class: 'toasts' }); document.body.append(box); }
    const t = h('div', { class: `toast toast-${type}` }, msg);
    box.append(t);
    setTimeout(() => t.classList.add('is-hiding'), 3500);
    setTimeout(() => t.remove(), 4000);
  }

  // ---------- filters ----------
  const $ = id => document.getElementById(id);
  function visibleTasks() {
    const f = state.filters;
    const q = f.q.trim().toLowerCase();
    return state.tasks.filter(t => {
      if (!f.subtasks && t.parent_id && taskById(t.parent_id)) return false;
      if (q && !t.title.toLowerCase().includes(q)) return false;
      if (f.assignees.length) {
        const hit = f.assignees.some(a => a === 0 ? t.assignees.length === 0 : t.assignees.includes(a));
        if (!hit) return false;
      }
      if (f.priority && (f.priority === 'none' ? t.priority : t.priority !== f.priority)) return false;
      if (f.due === 'overdue' && !isOverdue(t)) return false;
      if (f.due === 'today' && t.due_date !== today) return false;
      if (f.due === 'week' && !(t.due_date && t.due_date >= today && t.due_date <= addDays(today, 7))) return false;
      if (f.due === 'none' && t.due_date) return false;
      if (f.hideDone && isDone(t)) return false;
      return true;
    });
  }
  function filtersActive() {
    const f = state.filters;
    return !!(f.q || f.assignees.length || f.priority || f.due);
  }

  function setupToolbar() {
    $('f-search').addEventListener('input', e => { state.filters.q = e.target.value; render(); });
    $('f-priority').addEventListener('change', e => { state.filters.priority = e.target.value; render(); });
    $('f-due').addEventListener('change', e => { state.filters.due = e.target.value; render(); });
    $('f-subtasks').checked = state.filters.subtasks;
    $('f-subtasks').addEventListener('change', e => { state.filters.subtasks = e.target.checked; store.set(KEY + ':subtasks', e.target.checked ? '1' : '0'); render(); });
    $('f-hide-done').checked = state.filters.hideDone;
    $('f-hide-done').addEventListener('change', e => { state.filters.hideDone = e.target.checked; store.set(KEY + ':hideDone', e.target.checked ? '1' : '0'); render(); });
    $('f-clear').addEventListener('click', () => {
      Object.assign(state.filters, { q: '', assignees: [], priority: '', due: '' });
      $('f-search').value = ''; $('f-priority').value = ''; $('f-due').value = '';
      render();
    });
    const fa = $('f-assignee');
    const btn = h('button', { type: 'button', class: 'pill-select' }, svg(ICON.user), h('span', {}, 'Assignee'));
    fa.append(btn);
    btn.addEventListener('click', () => {
      const opts = [{ id: 0, name: 'Unassigned', initials: '–', color: '#d1d5db', active: true }, ...boot.members];
      picker(btn, opts, state.filters.assignees, ids => { state.filters.assignees = ids; render(); });
    });
    document.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => {
      state.view = b.dataset.view;
      store.set(KEY + ':view', state.view);
      render();
    }));
  }
  function syncToolbar() {
    document.querySelectorAll('[data-view]').forEach(b => b.classList.toggle('is-active', b.dataset.view === state.view));
    const fa = $('f-assignee').querySelector('button');
    const n = state.filters.assignees.length;
    fa.classList.toggle('is-set', n > 0);
    fa.querySelector('span').textContent = n ? `Assignee (${n})` : 'Assignee';
    $('f-priority').classList.toggle('is-set', !!state.filters.priority);
    $('f-due').classList.toggle('is-set', !!state.filters.due);
    $('f-clear').hidden = !filtersActive();
  }

  // ---------- member picker (assignees and filters) ----------
  let openPicker = null;
  function closePicker() { openPicker?.remove(); openPicker = null; }
  function picker(anchor, options, selected, onChange) {
    closePicker();
    let chosen = [...selected];
    const list = h('div', { class: 'picker-list' });
    const search = h('input', { type: 'search', placeholder: 'Search people', class: 'picker-search' });
    const draw = () => {
      list.replaceChildren();
      const q = search.value.toLowerCase();
      options.filter(o => o.name.toLowerCase().includes(q)).forEach(o => {
        const on = chosen.includes(o.id);
        list.append(h('button', {
          type: 'button', class: 'picker-item' + (on ? ' is-on' : ''),
          onclick: () => { chosen = on ? chosen.filter(x => x !== o.id) : [...chosen, o.id]; onChange(chosen); draw(); },
        }, h('span', { class: 'avatar sm', style: `--c:${o.color}` }, o.initials), h('span', { class: 'grow' }, o.name), on ? '✓' : ''));
      });
      if (!list.children.length) list.append(h('p', { class: 'muted small pad' }, 'Nobody found.'));
    };
    search.addEventListener('input', draw);
    const pop = h('div', { class: 'popover' }, search, list);
    document.body.append(pop);
    const r = anchor.getBoundingClientRect();
    pop.style.top = Math.min(r.bottom + 6, innerHeight - 320) + scrollY + 'px';
    pop.style.left = Math.min(r.left, innerWidth - 260) + scrollX + 'px';
    openPicker = pop;
    draw();
    search.focus();
  }
  document.addEventListener('mousedown', e => { if (openPicker && !openPicker.contains(e.target) && !e.target.closest('.pill-select, .assignee-btn')) closePicker(); });

  // ---------- BOARD ----------
  function renderBoard() {
    const tasks = visibleTasks();
    const board = h('div', { class: 'board' });
    state.statuses.forEach(s => {
      const colTasks = tasks.filter(t => t.status_id === s.id).sort((a, b) => a.position - b.position || a.id - b.id);
      const cards = h('div', { class: 'cards', dataset: { status: s.id } });
      colTasks.forEach(t => cards.append(card(t)));
      const col = h('section', { class: 'column', style: `--sc:${s.color}` },
        h('header', { class: 'column-head' },
          h('span', { class: 'status-pill' }, s.name),
          h('span', { class: 'count' }, colTasks.length)),
        cards,
        boot.can.create ? addTaskButton(s) : null);
      board.append(col);
      dropZone(cards, s);
    });
    if (boot.can.statuses) board.append(h('a', { class: 'add-column', href: boot.settingsUrl }, svg(ICON.plus), 'Add or edit stages'));
    return board;
  }

  function card(t) {
    const parent = t.parent_id ? taskById(t.parent_id) : null;
    const meta = h('div', { class: 'card-meta' });
    if (t.assignees.length) meta.append(avatars(t.assignees));
    if (t.due_date) meta.append(h('span', { class: 'chip' + (isOverdue(t) ? ' is-overdue' : '') }, svg(ICON.cal), dueLabel(t.due_date)));
    if (t.priority) meta.append(h('span', { class: 'chip', style: `--pc:${PRIORITY_COLORS[t.priority]}` }, svg(ICON.flag, 'i flag'), P[t.priority]));
    const counts = h('div', { class: 'card-counts' });
    if (t.subtasks) counts.append(h('span', { title: 'Subtasks' }, svg(ICON.sub), `${t.subtasks_done}/${t.subtasks}`));
    if (t.comments) counts.append(h('span', { title: 'Comments' }, svg(ICON.chat), t.comments));
    if (t.files) counts.append(h('span', { title: 'Files' }, svg(ICON.clip), t.files));
    const el = h('article', {
      class: 'tcard' + (isDone(t) ? ' is-done' : ''), draggable: t.can.move ? 'true' : null, tabindex: '0',
      dataset: { id: t.id },
      onclick: () => openTask(t.id),
      onkeydown: e => { if (e.key === 'Enter') openTask(t.id); },
    },
      parent ? h('div', { class: 'card-parent' }, svg(ICON.sub), parent.title) : null,
      h('div', { class: 'tcard-title' }, t.title),
      meta.children.length ? meta : null,
      counts.children.length ? counts : null);
    if (t.can.move) {
      el.addEventListener('dragstart', e => {
        dragging = t.id;
        el.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(t.id));
      });
      el.addEventListener('dragend', () => { el.classList.remove('is-dragging'); dragging = null; document.querySelectorAll('.drop-marker').forEach(m => m.remove()); });
    }
    return el;
  }

  let dragging = null;
  function dropZone(container, status) {
    const marker = () => container.querySelector('.drop-marker') || h('div', { class: 'drop-marker' });
    const afterEl = y => [...container.querySelectorAll('.tcard:not(.is-dragging)')].find(c => {
      const r = c.getBoundingClientRect();
      return y < r.top + r.height / 2;
    });
    container.addEventListener('dragover', e => {
      if (!dragging) return;
      e.preventDefault();
      const m = marker();
      const after = afterEl(e.clientY);
      if (after) container.insertBefore(m, after); else container.append(m);
    });
    container.addEventListener('dragleave', e => { if (!container.contains(e.relatedTarget)) container.querySelector('.drop-marker')?.remove(); });
    container.addEventListener('drop', async e => {
      e.preventDefault();
      const id = dragging;
      const m = container.querySelector('.drop-marker');
      if (!id || !m) return;
      const el = document.querySelector(`.tcard[data-id="${id}"]`);
      container.insertBefore(el, m);
      m.remove();
      // Full order of the column (including cards hidden by filters, kept in their place at the end).
      const shown = [...container.querySelectorAll('.tcard')].map(c => +c.dataset.id);
      const hidden = state.tasks.filter(t => t.status_id === status.id && !shown.includes(t.id) && t.id !== id).sort((a, b) => a.position - b.position).map(t => t.id);
      const order = [...shown, ...hidden];
      const t = taskById(id);
      const prev = { status_id: t.status_id, completed_at: t.completed_at };
      t.status_id = status.id;
      order.forEach((tid, i) => { const x = taskById(tid); if (x) x.position = i; });
      render();
      try {
        const data = await api('POST', `/api/tasks/${id}/move`, { status_id: status.id, order });
        upsert(data.task);
        if (state.openId === id) refreshDetail();
        render();
      } catch {
        Object.assign(t, prev);
        reloadTasks();
      }
    });
  }

  function addTaskButton(status, parentId = null) {
    const wrap = h('div', { class: 'add-task' });
    const btn = h('button', { type: 'button', class: 'add-btn' }, svg(ICON.plus), 'Add task');
    btn.addEventListener('click', () => {
      const input = h('input', { type: 'text', class: 'add-input', placeholder: 'Task name, then Enter', maxlength: 255 });
      wrap.replaceChildren(input);
      input.focus();
      let busy = false;
      const done = () => { wrap.replaceChildren(btn); };
      input.addEventListener('keydown', async e => {
        if (e.key === 'Escape') done();
        if (e.key === 'Enter' && input.value.trim() && !busy) {
          busy = true;
          try {
            const data = await api('POST', `/api/projects/${boot.project.id}/tasks`, { title: input.value.trim(), status_id: status.id, parent_id: parentId });
            state.tasks.push(data.task);
            input.value = '';
            render();
            // keep adding: re-open the input in the re-rendered column
            const again = document.querySelector(`.cards[data-status="${status.id}"]`)?.parentElement.querySelector('.add-btn, .add-row-btn');
            again?.click();
          } finally { busy = false; }
        }
      });
      input.addEventListener('blur', () => { if (!input.value.trim()) setTimeout(done, 150); });
    });
    wrap.append(btn);
    return wrap;
  }

  // ---------- LIST ----------
  const COLS = [
    ['title', 'Name'], ['assignees', 'Assignees'], ['priority', 'Priority'], ['start_date', 'Start'], ['due_date', 'Due'], ['created_at', 'Created'], ['status_id', 'Status'],
  ];
  const PRIORITY_RANK = { urgent: 0, high: 1, normal: 2, low: 3 };
  function sortTasks(list) {
    const { key, dir } = state.sort;
    const val = t => {
      if (key === 'priority') return t.priority ? PRIORITY_RANK[t.priority] : 9;
      if (key === 'assignees') return t.assignees.map(id => members.get(id)?.name || '').sort()[0] || '~';
      if (key === 'title') return t.title.toLowerCase();
      if (key === 'status_id') return statusById(t.status_id)?.position ?? 0;
      return t[key] ?? (dir > 0 ? '~' : '');
    };
    return [...list].sort((a, b) => {
      const x = val(a), y = val(b);
      return (x < y ? -1 : x > y ? 1 : 0) * dir || a.position - b.position || a.id - b.id;
    });
  }

  function renderList() {
    const tasks = visibleTasks();
    const wrap = h('div', { class: 'list' });
    const head = h('div', { class: 'list-row list-head' }, COLS.map(([k, label]) => h('button', {
      type: 'button', class: 'th' + (state.sort.key === k ? ' is-sorted' : ''), dataset: { col: k },
      onclick: () => {
        state.sort = state.sort.key === k ? (state.sort.dir === 1 ? { key: k, dir: -1 } : { key: 'position', dir: 1 }) : { key: k, dir: 1 };
        render();
      },
    }, label, state.sort.key === k ? (state.sort.dir === 1 ? ' ↑' : ' ↓') : '')));
    wrap.append(head);

    state.statuses.forEach(s => {
      const group = sortTasks(tasks.filter(t => t.status_id === s.id));
      const collapsed = state.collapsed.has(s.id);
      const g = h('section', { class: 'list-group' + (collapsed ? ' is-collapsed' : ''), style: `--sc:${s.color}` });
      g.append(h('button', {
        type: 'button', class: 'group-head',
        onclick: () => {
          collapsed ? state.collapsed.delete(s.id) : state.collapsed.add(s.id);
          store.set(KEY + ':collapsed', JSON.stringify([...state.collapsed]));
          render();
        },
      }, svg(ICON.chev, 'i chev'), h('span', { class: 'status-pill' }, s.name), h('span', { class: 'count' }, group.length)));
      if (!collapsed) {
        group.forEach(t => g.append(listRow(t)));
        if (boot.can.create) {
          const add = addTaskButton(s);
          add.classList.add('list-add');
          g.append(add);
        }
      }
      wrap.append(g);
    });
    return wrap;
  }

  function listRow(t) {
    const parent = t.parent_id ? taskById(t.parent_id) : null;
    const save = async patch => {
      try {
        const data = await api('POST', `/api/tasks/${t.id}`, patch);
        upsert(data.task);
        if (state.openId === t.id) { state.detail = data.task; renderDrawer(); }
      } catch { /* toast shown */ }
      render();
    };
    const statusSel = h('select', { class: 'cell-select status-select', disabled: !t.can.move, style: `--sc:${statusById(t.status_id)?.color}`, onchange: e => save({ status_id: +e.target.value }) },
      state.statuses.map(s => h('option', { value: s.id, selected: s.id === t.status_id }, s.name)));
    const prioSel = h('select', { class: 'cell-select prio-select' + (t.priority ? '' : ' is-empty'), disabled: !t.can.fields, style: t.priority ? `--pc:${PRIORITY_COLORS[t.priority]}` : null, onchange: e => save({ priority: e.target.value || null }) },
      h('option', { value: '' }, '—'), Object.entries(P).map(([k, l]) => h('option', { value: k, selected: k === t.priority }, l)));
    const dateInput = (field) => h('input', {
      type: 'date', class: 'cell-date' + (field === 'due_date' && isOverdue(t) ? ' is-overdue' : '') + (t[field] ? '' : ' is-empty'),
      value: t[field] || '', disabled: !t.can.fields, onchange: e => save({ [field]: e.target.value || null }),
    });
    const assignBtn = h('button', { type: 'button', class: 'assignee-btn', disabled: !t.can.assign },
      t.assignees.length ? avatars(t.assignees) : h('span', { class: 'avatar sm empty' }, svg(ICON.user)));
    assignBtn.addEventListener('click', () => picker(assignBtn, activeMembers(), t.assignees, ids => {
      t.assignees = ids;
      clearTimeout(assignBtn._t);
      assignBtn._t = setTimeout(() => save({ assignees: ids }), 500);
      assignBtn.replaceChildren(ids.length ? avatars(ids) : h('span', { class: 'avatar sm empty' }, svg(ICON.user)));
    }));
    return h('div', { class: 'list-row' + (isDone(t) ? ' is-done' : ''), dataset: { id: t.id } },
      h('button', { type: 'button', class: 'cell-title', onclick: () => openTask(t.id) },
        h('span', { class: 'dot', style: `--sc:${statusById(t.status_id)?.color}` }),
        parent ? h('span', { class: 'muted small parent-crumb' }, parent.title + ' › ') : null,
        h('span', { class: 'title-text' }, t.title),
        t.subtasks ? h('span', { class: 'mini' }, svg(ICON.sub), `${t.subtasks_done}/${t.subtasks}`) : null,
        t.comments ? h('span', { class: 'mini' }, svg(ICON.chat), t.comments) : null),
      h('div', { class: 'cell', 'data-label': 'Assignees' }, assignBtn),
      h('div', { class: 'cell', 'data-label': 'Priority' }, prioSel),
      h('div', { class: 'cell', 'data-label': 'Start' }, dateInput('start_date')),
      h('div', { class: 'cell', 'data-label': 'Due' }, dateInput('due_date')),
      h('div', { class: 'cell muted small', 'data-label': 'Created' }, fmtDate(t.created_at)),
      h('div', { class: 'cell', 'data-label': 'Status' }, statusSel));
  }

  // ---------- render ----------
  function render() {
    syncToolbar();
    const view = $('view');
    const scroll = view.firstElementChild ? [view.firstElementChild.scrollLeft, view.scrollTop] : null;
    view.replaceChildren(state.view === 'list' ? renderList() : renderBoard());
    view.className = 'view view-' + state.view;
    if (scroll && view.firstElementChild) { view.firstElementChild.scrollLeft = scroll[0]; view.scrollTop = scroll[1]; }
  }

  // ---------- TASK PANEL ----------
  const drawer = $('drawer');
  const backdrop = $('drawer-backdrop');
  async function openTask(id, push = true) {
    state.openId = id;
    if (push) {
      const u = new URL(location.href);
      u.searchParams.set('task', id);
      history.pushState({ task: id }, '', u);
    }
    drawer.hidden = false;
    backdrop.hidden = false;
    document.body.classList.add('drawer-open');
    if (!state.detail || state.detail.id !== id) drawer.replaceChildren(h('div', { class: 'drawer-loading' }, 'Loading…'));
    try {
      const data = await api('GET', `/api/tasks/${id}`);
      if (state.openId !== id) return;
      state.detail = data.task;
      upsert(data.task);
      renderDrawer();
    } catch {
      closeTask();
    }
  }
  async function refreshDetail() {
    if (!state.openId) return;
    const data = await api('GET', `/api/tasks/${state.openId}`);
    state.detail = data.task;
    upsert(data.task);
    renderDrawer();
  }
  function closeTask(push = true) {
    saveDescriptionNow();
    state.openId = null;
    state.detail = null;
    drawer.hidden = true;
    backdrop.hidden = true;
    document.body.classList.remove('drawer-open');
    if (push) {
      const u = new URL(location.href);
      if (u.searchParams.has('task')) { u.searchParams.delete('task'); history.pushState({}, '', u); }
    }
    render();
  }
  backdrop.addEventListener('click', () => closeTask());
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && openPicker) { closePicker(); return; }
    if (e.key === 'Escape' && state.openId && !e.target.closest('input, textarea, [contenteditable]')) closeTask();
  });
  addEventListener('popstate', () => {
    const id = +new URL(location.href).searchParams.get('task');
    if (id) openTask(id, false); else if (state.openId) closeTask(false);
  });

  async function patch(fields) {
    const t = state.detail;
    try {
      const data = await api('POST', `/api/tasks/${t.id}`, fields);
      if (state.detail && state.detail.id === t.id) state.detail = data.task;
      upsert(data.task);
      render();
      renderDrawer();
    } catch {
      renderDrawer();
    }
  }

  let descTimer = null;
  let descPending = null;
  function saveDescriptionNow() {
    clearTimeout(descTimer);
    if (descPending) {
      const { id, html } = descPending;
      descPending = null;
      api('POST', `/api/tasks/${id}`, { description: html }).catch(() => {});
    }
  }

  function renderDrawer() {
    const t = state.detail;
    if (!t) return;
    const st = statusById(t.status_id);
    const keepScroll = drawer.querySelector('.feed')?.scrollTop;
    const draft = drawer.querySelector('.comment-box textarea')?.value;

    // header
    const head = h('header', { class: 'drawer-head' },
      h('div', { class: 'crumbs' },
        h('span', {}, boot.project.name),
        t.parent ? h('span', {}, ' › ', h('a', { href: '#', onclick: e => { e.preventDefault(); openTask(t.parent.id); } }, t.parent.title)) : null),
      h('div', { class: 'row-center' },
        h('button', { type: 'button', class: 'icon-btn', title: 'Copy link', onclick: () => { navigator.clipboard?.writeText(location.href); toast('Link copied'); } }, svg(ICON.link)),
        t.can.delete ? h('button', {
          type: 'button', class: 'icon-btn danger', title: 'Delete task',
          onclick: async () => {
            if (!confirm(`Move “${t.title}”${t.subtasks ? ' and its subtasks' : ''} to Trash?`)) return;
            await api('POST', `/api/tasks/${t.id}/delete`);
            state.tasks = state.tasks.filter(x => x.id !== t.id && x.parent_id !== t.id);
            toast('Moved to Trash');
            if (t.parent) openTask(t.parent.id); else closeTask();
          },
        }, svg(ICON.trash)) : null,
        h('button', { type: 'button', class: 'icon-btn', title: 'Close (Esc)', onclick: () => closeTask() }, svg(ICON.x))));

    // title
    const title = h('textarea', { class: 'task-title', rows: 1, maxlength: 255, readonly: !t.can.edit }, t.title);
    const fit = () => { title.style.height = 'auto'; title.style.height = title.scrollHeight + 'px'; };
    requestAnimationFrame(fit);
    title.addEventListener('input', fit);
    title.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); title.blur(); } });
    title.addEventListener('blur', () => { const v = title.value.trim(); if (v && v !== t.title) patch({ title: v }); else title.value = t.title; });

    // fields
    const statusSel = h('select', { class: 'status-select big', disabled: !t.can.move, style: `--sc:${st?.color}`, onchange: e => patch({ status_id: +e.target.value }) },
      state.statuses.map(s => h('option', { value: s.id, selected: s.id === t.status_id }, s.name)));
    const assignBtn = h('button', { type: 'button', class: 'assignee-btn', disabled: !t.can.assign },
      t.assignees.length ? h('span', { class: 'row-center gap-s' }, t.assignees.map(id => h('span', { class: 'person' }, avatar(id, 'sm'), members.get(id)?.name))) : h('span', { class: 'muted' }, 'Empty'));
    assignBtn.addEventListener('click', () => {
      let latest = t.assignees;
      picker(assignBtn, activeMembers(), t.assignees, ids => {
        latest = ids;
        clearTimeout(assignBtn._t);
        assignBtn._t = setTimeout(() => patch({ assignees: latest }), 600);
      });
    });
    const dateIn = field => h('input', { type: 'date', class: 'field-date' + (field === 'due_date' && t.due_date && t.due_date < today && st?.type !== 'done' ? ' is-overdue' : ''), value: t[field] || '', disabled: !t.can.fields, onchange: e => patch({ [field]: e.target.value || null }) });
    const prio = h('select', { class: 'prio-select' + (t.priority ? '' : ' is-empty'), disabled: !t.can.fields, style: t.priority ? `--pc:${PRIORITY_COLORS[t.priority]}` : null, onchange: e => patch({ priority: e.target.value || null }) },
      h('option', { value: '' }, 'Empty'), Object.entries(P).map(([k, l]) => h('option', { value: k, selected: k === t.priority }, l)));
    const creator = members.get(t.created_by);
    const fields = h('div', { class: 'fields' },
      fieldRow('Status', statusSel),
      fieldRow('Assignees', assignBtn),
      fieldRow('Dates', h('div', { class: 'row-center gap-s' }, dateIn('start_date'), h('span', { class: 'muted' }, '→'), dateIn('due_date'))),
      fieldRow('Priority', prio),
      fieldRow('Created', h('span', { class: 'muted' }, fmtWhen(t.created_at), creator ? ` by ${creator.name}` : '')),
      t.completed_at ? fieldRow('Completed', h('span', { class: 'muted' }, fmtWhen(t.completed_at))) : null);

    // description
    const desc = h('div', {
      class: 'description' + (t.description ? '' : ' is-empty'), contenteditable: t.can.edit ? 'true' : 'false',
      'data-placeholder': t.can.edit ? 'Add a description: goals, links, references…' : 'No description.',
      html: t.description || '',
    });
    desc.addEventListener('input', () => {
      desc.classList.toggle('is-empty', !desc.textContent.trim() && !desc.querySelector('li'));
      descPending = { id: t.id, html: desc.innerHTML };
      clearTimeout(descTimer);
      descTimer = setTimeout(() => { saveDescriptionNow(); t.description = desc.innerHTML; }, 1200);
    });
    desc.addEventListener('blur', () => { t.description = desc.innerHTML; saveDescriptionNow(); });
    desc.addEventListener('paste', e => {
      // Paste as plain text so formatting from other apps doesn't come along.
      e.preventDefault();
      document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
    });
    const tool = (label, cmd, arg, tip) => h('button', { type: 'button', title: tip, onmousedown: e => { e.preventDefault(); document.execCommand(cmd, false, arg); desc.dispatchEvent(new Event('input')); } }, label);
    const toolbar = t.can.edit ? h('div', { class: 'rt-toolbar' },
      tool(h('b', {}, 'B'), 'bold', null, 'Bold'), tool(h('i', {}, 'I'), 'italic', null, 'Italic'), tool(h('s', {}, 'S'), 'strikeThrough', null, 'Strikethrough'),
      tool('H', 'formatBlock', 'h3', 'Heading'), tool('¶', 'formatBlock', 'p', 'Normal text'),
      tool('• List', 'insertUnorderedList', null, 'Bulleted list'), tool('1. List', 'insertOrderedList', null, 'Numbered list'),
      h('button', { type: 'button', title: 'Link', onmousedown: e => {
        e.preventDefault();
        const u = prompt('Link URL (https://…)');
        if (u && /^(https?:\/\/|mailto:)/i.test(u)) { document.execCommand('createLink', false, u); desc.dispatchEvent(new Event('input')); }
      } }, 'Link')) : null;

    // subtasks
    const subs = h('div', { class: 'subtasks' });
    t.subtask_list.forEach(s => {
      const ss = statusById(s.status_id);
      subs.append(h('div', { class: 'sub-row' + (isDone(s) ? ' is-done' : '') },
        h('span', { class: 'dot', style: `--sc:${ss?.color}`, title: ss?.name }),
        h('button', { type: 'button', class: 'sub-title', onclick: () => openTask(s.id) }, s.title),
        s.due_date ? h('span', { class: 'chip' + (isOverdue(s) ? ' is-overdue' : '') }, dueLabel(s.due_date)) : null,
        avatars(s.assignees),
        (t.can.edit || s.can.edit) ? h('button', {
          type: 'button', class: 'icon-btn small', title: 'Detach (make it a normal task)',
          onclick: async () => { await api('POST', `/api/tasks/${s.id}/detach`); const x = taskById(s.id); if (x) x.parent_id = null; refreshDetail(); },
        }, svg(ICON.x)) : null));
    });
    const subActions = h('div', { class: 'row-center gap-s' });
    if (!t.parent_id && boot.can.create) {
      const addBtn = h('button', { type: 'button', class: 'btn btn-sm' }, '+ Add subtask');
      addBtn.addEventListener('click', () => {
        const input = h('input', { type: 'text', class: 'add-input', placeholder: 'Subtask name, then Enter', maxlength: 255 });
        subActions.replaceChildren(input);
        input.focus();
        input.addEventListener('keydown', async e => {
          if (e.key === 'Escape') renderDrawer();
          if (e.key === 'Enter' && input.value.trim()) {
            const data = await api('POST', `/api/projects/${boot.project.id}/tasks`, { title: input.value.trim(), parent_id: t.id });
            state.tasks.push(data.task);
            await refreshDetail();
            drawer.querySelector('.subtask-actions .btn')?.click();
          }
        });
      });
      subActions.append(addBtn);
    }
    if (!t.parent_id && t.can.edit) {
      const linkBtn = h('button', { type: 'button', class: 'btn btn-sm' }, svg(ICON.link), ' Link existing task');
      linkBtn.addEventListener('click', () => linkPicker(linkBtn, t));
      subActions.append(linkBtn);
    }
    subActions.classList.add('subtask-actions');

    // files
    const files = h('div', { class: 'files' });
    t.attachments.forEach(f => files.append(fileTile(f)));
    const fileInput = h('input', { type: 'file', multiple: true, hidden: true });
    fileInput.addEventListener('change', () => uploadFiles(t.id, fileInput.files));
    const uploadBtn = boot.can.upload ? h('button', { type: 'button', class: 'btn btn-sm', onclick: () => fileInput.click() }, svg(ICON.clip), ' Upload files') : null;

    const left = h('div', { class: 'drawer-main' },
      h('div', { class: 'task-type' }, h('span', { class: 'status-dot', style: `--sc:${st?.color}` }), t.parent ? 'Subtask' : 'Task', h('span', { class: 'muted' }, ` #${t.id}`)),
      title, fields,
      h('div', { class: 'section' }, h('h3', {}, 'Description'), toolbar, desc),
      !t.parent_id || t.subtask_list.length ? h('div', { class: 'section' },
        h('h3', {}, 'Subtasks', t.subtasks ? h('span', { class: 'muted' }, ` ${t.subtasks_done}/${t.subtasks} done`) : null),
        t.subtasks ? h('div', { class: 'progress' }, h('span', { style: `width:${Math.round(t.subtasks_done / t.subtasks * 100)}%` })) : null,
        subs, subActions) : null,
      h('div', { class: 'section dropzone', ondragover: e => { if (boot.can.upload && e.dataTransfer.types.includes('Files')) { e.preventDefault(); e.currentTarget.classList.add('is-over'); } }, ondragleave: e => e.currentTarget.classList.remove('is-over'), ondrop: e => { e.preventDefault(); e.currentTarget.classList.remove('is-over'); if (boot.can.upload) uploadFiles(t.id, e.dataTransfer.files); } },
        h('h3', {}, 'Files'), files.children.length ? files : h('p', { class: 'muted small' }, boot.can.upload ? 'Drop files here or upload. Up to ' + boot.maxUpload + ' MB each.' : 'No files.'),
        uploadBtn, fileInput));

    // activity + comments
    const feed = h('div', { class: 'feed' });
    t.feed.forEach(item => feed.append(item.type === 'comment' ? commentEl(item, t) : h('div', { class: 'event' },
      h('span', {}, h('strong', {}, item.user_id === boot.me ? 'You' : (members.get(item.user_id)?.name || 'Someone')), ' ', item.text),
      h('time', {}, fmtWhen(item.at)))));
    const box = boot.can.comment ? commentBox(t, draft) : h('p', { class: 'muted small pad' }, 'You can’t comment on tasks.');
    const right = h('div', { class: 'drawer-side' }, h('h3', { class: 'side-title' }, 'Activity'), feed, box);

    drawer.replaceChildren(head, h('div', { class: 'drawer-body' }, left, right));
    if (keepScroll !== undefined) feed.scrollTop = keepScroll; else feed.scrollTop = feed.scrollHeight;
  }

  function fieldRow(label, control) {
    return h('div', { class: 'field-row' }, h('div', { class: 'field-label' }, label), h('div', { class: 'field-value' }, control));
  }

  function fileTile(f) {
    return h('div', { class: 'file' },
      h('a', { href: f.url, target: '_blank', rel: 'noopener', class: 'file-link' },
        f.image ? h('img', { src: f.url, alt: '', loading: 'lazy' }) : h('span', { class: 'file-ext' }, (f.name.split('.').pop() || 'file').slice(0, 4).toUpperCase()),
        h('span', { class: 'file-name', title: f.name }, f.name),
        h('span', { class: 'muted small' }, f.size)),
      f.can_delete ? h('button', {
        type: 'button', class: 'icon-btn small file-del', title: 'Delete file',
        onclick: async () => { if (!confirm(`Delete ${f.name}?`)) return; const d = await api('POST', `/api/files/${f.id}/delete`); state.detail = d.task; upsert(d.task); renderDrawer(); render(); },
      }, svg(ICON.x)) : null);
  }

  async function uploadFiles(taskId, list) {
    if (!list || !list.length) return;
    const fd = new FormData();
    for (const f of list) {
      if (f.size > boot.maxUpload * 1048576) { toast(`${f.name} is over ${boot.maxUpload} MB.`, 'error'); continue; }
      fd.append('files[]', f);
    }
    if (!fd.has('files[]')) return;
    toast('Uploading…');
    const d = await api('POST', `/api/tasks/${taskId}/files`, fd);
    if (state.detail?.id === taskId) state.detail = d.task;
    upsert(d.task);
    renderDrawer();
    render();
  }

  function commentEl(c, t) {
    const who = members.get(c.user_id);
    const body = h('div', { class: 'comment-body', html: c.html });
    const actions = h('div', { class: 'comment-actions' });
    if (c.can_edit) actions.append(h('button', {
      type: 'button', class: 'link-btn', onclick: () => {
        const ta = h('textarea', { rows: 3 }, c.body);
        const save = h('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: async () => {
          if (!ta.value.trim()) return;
          const d = await api('POST', `/api/comments/${c.id}`, { body: ta.value });
          state.detail = d.task; renderDrawer();
        } }, 'Save');
        body.replaceChildren(ta, h('div', { class: 'row-center gap-s' }, save, h('button', { type: 'button', class: 'btn btn-sm', onclick: () => renderDrawer() }, 'Cancel')));
        ta.focus();
      },
    }, 'Edit'));
    if (c.can_delete) actions.append(h('button', {
      type: 'button', class: 'link-btn danger', onclick: async () => {
        if (!confirm('Delete this comment' + (c.files.length ? ' and its files' : '') + '?')) return;
        const d = await api('POST', `/api/comments/${c.id}/delete`);
        state.detail = d.task; upsert(d.task); renderDrawer(); render();
      },
    }, 'Delete'));
    return h('div', { class: 'comment' },
      h('div', { class: 'comment-head' }, avatar(c.user_id, 'sm'), h('strong', {}, who?.name || 'Former member'), h('time', {}, fmtWhen(c.at)), c.edited ? h('span', { class: 'muted small' }, '(edited)') : null),
      body,
      c.files.length ? h('div', { class: 'files compact' }, c.files.map(fileTile)) : null,
      actions.children.length ? actions : null);
  }

  function commentBox(t, draft) {
    const ta = h('textarea', { rows: 2, placeholder: 'Write a comment… (Ctrl+Enter to send)', maxlength: 10000 }, draft || '');
    const fileInput = h('input', { type: 'file', multiple: true, hidden: true });
    const chosen = h('div', { class: 'chosen-files' });
    fileInput.addEventListener('change', () => {
      chosen.replaceChildren(...[...fileInput.files].map(f => h('span', { class: 'chip' }, svg(ICON.clip), f.name)));
    });
    const send = async () => {
      if (!ta.value.trim() && !fileInput.files.length) return;
      const fd = new FormData();
      fd.append('body', ta.value);
      for (const f of fileInput.files) {
        if (f.size > boot.maxUpload * 1048576) { toast(`${f.name} is over ${boot.maxUpload} MB.`, 'error'); return; }
        fd.append('files[]', f);
      }
      sendBtn.disabled = true;
      try {
        const d = await api('POST', `/api/tasks/${t.id}/comments`, fd);
        ta.value = '';
        state.detail = d.task;
        upsert(d.task);
        render();
        drawer.querySelector('.comment-box textarea') && (drawer.querySelector('.comment-box textarea').value = '');
        renderDrawer();
        const feed = drawer.querySelector('.feed');
        feed.scrollTop = feed.scrollHeight;
      } finally { sendBtn.disabled = false; }
    };
    ta.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); } });
    const sendBtn = h('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: send }, 'Comment');
    return h('div', { class: 'comment-box' }, ta, chosen,
      h('div', { class: 'row-between' },
        boot.can.upload ? h('button', { type: 'button', class: 'icon-btn', title: 'Attach files', onclick: () => fileInput.click() }, svg(ICON.clip)) : h('span'),
        sendBtn),
      fileInput);
  }

  function linkPicker(anchor, t) {
    closePicker();
    const list = h('div', { class: 'picker-list' });
    const search = h('input', { type: 'search', placeholder: 'Search tasks in this project', class: 'picker-search' });
    let timer;
    const load = async () => {
      const d = await api('GET', `/api/tasks/${t.id}/linkable?q=${encodeURIComponent(search.value)}`);
      list.replaceChildren(...d.tasks.map(x => h('button', {
        type: 'button', class: 'picker-item',
        onclick: async () => {
          closePicker();
          const r = await api('POST', `/api/tasks/${t.id}/subtasks/link`, { task_id: x.id });
          const local = taskById(x.id); if (local) local.parent_id = t.id;
          state.detail = r.task; upsert(r.task); renderDrawer(); render();
        },
      }, h('span', { class: 'dot', style: `--sc:${x.color}` }), h('span', { class: 'grow' }, x.title), h('span', { class: 'muted small' }, x.status))));
      if (!d.tasks.length) list.append(h('p', { class: 'muted small pad' }, 'No matching tasks. Only top-level tasks without subtasks can be linked.'));
    };
    search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 200); });
    const pop = h('div', { class: 'popover wide' }, search, list);
    document.body.append(pop);
    const r = anchor.getBoundingClientRect();
    pop.style.top = Math.min(r.bottom + 6, innerHeight - 340) + scrollY + 'px';
    pop.style.left = Math.max(8, Math.min(r.left, innerWidth - 360)) + scrollX + 'px';
    openPicker = pop;
    search.focus();
    load();
  }

  // ---------- start ----------
  setupToolbar();
  render();
  const initial = +new URL(location.href).searchParams.get('task');
  if (initial) openTask(initial, false);
  addEventListener('beforeunload', saveDescriptionNow);
})();
