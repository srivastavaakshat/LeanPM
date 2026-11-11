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
    expanded: new Set(JSON.parse(store.get(KEY + ':expanded') || '[]')), // cards showing their subtasks
    filters: { q: '', assignees: [], priority: '', due: '', subtasks: store.get(KEY + ':subtasks') === '1', hideDone: store.get(KEY + ':hideDone') === '1' },
    openId: null,
    detail: null,
    files: null,        // the Files tab's list; null = needs (re)loading
    filesLoading: false,
    fileKind: '',
  };
  if (!['board', 'list', 'files'].includes(state.view)) state.view = 'board';
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
    check: '<path d="M5.5 10.5l3 3 6-7"/>',
  };

  // ---------- custom fields ----------
  const FIELDS = boot.fields || [];
  const fieldVal = (t, f) => (t.fields || {})[f.id] ?? '';
  const linkLabel = url => {
    try {
      const host = new URL(url).hostname.replace(/^www\./, '');
      if (host.endsWith('figma.com')) return 'Figma';
      if (host.endsWith('docs.google.com')) return 'Google Doc';
      if (host.endsWith('drive.google.com')) return 'Drive';
      return host;
    } catch { return 'Link'; }
  };
  const ICON_LINK_OUT = '<path d="M11 4h5v5M16 4l-7 7M14 11v4a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h4"/>';
  /** A clickable link that doesn't open the task underneath it. */
  const fieldLink = (f, url, cls = 'chip field-chip') => h('a', {
    href: url, target: '_blank', rel: 'noopener noreferrer', class: cls, title: `${f.name}: ${url}`,
    onclick: e => e.stopPropagation(), onkeydown: e => e.stopPropagation(),
  }, svg(ICON_LINK_OUT), linkLabel(url));
  function fieldText(f, v) {
    if (v === '') return '';
    if (f.type === 'checkbox') return v === '1' ? '✓' : '';
    if (f.type === 'date') return fmtDate(v);
    return v;
  }

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
    if (method !== 'GET') state.files = null;
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
    document.querySelectorAll('[data-kind]').forEach(b => b.addEventListener('click', () => {
      state.fileKind = b.dataset.kind;
      render();
    }));
    document.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => {
      state.view = b.dataset.view;
      store.set(KEY + ':view', state.view);
      render();
    }));
  }
  function syncToolbar() {
    document.querySelectorAll('[data-view]').forEach(b => b.classList.toggle('is-active', b.dataset.view === state.view));
    const files = state.view === 'files';
    $('task-filters').hidden = files;
    $('file-filters').hidden = !files;
    $('f-search').placeholder = files ? 'Search files or tasks' : 'Search tasks';
    document.querySelectorAll('[data-kind]').forEach(b => b.classList.toggle('is-set', b.dataset.kind === state.fileKind));
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
    FIELDS.filter(f => f.type === 'url' && fieldVal(t, f)).forEach(f => meta.append(fieldLink(f, fieldVal(t, f))));
    const counts = h('div', { class: 'card-counts' });
    const subs = t.parent_id ? [] : subtasksOf(t.id);
    const open = subs.length > 0 && state.expanded.has(t.id);
    if (subs.length) {
      counts.append(h('button', { type: 'button', class: 'sub-toggle' + (open ? ' is-open' : ''), 'aria-expanded': String(open), title: (open ? 'Hide' : 'Show') + ' subtasks (S)',
        onclick: e => { e.stopPropagation(); toggleSubs(t.id); } },
      svg(ICON.sub), `${t.subtasks_done}/${t.subtasks}`, svg(ICON.chev, 'i sub-chev')));
    } else if (t.subtasks) counts.append(h('span', { title: 'Subtasks' }, svg(ICON.sub), `${t.subtasks_done}/${t.subtasks}`));
    if (t.comments) counts.append(h('span', { title: 'Comments' }, svg(ICON.chat), t.comments));
    if (t.files) counts.append(h('span', { title: 'Files' }, svg(ICON.clip), t.files));
    const el = h('article', {
      class: 'tcard' + (isDone(t) ? ' is-done' : '') + (t.can.move ? ' can-move' : '') + (state.landed === t.id ? ' is-landed' : ''), tabindex: '0',
      dataset: { id: t.id },
      onclick: () => openTask(t.id),
      onkeydown: e => { if (e.key === 'Enter') openTask(t.id); },
    },
      parent ? h('div', { class: 'card-parent' }, svg(ICON.sub), parent.title) : null,
      h('div', { class: 'tcard-title' }, t.title),
      meta.children.length ? meta : null,
      counts.children.length ? counts : null,
      open ? h('div', { class: 'card-subs' }, subs.map(x => subRow(x, t))) : null);
    if (t.can.move) el.addEventListener('pointerdown', e => dragStart(e, t, el));
    return el;
  }

  // ---------- subtasks inside a card ----------
  // The subtask count on a card opens a list of its subtasks right on the board. Each one can be
  // ticked done, opened, or dragged out of the card to become a main task again.
  const subtasksOf = id => state.tasks.filter(x => x.parent_id === id).sort((a, b) => a.position - b.position || a.id - b.id);
  function toggleSubs(id, on = !state.expanded.has(id)) {
    on ? state.expanded.add(id) : state.expanded.delete(id);
    store.set(KEY + ':expanded', JSON.stringify([...state.expanded]));
    const had = document.activeElement?.closest('.tcard')?.dataset.id;
    render();
    if (had) focusCard(+had);
  }
  /** Keep a parent's subtask counts right after a local change. */
  function recount(id) {
    const p = taskById(id);
    if (!p) return;
    const kids = subtasksOf(id);
    p.subtasks = kids.length;
    p.subtasks_done = kids.filter(isDone).length;
  }
  function subRow(x, parent) {
    const done = isDone(x);
    const st = statusById(x.status_id);
    const row = h('div', {
      class: 'csub' + (done ? ' is-done' : '') + (x.can.move ? ' can-move' : '') + (state.landed === x.id ? ' is-landed' : ''), tabindex: '0',
      dataset: { id: x.id }, title: `${x.title} · ${st?.name || ''}`,
      onclick: e => { e.stopPropagation(); openTask(x.id); },
      onkeydown: e => { if (e.key === 'Enter') { e.stopPropagation(); openTask(x.id); } },
    },
      h('button', { type: 'button', class: 'csub-check', style: `--sc:${st?.color || '#999'}`, disabled: !x.can.move, 'aria-pressed': String(done),
        title: done ? 'Mark not done' : 'Mark done', onclick: e => { e.stopPropagation(); toggleDone(x); } }, svg(ICON.check)),
      h('span', { class: 'csub-title' }, x.title),
      x.assignees.length ? avatars(x.assignees.slice(0, 2)) : null);
    if (x.can.move && (x.can.edit || parent.can.edit)) row.addEventListener('pointerdown', e => { e.stopPropagation(); dragStart(e, x, row, parent); });
    return row;
  }
  function toggleDone(x) {
    const to = isDone(x) ? state.statuses.find(s => s.type === 'open') || state.statuses[0] : state.statuses.find(s => s.type === 'done');
    if (!to) { toast('This project has no Done stage.', 'error'); return; }
    const prev = x.status_id;
    x.status_id = to.id;
    recount(x.parent_id);
    render();
    api('POST', `/api/tasks/${x.id}`, { status_id: to.id }).then(d => {
      upsert(d.task);
      recount(x.parent_id);
      if (state.openId === x.parent_id) refreshDetail();
      render();
    }).catch(() => { x.status_id = prev; reloadTasks(); });
  }

  // ---------- drag a card between stages ----------
  // Pointer-based rather than HTML drag-and-drop, so the lifted card follows the hand: it tilts
  // with the movement, a slot opens where it will land, and it settles into that slot on release.
  // Mouse: drag after moving 5px. Touch: press and hold briefly, so swiping still scrolls the board.
  let drag = null;
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Hold a card over the middle of another card and it drops in as that card's subtask; a subtask
  // dragged out of its card (see subRow) lands in a column as a main task again.
  function dragStart(e, t, el, parent = null) {
    if (e.button !== 0 || drag || e.target.closest('a, button, input, select')) return;
    const touch = e.pointerType !== 'mouse';
    const d = drag = { t, el, parent, id: t.id, x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY, active: false, pointerId: e.pointerId, tilt: 0, vx: 0 };
    if (touch) d.hold = setTimeout(() => activate(d), 220);
    const move = ev => {
      if (ev.pointerId !== d.pointerId) return;
      d.vx = ev.clientX - d.x; d.x = ev.clientX; d.y = ev.clientY;
      if (!d.active) {
        const far = Math.hypot(d.x - d.x0, d.y - d.y0) > (touch ? 8 : 5);
        if (far && touch) return end(false);          // a swipe before the hold: let it scroll
        if (far) activate(d);
        return;
      }
      ev.preventDefault();
      frame();
    };
    const up = ev => { if (ev.pointerId === d.pointerId) end(d.active); };
    const key = ev => { if (ev.key === 'Escape' && d.active) { ev.preventDefault(); end(false, true); } };
    const blockScroll = ev => { if (d.active) ev.preventDefault(); };
    function end(commit, cancelled = false) {
      clearTimeout(d.hold);
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      removeEventListener('pointercancel', up);
      removeEventListener('keydown', key, true);
      removeEventListener('touchmove', blockScroll);
      if (d.active) finish(d, commit && !cancelled); else drag = null;
    }
    addEventListener('pointermove', move, { passive: false });
    addEventListener('pointerup', up);
    addEventListener('pointercancel', up);
    addEventListener('keydown', key, true);
    addEventListener('touchmove', blockScroll, { passive: false });
  }

  function activate(d) {
    if (d.active || drag !== d) return;
    d.active = true;
    const r = d.el.getBoundingClientRect();
    d.dx = d.x - r.left; d.dy = d.y - r.top;
    if (d.parent) {
      // A subtask lifted out of its card: it becomes a card-sized ghost, its row waits behind, faded.
      d.home = d.el.closest('.tcard');
      const w = d.home.getBoundingClientRect().width;
      d.ghost = h('article', { class: 'tcard is-ghost' }, h('div', { class: 'card-parent' }, svg(ICON.sub), d.parent.title), h('div', { class: 'tcard-title' }, d.t.title));
      d.dx = Math.min(d.dx, w - 24);
      Object.assign(d.ghost.style, { width: w + 'px', left: '0px', top: '0px', transform: `translate(${d.x - d.dx}px, ${r.top}px)` });
      document.body.append(d.ghost);
      d.slot = h('div', { class: 'drop-slot', style: `height:${d.ghost.offsetHeight}px` });
      d.el.classList.add('is-lifted-out');
    } else {
      d.from = d.el.parentElement;
      d.slot = h('div', { class: 'drop-slot', style: `height:${r.height}px` });
      d.ghost = d.el.cloneNode(true);
      d.ghost.classList.add('is-ghost');
      d.ghost.classList.remove('is-landed', 'is-nest-target');
      Object.assign(d.ghost.style, { width: r.width + 'px', left: '0px', top: '0px', transform: `translate(${r.left}px, ${r.top}px)` });
      document.body.append(d.ghost);
      d.el.replaceWith(d.slot);
    }
    document.body.classList.add('is-dragging-card');
    window.LPMKeys?.showHint([[[], d.parent ? 'Drop in a column to make it a main task' : 'Hold over a card to make it a subtask'], [['esc'], 'cancel']]);
    if (navigator.userActivation?.isActive) navigator.vibrate?.(8);
    requestAnimationFrame(() => d.ghost.classList.add('is-lifted'));
    frame();
    d.scroller = setInterval(() => autoScroll(d), 16);
  }

  // Follow the pointer, tilt with the speed, and move the slot to where the card would land.
  function frame() {
    const d = drag;
    if (!d?.active || d.raf) return;
    d.raf = requestAnimationFrame(() => {
      d.raf = 0;
      d.tilt += (Math.max(-7, Math.min(7, d.vx * 0.9)) - d.tilt) * 0.25;
      d.vx *= 0.6;
      d.ghost.style.transform = `translate(${d.x - d.dx}px, ${d.y - d.dy}px) rotate(${reduceMotion ? 0 : d.tilt.toFixed(2)}deg) scale(1.03)`;
      if (Math.abs(d.tilt) > 0.05 || Math.abs(d.vx) > 0.05) frame();
      placeSlot(d);
    });
  }

  // A task can go under another one when both are main tasks and it has no subtasks of its own.
  const canNest = (t, target) => target.id !== t.id && !target.parent_id && target.id !== t.parent_id
    && !state.tasks.some(x => x.parent_id === t.id) && t.can.edit && target.can.edit;

  function setNest(d, el = null, t = null) {
    if (d.nestEl === el) return;
    d.nestEl?.classList.remove('is-nest-target');
    d.nestEl = el;
    d.nest = t;
    el?.classList.add('is-nest-target');
    d.ghost.classList.toggle('is-nesting', !!el);
    d.slot.classList.toggle('is-idle', !!el);
  }

  function placeSlot(d) {
    const cols = [...document.querySelectorAll('.board .cards')];
    if (!cols.length) return;
    const under = document.elementFromPoint(d.x, d.y)?.closest('.board .tcard');
    // A subtask held over its own card goes back where it was.
    if (d.parent && under === d.home) {
      setNest(d);
      d.home.classList.add('is-home');
      if (d.slot.parentElement) flip([d.slot.parentElement], () => d.slot.remove());
      return;
    }
    d.home?.classList.remove('is-home');
    // Over the middle of a card that can take it: after a short hold, it becomes the drop target.
    const ut = under && taskById(+under.dataset.id);
    if (ut && canNest(d.t, ut)) {
      const r = under.getBoundingClientRect(), edge = Math.min(22, r.height * 0.25);
      if (d.y > r.top + edge && d.y < r.bottom - edge) {
        if (d.nestCand !== ut.id) { d.nestCand = ut.id; d.nestAt = performance.now(); }
        if (performance.now() - d.nestAt > 240) setNest(d, under, ut);
        return; // the slot stays put while you decide
      }
    }
    d.nestCand = null;
    setNest(d);
    // The column under the pointer, else the nearest one horizontally.
    let target = cols.find(c => { const r = c.closest('.column').getBoundingClientRect(); return d.x >= r.left && d.x <= r.right; });
    if (!target) target = cols.reduce((best, c) => { const r = c.getBoundingClientRect(); const dist = Math.abs(d.x - (r.left + r.right) / 2); return !best || dist < best.dist ? { c, dist } : best; }, null).c;
    document.querySelectorAll('.column.is-target').forEach(c => c !== target.closest('.column') && c.classList.remove('is-target'));
    target.closest('.column').classList.add('is-target');
    const after = [...target.querySelectorAll('.tcard')].find(c => { const r = c.getBoundingClientRect(); return d.y < r.top + r.height / 2; });
    if (d.slot.parentElement === target && (after ? d.slot.nextElementSibling === after : d.slot === target.lastElementChild)) return;
    flip([d.slot.parentElement || target, target], () => { if (after) target.insertBefore(d.slot, after); else target.append(d.slot); });
  }

  // Animate cards from their old spots to their new ones when the slot moves (FLIP).
  function flip(containers, change) {
    const cards = [...new Set(containers)].flatMap(c => [...c.querySelectorAll('.tcard')]);
    const before = new Map(cards.map(c => [c, c.getBoundingClientRect().top]));
    change();
    if (reduceMotion) return;
    cards.forEach(c => {
      const dy = before.get(c) - c.getBoundingClientRect().top;
      if (!dy) return;
      c.style.transition = 'none';
      c.style.transform = `translateY(${dy}px)`;
      requestAnimationFrame(() => { c.style.transition = ''; c.style.transform = ''; });
    });
  }

  function autoScroll(d) {
    const view = $('view'), r = view.getBoundingClientRect(), edge = 70;
    if (d.x < r.left + edge) view.scrollLeft -= (r.left + edge - d.x) / 4;
    else if (d.x > r.right - edge) view.scrollLeft += (d.x - (r.right - edge)) / 4;
    const cards = d.slot.parentElement || d.nestEl?.parentElement, cr = cards?.getBoundingClientRect();
    if (cr && d.y < cr.top + 40) cards.scrollTop -= 8;
    else if (cr && d.y > cr.bottom - 40) cards.scrollTop += 8;
    placeSlot(d);
  }

  // Settle the card into its slot (or back where it came from), then save the move.
  function finish(d, commit) {
    // The click that follows this pointerup mustn't open the task.
    const block = ev => { ev.stopPropagation(); ev.preventDefault(); };
    addEventListener('click', block, true);
    setTimeout(() => removeEventListener('click', block, true), 0);
    clearInterval(d.scroller);
    cancelAnimationFrame(d.raf);
    window.LPMKeys?.hideHint();
    d.home?.classList.remove('is-home');
    const nest = commit ? d.nest : null, nestEl = d.nestEl;
    const out = d.parent && commit && !nest && d.slot.parentElement; // a subtask dropped in a column
    if (!commit && !d.parent) flip([d.slot.parentElement, d.from], () => d.from.append(d.slot));
    if (!commit && d.parent && d.slot.parentElement) flip([d.slot.parentElement], () => d.slot.remove());
    const container = d.slot.parentElement;
    const status = container && state.statuses.find(s => String(s.id) === container.dataset.status);
    // Where the subtask lands among the cards (the slot is removed before saving).
    const order = out ? [...container.children].map(c => c === d.slot ? d.id : c.classList.contains('tcard') ? +c.dataset.id : 0).filter(Boolean) : null;
    const to = (nest ? nestEl : d.parent && !out ? d.el : d.slot).getBoundingClientRect();
    d.ghost.classList.remove('is-lifted');
    d.ghost.classList.add('is-settling');
    if (nest) d.ghost.classList.add('is-nested');
    d.ghost.style.transform = `translate(${nest ? to.left + to.width / 2 - d.ghost.offsetWidth / 2 : to.left}px, ${to.top}px)`;
    const done = () => {
      if (d.ended) return;
      d.ended = true;
      d.ghost.remove();
      if (d.parent) { d.slot.remove(); d.el.classList.remove('is-lifted-out'); } else d.slot.replaceWith(d.el);
      nestEl?.classList.remove('is-nest-target');
      document.querySelectorAll('.column.is-target').forEach(c => c.classList.remove('is-target'));
      document.body.classList.remove('is-dragging-card');
      drag = null;
      if (nest) nestUnder(d.t, nest);
      else if (out && status) unnest(d.t, status, order);
      else if (commit && status && !d.parent) commitMove(container, status, d.id);
      if (d.redraw) render();
    };
    d.ghost.addEventListener('transitionend', done, { once: true });
    setTimeout(done, reduceMotion ? 0 : 320);
  }

  // Save a card's new place. Returns without doing anything if it ended where it started.
  function commitMove(container, status, id) {
    // Full order of the column (including cards hidden by filters, kept in their place at the end).
    const shown = [...container.querySelectorAll('.tcard')].map(c => +c.dataset.id);
    const hidden = state.tasks.filter(t => t.status_id === status.id && !shown.includes(t.id) && t.id !== id).sort((a, b) => a.position - b.position).map(t => t.id);
    const order = [...shown, ...hidden];
    const t = taskById(id);
    const prev = { status_id: t.status_id, completed_at: t.completed_at, position: t.position };
    const same = t.status_id === status.id && state.tasks.filter(x => x.status_id === status.id).sort((a, b) => a.position - b.position || a.id - b.id).map(x => x.id).join() === order.join();
    if (same) return;
    t.status_id = status.id;
    order.forEach((tid, i) => { const x = taskById(tid); if (x) x.position = i; });
    // A brief lime glow on the card where it landed.
    state.landed = id;
    setTimeout(() => { if (state.landed === id) { state.landed = null; document.querySelector(`.tcard[data-id="${id}"]`)?.classList.remove('is-landed'); } }, 900);
    render();
    api('POST', `/api/tasks/${id}/move`, { status_id: status.id, order }).then(data => {
      upsert(data.task);
      if (state.openId === id) refreshDetail();
      render();
    }).catch(() => {
      Object.assign(t, prev);
      reloadTasks();
    });
  }

  function glow(id) {
    state.landed = id;
    setTimeout(() => { if (state.landed === id) { state.landed = null; document.querySelector(`[data-id="${id}"].is-landed`)?.classList.remove('is-landed'); } }, 900);
  }

  // Dropped on a card: it becomes that card's subtask, and the card opens to show it.
  function nestUnder(t, parent) {
    const old = t.parent_id;
    t.parent_id = parent.id;
    recount(parent.id);
    if (old) recount(old);
    state.expanded.add(parent.id);
    store.set(KEY + ':expanded', JSON.stringify([...state.expanded]));
    glow(t.id);
    render();
    api('POST', `/api/tasks/${parent.id}/subtasks/link`, { task_id: t.id }).then(data => {
      upsert(data.task);
      if ([t.id, parent.id, old].includes(state.openId)) refreshDetail();
      toast(`“${t.title}” is now a subtask of “${parent.title}”`);
      render();
    }).catch(() => { t.parent_id = old; reloadTasks(); });
  }

  // A subtask dragged out of its card: a main task again, in the column and place it was dropped.
  function unnest(t, status, order) {
    const old = t.parent_id;
    const hidden = state.tasks.filter(x => x.status_id === status.id && !order.includes(x.id)).sort((a, b) => a.position - b.position).map(x => x.id);
    order = [...order, ...hidden];
    t.parent_id = null;
    t.status_id = status.id;
    order.forEach((id, i) => { const x = taskById(id); if (x) x.position = i; });
    recount(old);
    glow(t.id);
    render();
    api('POST', `/api/tasks/${t.id}/detach`)
      .then(() => api('POST', `/api/tasks/${t.id}/move`, { status_id: status.id, order }))
      .then(data => {
        upsert(data.task);
        recount(old);
        if ([t.id, old].includes(state.openId)) refreshDetail();
        toast(`“${t.title}” is a main task again`);
        render();
      }).catch(() => reloadTasks());
  }

  function addTaskButton(status, parentId = null) {
    const wrap = h('div', { class: 'add-task' });
    const btn = h('button', { type: 'button', class: 'add-btn', title: 'New task (N)' }, svg(ICON.plus), 'Add task', parentId ? null : h('kbd', { class: 'add-kbd' }, 'N'));
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
      if (key.startsWith('f:')) {
        const f = FIELDS.find(x => 'f:' + x.id === key);
        const v = f ? fieldVal(t, f) : '';
        if (v === '') return dir > 0 ? '~' : '';
        return f.type === 'number' ? +v : String(v).toLowerCase();
      }
      return t[key] ?? (dir > 0 ? '~' : '');
    };
    return [...list].sort((a, b) => {
      const x = val(a), y = val(b);
      return (x < y ? -1 : x > y ? 1 : 0) * dir || a.position - b.position || a.id - b.id;
    });
  }

  function renderList() {
    const tasks = visibleTasks();
    const cols = [...COLS.slice(0, 6), ...FIELDS.map(f => ['f:' + f.id, f.name]), COLS[6]];
    const wrap = h('div', { class: 'list', style: `--cols: minmax(260px, 1fr) 120px 110px 130px 130px 90px${FIELDS.map(() => ' 150px').join('')} 140px; --list-min: ${900 + FIELDS.length * 158}px` });
    const head = h('div', { class: 'list-row list-head' }, cols.map(([k, label]) => h('button', {
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
      FIELDS.map(f => {
        const v = fieldVal(t, f);
        return h('div', { class: 'cell small field-cell', 'data-label': f.name },
          f.type === 'url' && v ? fieldLink(f, v, 'field-link') : h('span', { title: v }, fieldText(f, v)));
      }),
      h('div', { class: 'cell', 'data-label': 'Status' }, statusSel));
  }

  // ---------- FILES ----------
  const EXT = {
    doc: ['doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'pages', 'numbers', 'key', 'odt', 'ods', 'odp'],
    design: ['fig', 'sketch', 'psd', 'psb', 'ai', 'xd', 'eps', 'svg', 'indd', 'afdesign', 'afphoto', 'aep', 'prproj', 'blend'],
  };
  const extOf = name => (name.includes('.') ? name.split('.').pop() : '').toLowerCase();
  const kindOf = f => {
    const ext = extOf(f.name);
    if (f.image || (f.mime || '').startsWith('image/')) return 'image';
    if (f.mime === 'application/pdf' || ext === 'pdf') return 'pdf';
    if (EXT.doc.includes(ext)) return 'doc';
    if (EXT.design.includes(ext)) return 'design';
    return 'other';
  };
  const fmtSize = b => b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB';

  async function loadFiles() {
    if (state.filesLoading) return;
    state.filesLoading = true;
    try {
      const d = await api('GET', `/api/projects/${boot.project.id}/files`);
      state.files = d.files;
    } catch {
      state.files = [];
    } finally {
      state.filesLoading = false;
    }
    if (state.view === 'files') render();
  }

  function renderFiles() {
    const wrap = h('div', { class: 'files-view' });
    if (state.files === null) {
      loadFiles();
      wrap.append(h('p', { class: 'muted pad' }, 'Loading files…'));
      return wrap;
    }
    const q = state.filters.q.trim().toLowerCase();
    const list = state.files.filter(f =>
      (!state.fileKind || kindOf(f) === state.fileKind) &&
      (!q || f.name.toLowerCase().includes(q) || f.task_title.toLowerCase().includes(q)));
    const total = list.reduce((n, f) => n + f.bytes, 0);
    wrap.append(h('div', { class: 'files-summary muted small' },
      `${list.length} ${list.length === 1 ? 'file' : 'files'}` + (list.length ? ` · ${fmtSize(total)}` : '') +
      (state.files.length !== list.length ? ` (of ${state.files.length})` : '')));
    if (!state.files.length) {
      wrap.append(h('div', { class: 'files-empty' },
        h('strong', {}, 'No files in this project yet'),
        h('p', { class: 'muted' }, 'Files added to any task or comment show up here, newest first. Open a task and use “Upload files”, or attach files to a comment.')));
      return wrap;
    }
    if (!list.length) {
      wrap.append(h('p', { class: 'muted pad' }, 'No files match.'));
      return wrap;
    }
    const grid = h('div', { class: 'doc-grid' });
    list.forEach(f => {
      const ext = extOf(f.name);
      grid.append(h('div', { class: 'doc' },
        h('a', { href: f.url, target: '_blank', rel: 'noopener', class: 'doc-thumb', title: 'Open ' + f.name },
          f.image ? h('img', { src: f.url, alt: '', loading: 'lazy' }) : h('span', { class: `file-ext kind-${kindOf(f)}` }, (ext || 'file').slice(0, 5).toUpperCase())),
        h('div', { class: 'doc-info' },
          h('a', { href: f.url, target: '_blank', rel: 'noopener', class: 'doc-name', title: f.name }, f.name),
          h('div', { class: 'muted small' }, `${f.size} · ${fmtDate(f.created_at)} · ${f.user_name}`),
          h('button', { type: 'button', class: 'doc-task', title: 'Open the task', onclick: () => openTask(f.task_id) },
            svg(f.subtask ? ICON.sub : ICON.chat), h('span', { class: 'doc-task-title' }, f.task_title), f.comment_id ? h('span', { class: 'muted' }, ' · in a comment') : null)),
        f.can_delete ? h('button', {
          type: 'button', class: 'icon-btn small file-del', title: 'Delete file',
          onclick: async () => {
            if (!confirm(`Delete ${f.name}? It’s removed from its task too.`)) return;
            const d = await api('POST', `/api/files/${f.id}/delete`);
            upsert(d.task);
            if (state.openId === f.task_id) { state.detail = d.task; renderDrawer(); }
            render();
          },
        }, svg(ICON.x)) : null));
    });
    wrap.append(grid);
    return wrap;
  }

  // ---------- render ----------
  function render() {
    if (drag?.active) { drag.redraw = true; return; } // redrawn when the card is dropped
    syncToolbar();
    const view = $('view');
    // Keep the scroll position when re-drawing the same view; start at the top-left after switching views.
    const scroll = view.firstElementChild && state.drawnView === state.view ? [view.firstElementChild.scrollLeft, view.scrollTop] : [0, 0];
    if (state.drawnView !== state.view) view.scrollLeft = 0;
    state.drawnView = state.view;
    view.replaceChildren(state.view === 'files' ? renderFiles() : state.view === 'list' ? renderList() : renderBoard());
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
      // The cursor waits in the comment box, so you can type an update straight away.
      drawer.querySelector('.comment-box textarea')?.focus({ preventScroll: true });
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
    const was = state.openId;
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
    if (was) focusCard(was); // keyboard users carry on from the same card
  }
  backdrop.addEventListener('click', () => closeTask());
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && openPicker) { closePicker(); return; }
    if (e.key === 'Escape' && state.openId && !e.target.closest('input, textarea, [contenteditable]')) closeTask();
  });
  // ---------- keyboard ----------
  // Arrows move between cards, Enter opens, Shift+←/→ moves a card to the previous/next stage,
  // N adds a task, B/L/F switch views. The palette (⌘K) opens tasks here without reloading.
  const typingIn = el => window.LPMKeys?.typing(el) ?? !!el?.closest?.('input, textarea, select, [contenteditable]');
  function focusCard(id) {
    const el = document.querySelector(`.tcard[data-id="${id}"]`);
    if (el) { el.focus({ preventScroll: true }); el.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
  }
  function cardGrid() {
    return [...document.querySelectorAll('.board .cards')].map(c => [...c.querySelectorAll('.tcard')]);
  }
  function moveFocus(card, key) {
    const grid = cardGrid();
    const col = grid.findIndex(c => c.includes(card));
    if (col < 0) return;
    const row = grid[col].indexOf(card);
    if (key === 'ArrowUp' && row > 0) grid[col][row - 1].focus();
    else if (key === 'ArrowDown' && row < grid[col].length - 1) grid[col][row + 1].focus();
    else if (key === 'ArrowLeft' || key === 'ArrowRight') {
      const step = key === 'ArrowLeft' ? -1 : 1;
      for (let c = col + step; c >= 0 && c < grid.length; c += step) {
        if (grid[c].length) { grid[c][Math.min(row, grid[c].length - 1)].focus(); break; }
      }
    }
    document.activeElement?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
  function shiftStage(t, dir) {
    if (!t?.can.move) { toast('You can’t move this task.', 'error'); return; }
    const list = state.statuses;
    const to = list[list.findIndex(s => s.id === t.status_id) + dir];
    if (!to) return;
    const order = [...state.tasks.filter(x => x.status_id === to.id && x.id !== t.id).sort((a, b) => a.position - b.position || a.id - b.id).map(x => x.id), t.id];
    const prev = { status_id: t.status_id, completed_at: t.completed_at, position: t.position };
    t.status_id = to.id;
    order.forEach((tid, i) => { const x = taskById(tid); if (x) x.position = i; });
    state.landed = t.id;
    setTimeout(() => { if (state.landed === t.id) { state.landed = null; document.querySelector(`.tcard[data-id="${t.id}"]`)?.classList.remove('is-landed'); } }, 900);
    render();
    focusCard(t.id);
    api('POST', `/api/tasks/${t.id}/move`, { status_id: to.id, order }).then(data => { upsert(data.task); render(); focusCard(t.id); })
      .catch(() => { Object.assign(t, prev); reloadTasks(); });
  }
  function newTask() {
    if (!boot.can.create) return;
    if (state.view === 'files') { state.view = 'board'; store.set(KEY + ':view', 'board'); render(); }
    document.querySelector('.view .add-btn, .view .add-row-btn')?.click();
  }
  document.addEventListener('keydown', e => {
    if (e.metaKey || e.ctrlKey || e.altKey || e.defaultPrevented || state.openId || openPicker || drag || typingIn(e.target) || document.querySelector('dialog[open], .palette')) return;
    const card = e.target.closest?.('.tcard');
    if (card && e.key.startsWith('Arrow')) {
      e.preventDefault();
      if (e.shiftKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) shiftStage(taskById(+card.dataset.id), e.key === 'ArrowLeft' ? -1 : 1);
      else moveFocus(card, e.key);
      return;
    }
    if (e.key.startsWith('Arrow') && !card && state.view === 'board' && !e.target.closest('button, a')) {
      const first = document.querySelector('.board .tcard');
      if (first) { e.preventDefault(); first.focus(); }
      return;
    }
    const k = e.key.toLowerCase();
    if (k === 's' && card && !e.shiftKey) {
      const t = taskById(+card.dataset.id);
      if (t && subtasksOf(t.id).length) { e.preventDefault(); toggleSubs(t.id); }
      return;
    }
    if (k === 'n' && !e.shiftKey) { e.preventDefault(); newTask(); }
    else if (['b', 'l', 'f'].includes(k) && !e.shiftKey) {
      e.preventDefault();
      state.view = { b: 'board', l: 'list', f: 'files' }[k];
      store.set(KEY + ':view', state.view);
      render();
    }
  });
  addEventListener('lpm:new-task', newTask);
  addEventListener('lpm:open-task', e => {
    if (e.detail.projectId !== boot.project.id) return;
    e.preventDefault();
    openTask(e.detail.id);
  });
  // While a card has keyboard focus, a hint shows which keys work.
  document.addEventListener('focusin', e => {
    const card = e.target.closest?.('.tcard');
    if (!card || !window.LPMKeys || !card.matches(':focus-visible')) return;
    window.LPMKeys.showHint([[['←', '→', '↑', '↓'], 'move'], [['↵'], 'open'], ...(card.classList.contains('can-move') ? [[['⇧', '←', '→'], 'change stage']] : []),
      ...(card.querySelector('.sub-toggle') ? [[['S'], 'subtasks']] : []), [['N'], 'new task']]);
  });
  document.addEventListener('focusout', e => { if (e.target.closest?.('.tcard')) window.LPMKeys?.hideHint(150); });

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
    const commentFocused = !!document.activeElement?.matches?.('.comment-box textarea');

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
      t.completed_at ? fieldRow('Completed', h('span', { class: 'muted' }, fmtWhen(t.completed_at))) : null,
      FIELDS.map(f => fieldRow(f.name, fieldControl(t, f))));

    // description
    const desc = h('div', {
      class: 'description' + (t.description ? '' : ' is-empty'), contenteditable: t.can.edit ? 'true' : 'false',
      'data-placeholder': t.can.edit ? 'Add a description: goals, links, references…' : 'No description.',
      html: t.description || '',
    });
    desc.querySelectorAll('.mention').forEach(m => { m.contentEditable = 'false'; });
    if (t.can.edit) mentionEditable(desc);
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
      docsSection(t),
      h('div', { class: 'section' },
        h('h3', {}, 'Files'), files.children.length ? files : h('p', { class: 'muted small' }, boot.can.upload ? 'Drop files anywhere on this side, or upload. Up to ' + boot.maxUpload + ' MB each.' : 'No files.'),
        uploadBtn, fileInput));
    // Drop files anywhere on the left to add them to the task's files.
    if (boot.can.upload) window.LPMDrop?.(left, 'Drop to add to Files', list => uploadFiles(t.id, list));

    // activity + comments
    const feed = h('div', { class: 'feed' });
    t.feed.forEach(item => feed.append(item.type === 'comment' ? commentEl(item, t) : h('div', { class: 'event' },
      h('span', {}, h('strong', {}, item.user_id === boot.me ? 'You' : (members.get(item.user_id)?.name || 'Someone')), ' ', item.text),
      h('time', {}, fmtWhen(item.at)))));
    const box = boot.can.comment ? commentBox(t, draft) : h('p', { class: 'muted small pad' }, 'You can’t comment on tasks.');
    const right = h('div', { class: 'drawer-side' }, h('h3', { class: 'side-title' }, 'Activity'), feed, box);
    // Drop files on the activity side to post them as a comment (with anything already typed).
    if (boot.can.comment && boot.can.upload && box.sendFiles) window.LPMDrop?.(right, 'Drop to post as a comment', box.sendFiles);

    drawer.replaceChildren(head, h('div', { class: 'drawer-body' }, left, right));
    if (keepScroll !== undefined) feed.scrollTop = keepScroll; else feed.scrollTop = feed.scrollHeight;
    if (commentFocused) { const ta = drawer.querySelector('.comment-box textarea'); ta?.focus({ preventScroll: true }); ta?.setSelectionRange(ta.value.length, ta.value.length); }
  }

  /** Editor for one custom field in the task panel. Saves on change. */
  function fieldControl(t, f) {
    const v = fieldVal(t, f);
    const off = !t.can.fields;
    const save = value => { if (value !== v) patch({ fields: { [f.id]: value } }); };
    if (f.type === 'select') {
      return h('select', { class: 'cf-input' + (v ? '' : ' is-empty'), disabled: off, onchange: e => save(e.target.value) },
        h('option', { value: '' }, 'Empty'), f.options.map(o => h('option', { value: o, selected: o === v }, o)));
    }
    if (f.type === 'checkbox') {
      return h('input', { type: 'checkbox', class: 'cf-check', checked: v === '1', disabled: off, onchange: e => save(e.target.checked ? '1' : '') });
    }
    const type = { url: 'url', number: 'number', date: 'date' }[f.type] || 'text';
    const input = h('input', {
      type, class: 'cf-input' + (v ? '' : ' is-empty'), value: v, disabled: off, step: f.type === 'number' ? 'any' : null,
      placeholder: off ? 'Empty' : f.type === 'url' ? 'Paste a link' : 'Empty',
      onchange: e => save(e.target.value.trim()),
      onkeydown: e => { if (e.key === 'Enter') e.target.blur(); if (e.key === 'Escape') { e.target.value = v; e.target.blur(); } },
    });
    if (f.type !== 'url' || !v) return input;
    return h('div', { class: 'row-center gap-s cf-url' }, input,
      h('a', { href: v, target: '_blank', rel: 'noopener noreferrer', class: 'btn btn-sm', title: 'Open ' + v }, svg(ICON_LINK_OUT), 'Open ' + linkLabel(v)));
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
        mentionTextarea(ta);
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
    const mod = window.LPMKeys?.mod || 'Ctrl';
    const ta = h('textarea', { rows: 2, placeholder: `Write a comment… @ to mention · ${mod}+Enter to send · Esc to close`, maxlength: 10000 }, draft || '');
    mentionTextarea(ta);
    const fileInput = h('input', { type: 'file', multiple: true, hidden: true });
    const chosen = h('div', { class: 'chosen-files' });
    fileInput.addEventListener('change', () => {
      chosen.replaceChildren(...[...fileInput.files].map(f => h('span', { class: 'chip' }, svg(ICON.clip), f.name)));
    });
    const send = async (dropped = []) => {
      const list = [...fileInput.files, ...dropped];
      if (!ta.value.trim() && !list.length) return;
      const fd = new FormData();
      fd.append('body', ta.value);
      for (const f of list) {
        if (f.size > boot.maxUpload * 1048576) { toast(`${f.name} is over ${boot.maxUpload} MB.`, 'error'); return; }
        fd.append('files[]', f);
      }
      sendBtn.disabled = true;
      if (dropped.length) toast('Uploading…');
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
    ta.addEventListener('keydown', e => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
      // Esc leaves the comment box; with nothing typed it closes the task in one go.
      else if (e.key === 'Escape' && !e.defaultPrevented) { e.preventDefault(); if (ta.value.trim()) ta.blur(); else closeTask(); }
    });
    const sendBtn = h('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: () => send(), title: `${mod}+Enter` }, 'Comment', h('kbd', { class: 'btn-kbd' }, `${mod === '⌘' ? '⌘' : 'Ctrl'}↵`));
    const box = h('div', { class: 'comment-box' }, ta, chosen,
      h('div', { class: 'row-between' },
        boot.can.upload ? h('button', { type: 'button', class: 'icon-btn', title: 'Attach files (or drop them here)', onclick: () => fileInput.click() }, svg(ICON.clip)) : h('span'),
        sendBtn),
      fileInput);
    box.sendFiles = list => send([...list]);
    return box;
  }

  // ---------- meeting-notes docs connected to a task ----------
  // A connected doc is where the task gets discussed; tasks created in it become its subtasks.
  const DOC_ICON = '<path d="M6 3h5l4 4v9a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z"/><path d="M11 3v4h4M8 11h5M8 14h3"/>';
  function docsSection(t) {
    if (!t.can.docs && !(t.docs || []).length) return null;
    const rows = (t.docs || []).map(d => h('div', { class: 'task-doc' },
      h('span', { class: 'task-doc-icon' }, svg(DOC_ICON)),
      h('a', { class: 'grow task-doc-title', href: d.url, title: 'Open the doc' }, d.title),
      d.visibility === 'private' ? h('span', { class: 'tag' }, 'Only you') : null,
      d.can_unlink ? h('button', { type: 'button', class: 'icon-btn small', title: 'Disconnect this doc', onclick: async () => {
        const r = await api('POST', `/api/tasks/${t.id}/docs/${d.id}/unlink`);
        state.detail = r.task; renderDrawer();
        toast(`Disconnected “${d.title}”`);
      } }, svg(ICON.x)) : null));
    const actions = t.can.docs ? h('div', { class: 'row-center gap-s' },
      h('button', { type: 'button', class: 'btn btn-sm', onclick: async e => {
        e.currentTarget.disabled = true;
        const r = await api('POST', `/api/tasks/${t.id}/docs`, {});
        location.href = r.doc.url; // straight into the new doc
      } }, '+ New doc'),
      h('button', { type: 'button', class: 'btn btn-sm', onclick: e => docPicker(e.currentTarget, t) }, svg(DOC_ICON), ' Connect a doc')) : null;
    return h('div', { class: 'section' },
      h('h3', {}, 'Docs', rows.length ? h('span', { class: 'muted' }, ` ${rows.length}`) : null),
      rows.length ? h('div', { class: 'task-docs' }, rows) : null,
      h('p', { class: 'muted small' }, 'Meeting notes for this task. Tasks you create in a connected doc become its subtasks.'),
      actions);
  }
  async function docPicker(anchor, t) {
    closePicker();
    const list = h('div', { class: 'picker-list' }, h('p', { class: 'muted small pad' }, 'Loading…'));
    const pop = h('div', { class: 'popover wide' }, h('div', { class: 'slash-label' }, 'Your meeting notes'), list);
    document.body.append(pop);
    const r = anchor.getBoundingClientRect();
    pop.style.top = Math.min(r.bottom + 6, innerHeight - 340) + scrollY + 'px';
    pop.style.left = Math.max(8, Math.min(r.left, innerWidth - 360)) + scrollX + 'px';
    openPicker = pop;
    const d = await api('GET', `/api/tasks/${t.id}/docs/available`);
    list.replaceChildren(...d.docs.map(doc => h('button', {
      type: 'button', class: 'picker-item',
      onclick: async () => {
        closePicker();
        const res = await api('POST', `/api/tasks/${t.id}/docs/link`, { doc_id: doc.id });
        state.detail = res.task; renderDrawer();
        toast(`Connected “${doc.title}”. Tasks you create in it become subtasks here.`);
      },
    }, svg(DOC_ICON), h('span', { class: 'grow' }, doc.title, doc.linked_to ? h('span', { class: 'muted small' }, ` · now on “${doc.linked_to}”`) : null))));
    if (!d.docs.length) list.append(h('p', { class: 'muted small pad' }, 'You have no other meeting notes. Use “+ New doc” to start one for this task.'));
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

  // ---------- @mentions ----------
  // Typing @ in a comment or description lists the people who can open this project.
  const mentionable = () => boot.mentionable.map(id => members.get(id)).filter(Boolean);
  const mention = { pop: null, items: [], index: 0, pick: null };
  const MENTION_QUERY = /(?:^|[\s(])@([^@\n]{0,30})$/u;

  function mentionMatches(q) {
    q = q.toLowerCase();
    return mentionable().filter(m => !q || m.name.toLowerCase().startsWith(q) || m.name.toLowerCase().split(/\s+/).some(w => w.startsWith(q)))
      .slice(0, 8);
  }
  function closeMention() { mention.pop?.remove(); mention.pop = null; mention.items = []; mention.pick = null; }
  function drawMention() {
    mention.pop.replaceChildren(...mention.items.map((m, i) => h('button', {
      type: 'button', class: 'picker-item' + (i === mention.index ? ' is-on' : ''),
      onmousedown: e => { e.preventDefault(); mention.pick(m); },
    }, h('span', { class: 'avatar sm', style: `--c:${m.color}` }, m.initials), h('span', { class: 'grow' }, m.name))));
  }
  function openMention(rect, items, pick) {
    if (!items.length) return closeMention();
    if (!mention.pop) {
      mention.pop = h('div', { class: 'popover mention-pop' });
      document.body.append(mention.pop);
    }
    mention.items = items;
    mention.index = Math.min(mention.index, items.length - 1);
    mention.pick = m => { pick(m); closeMention(); };
    drawMention();
    const below = rect.bottom + 6 + mention.pop.offsetHeight < innerHeight;
    mention.pop.style.top = (below ? rect.bottom + 6 : rect.top - mention.pop.offsetHeight - 6) + scrollY + 'px';
    mention.pop.style.left = Math.max(8, Math.min(rect.left, innerWidth - 260)) + scrollX + 'px';
  }
  /** Arrow keys / Enter / Tab / Escape while the list is open. Returns true when handled. */
  function mentionKey(e) {
    if (!mention.pop) return false;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      mention.index = (mention.index + (e.key === 'ArrowDown' ? 1 : -1) + mention.items.length) % mention.items.length;
      drawMention();
    } else if ((e.key === 'Enter' && !e.ctrlKey && !e.metaKey) || e.key === 'Tab') {
      mention.pick(mention.items[mention.index]);
    } else if (e.key === 'Escape') {
      closeMention();
    } else {
      return false;
    }
    e.preventDefault();
    e.stopImmediatePropagation();
    return true;
  }

  function mentionTextarea(ta) {
    const check = () => {
      const before = ta.value.slice(0, ta.selectionStart);
      const m = before.match(MENTION_QUERY);
      if (!m || ta.selectionStart !== ta.selectionEnd) return closeMention();
      mention.index = 0;
      const r = ta.getBoundingClientRect();
      openMention({ top: r.top, bottom: r.bottom, left: r.left + 8 }, mentionMatches(m[1]), person => {
        const start = ta.selectionStart - m[1].length - 1;
        const insert = '@' + person.name + ' ';
        ta.value = ta.value.slice(0, start) + insert + ta.value.slice(ta.selectionStart);
        ta.selectionStart = ta.selectionEnd = start + insert.length;
        ta.focus();
      });
    };
    ta.addEventListener('keydown', mentionKey, true);
    ta.addEventListener('input', check);
    ta.addEventListener('click', check);
    ta.addEventListener('blur', () => setTimeout(closeMention, 150));
  }

  function mentionEditable(el) {
    const check = () => {
      const sel = getSelection();
      if (!sel.rangeCount || !sel.isCollapsed) return closeMention();
      const range = sel.getRangeAt(0);
      const node = range.startContainer;
      if (node.nodeType !== Node.TEXT_NODE || !el.contains(node)) return closeMention();
      const before = node.textContent.slice(0, range.startOffset);
      const m = before.match(MENTION_QUERY);
      if (!m) return closeMention();
      mention.index = 0;
      const at = document.createRange();
      at.setStart(node, range.startOffset - m[1].length - 1);
      at.setEnd(node, range.startOffset);
      const rect = at.getBoundingClientRect();
      openMention(rect, mentionMatches(m[1]), person => {
        at.deleteContents();
        const chip = h('span', { class: 'mention', 'data-user': person.id, contenteditable: 'false' }, '@' + person.name);
        const space = document.createTextNode('\u00a0');
        at.insertNode(space);
        at.insertNode(chip);
        const after = document.createRange();
        after.setStart(space, 1);
        after.collapse(true);
        sel.removeAllRanges();
        sel.addRange(after);
        el.dispatchEvent(new Event('input'));
      });
    };
    el.addEventListener('keydown', mentionKey, true);
    el.addEventListener('input', e => { if (e.isTrusted) check(); });
    el.addEventListener('keyup', e => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) check(); });
    el.addEventListener('click', check);
    el.addEventListener('blur', () => setTimeout(closeMention, 150));
  }

  // ---------- start ----------
  setupToolbar();
  render();
  const initial = +new URL(location.href).searchParams.get('task');
  if (initial) openTask(initial, false);
  addEventListener('beforeunload', saveDescriptionNow);
})();
