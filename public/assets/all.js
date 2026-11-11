// All tasks: every visible task grouped by project, the Inbox first, as a list (with a "Move to"
// picker, and checkboxes to move or trash many at once) or a board with one column per project,
// where dragging a card moves it to that project.
(() => {
  'use strict';
  const { boot, h, svg, api, toast, fmtDate, isOverdue, avatars, peek } = window.LPM;
  const groups = document.getElementById('all-groups');
  const projects = new Map(boot.projects.map(p => [p.id, p]));
  const statusOf = t => (boot.statuses[t.project_id] || []).find(s => s.id === t.status_id) || { name: '?', color: '#999', type: 'open' };
  const store = { get(k) { try { return localStorage.getItem(k); } catch { return null; } }, set(k, v) { try { localStorage.setItem(k, v); } catch { /* ignore */ } } };
  const collapsed = new Set(JSON.parse(store.get(`lpm:${boot.org}:all:collapsed`) || '[]'));
  const f = { q: '', mine: false, hideDone: true, subtasks: false };
  const byId = new Map(boot.tasks.map(t => [t.id, t]));
  let view = store.get(`lpm:${boot.org}:all:view`) === 'board' ? 'board' : 'list';
  const page = document.querySelector('.all-page');
  const openPeek = t => peek.open(t.id, { onChange: task => { apply(task); render(); } });
  const canMoveTask = t => boot.can.move && t.can.edit && !t.parent_id;
  const selectable = t => canMoveTask(t) || t.can.delete;
  const picked = new Set();     // ticked task ids (list view)
  let lastPicked = null;        // for Shift+click ranges
  const bulkBar = document.getElementById('all-bulk');

  function visible(t) {
    if (!f.subtasks && t.parent_id && byId.has(t.parent_id)) return false;
    if (f.q && !t.title.toLowerCase().includes(f.q)) return false;
    if (f.mine && !t.assignees.includes(boot.me)) return false;
    if (f.hideDone && statusOf(t).type === 'done') return false;
    return true;
  }

  async function move(t, projectId) {
    const d = await api('POST', `/api/tasks/${t.id}/project`, { project_id: projectId });
    apply(d.task);
    // Subtasks move with their parent.
    boot.tasks.filter(x => x.parent_id === t.id).forEach(x => { x.project_id = projectId; });
    if (!boot.statuses[projectId]) boot.statuses[projectId] = d.task.statuses;
    render();
    toast(`Moved to ${projects.get(projectId)?.name || 'project'}`);
  }
  function apply(task) {
    const t = byId.get(task.id);
    if (!t) return;
    Object.assign(t, { title: task.title, status_id: task.status_id, project_id: task.project.id, due_date: task.due_date, assignees: task.assignees, parent_id: task.parent ? task.parent.id : null });
    boot.statuses[t.project_id] = task.statuses;
  }

  function row(t, group) {
    const st = statusOf(t);
    const done = st.type === 'done';
    const canMove = canMoveTask(t);
    const inInbox = projects.get(t.project_id)?.inbox;
    const sel = canMove ? h('select', { class: 'move-select' + (inInbox ? ' is-inbox' : ''), title: 'Move to another project', onchange: e => move(t, +e.target.value) },
      inInbox ? h('option', { value: t.project_id, selected: true }, 'Move to project…') : null,
      boot.projects.filter(p => !(inInbox && p.inbox)).map(p => h('option', { value: p.id, selected: p.id === t.project_id && !inInbox }, p.inbox ? 'Inbox' : p.name))) : null;
    const parent = t.parent_id ? byId.get(t.parent_id) : null;
    const tick = selectable(t) ? h('input', { type: 'checkbox', class: 'all-tick', checked: picked.has(t.id), 'aria-label': `Select “${t.title}”`,
      onclick: e => pick(t, e.target.checked, e.shiftKey ? group : null) }) : null;
    return h('div', { class: 'all-row' + (done ? ' is-done' : '') + (picked.has(t.id) ? ' is-picked' : ''), dataset: { id: t.id } },
      h('span', { class: 'all-check' }, tick),
      h('span', { class: 'all-stage', style: `--sc:${st.color}` }, st.name),
      h('button', { type: 'button', class: 'all-title', onclick: () => openPeek(t), onkeydown: e => {
        if (e.key.toLowerCase() === 'x' && selectable(t) && !e.metaKey && !e.ctrlKey) { e.preventDefault(); pick(t, !picked.has(t.id)); }
      } },
        parent ? h('span', { class: 'muted small' }, parent.title + ' › ') : null, t.title),
      h('span', { class: 'all-people' }, t.assignees.length ? avatars(t.assignees) : null),
      h('span', { class: 'all-due' + (isOverdue(t.due_date, done) ? ' is-overdue' : '') }, t.due_date ? fmtDate(t.due_date) : ''),
      h('span', { class: 'all-move' }, sel));
  }

  function render() {
    page.classList.toggle('is-board', view === 'board');
    document.querySelectorAll('[data-all-view]').forEach(b => b.classList.toggle('is-active', b.dataset.allView === view));
    if (view === 'board') { picked.clear(); renderBulk(); return renderBoard(); }
    const list = boot.tasks.filter(visible);
    // Only what you can see stays ticked: a filter or a collapsed group drops it from the selection.
    const shown = new Set(list.filter(t => !collapsed.has(t.project_id) || projects.get(t.project_id)?.inbox).map(t => t.id));
    [...picked].forEach(id => { if (!shown.has(id)) picked.delete(id); });
    groups.replaceChildren(...boot.projects.map(p => {
      const items = list.filter(t => t.project_id === p.id);
      if (!p.inbox && !items.length && (f.q || f.mine)) return null;
      const isCollapsed = collapsed.has(p.id) && !p.inbox;
      const can = items.filter(selectable);
      const all = can.length > 0 && can.every(t => picked.has(t.id));
      const tickAll = can.length && !isCollapsed ? h('input', { type: 'checkbox', class: 'all-tick', checked: all, 'aria-label': `Select every task in ${p.inbox ? 'the Inbox' : p.name}`,
        onclick: e => { can.forEach(t => e.target.checked ? picked.add(t.id) : picked.delete(t.id)); render(); } }) : null;
      if (tickAll && !all && can.some(t => picked.has(t.id))) tickAll.indeterminate = true;
      return h('section', { class: 'all-group' + (p.inbox ? ' is-inbox' : '') + (isCollapsed ? ' is-collapsed' : '') },
        h('header', { class: 'all-group-head' },
          h('span', { class: 'all-check' }, tickAll),
          h('button', { type: 'button', class: 'group-head', disabled: p.inbox, onclick: () => {
            collapsed.has(p.id) ? collapsed.delete(p.id) : collapsed.add(p.id);
            store.set(`lpm:${boot.org}:all:collapsed`, JSON.stringify([...collapsed]));
            render();
          } },
            p.inbox ? null : svg('<path d="M7 5l5 5-5 5"/>', 'i chev'),
            h('strong', {}, p.inbox ? 'Inbox' : p.name), h('span', { class: 'count' }, items.length)),
          p.inbox ? h('span', { class: 'muted small all-hint' }, 'Not in a project yet. Tick tasks to move them together, or use “Move to project…”.')
            : h('a', { class: 'small', href: boot.base.replace(/\/$/, '') + `/o/${boot.org}/p/${p.id}` }, 'Open board →')),
        isCollapsed ? null : (items.length ? h('div', { class: 'all-rows' }, items.map(t => row(t, items)))
          : h('p', { class: 'muted small all-empty' }, p.inbox ? 'Nothing to sort. Tasks you create in meeting notes without a project land here.' : 'No tasks match.')));
    }).filter(Boolean));
    renderBulk();
  }

  // ---------- bulk: tick tasks, then move them to a project or to Trash ----------
  function pick(t, on, range) {
    const ids = range && lastPicked != null && range.some(x => x.id === lastPicked)
      ? (() => { const a = range.findIndex(x => x.id === lastPicked), b = range.findIndex(x => x.id === t.id); return range.slice(Math.min(a, b), Math.max(a, b) + 1).filter(selectable).map(x => x.id); })()
      : [t.id];
    ids.forEach(id => on ? picked.add(id) : picked.delete(id));
    lastPicked = t.id;
    const focused = document.activeElement?.closest('.all-row')?.dataset.id;
    render();
    // Keep the keyboard where it was after the redraw.
    if (focused) groups.querySelector(`.all-row[data-id="${focused}"] .all-title`)?.focus({ preventScroll: true });
  }
  function renderBulk() {
    const ts = [...picked].map(id => byId.get(id)).filter(Boolean);
    bulkBar.hidden = !ts.length;
    document.body.classList.toggle('has-bulk', ts.length > 0);
    if (!ts.length) { bulkBar.replaceChildren(); return; }
    const movable = ts.filter(canMoveTask), trashable = ts.filter(t => t.can.delete);
    const to = h('select', { class: 'bulk-move', disabled: !movable.length, 'aria-label': 'Move the ticked tasks to a project',
      onchange: e => { const id = +e.target.value; if (id) bulk('project', movable, id); } },
      h('option', { value: '' }, movable.length ? `Move ${movable.length === ts.length ? '' : movable.length + ' '}to project…` : 'Can’t move these'),
      boot.projects.map(p => h('option', { value: p.id }, p.inbox ? 'Inbox' : p.name)));
    bulkBar.replaceChildren(
      h('strong', { class: 'bulk-count' }, `${ts.length} selected`),
      to,
      trashable.length ? h('button', { type: 'button', class: 'btn btn-sm bulk-trash', onclick: () => {
        const subs = trashable.reduce((n, t) => n + boot.tasks.filter(x => x.parent_id === t.id && !picked.has(x.id)).length, 0);
        if (confirm(`Move ${trashable.length} task${trashable.length === 1 ? '' : 's'}${subs ? ` and ${subs} subtask${subs === 1 ? '' : 's'}` : ''} to Trash? You can restore them from Trash.`)) bulk('trash', trashable);
      } }, 'Move to Trash') : null,
      h('button', { type: 'button', class: 'btn btn-sm btn-ghost bulk-clear', title: 'Clear (Esc)', onclick: () => { picked.clear(); render(); } }, 'Clear'));
  }
  async function bulk(action, ts, projectId) {
    bulkBar.querySelectorAll('select, button').forEach(el => { el.disabled = true; });
    let d;
    try {
      d = await api('POST', `/api/o/${boot.org}/tasks/bulk`, { action, task_ids: ts.map(t => t.id), project_id: projectId });
    } catch { renderBulk(); return; }
    if (action === 'trash') {
      const gone = new Set(d.done);
      boot.tasks = boot.tasks.filter(t => !gone.has(t.id) && !gone.has(t.parent_id));
      byId.clear(); boot.tasks.forEach(t => byId.set(t.id, t));
    } else {
      d.tasks.forEach(x => { const t = byId.get(x.id); if (t) Object.assign(t, x); });
      if (d.statuses.length) boot.statuses[projectId] = d.statuses.map(s => ({ id: +s.id, name: s.name, color: s.color, type: s.type }));
    }
    picked.clear();
    render();
    const n = d.done.length, where = action === 'trash' ? 'Trash' : (projects.get(projectId)?.name || 'project');
    toast(`Moved ${n} task${n === 1 ? '' : 's'} to ${where}` + (d.skipped ? ` · ${d.skipped} skipped (no permission)` : ''), d.skipped && !n ? 'error' : 'info');
  }

  // ---------- board: one column per project ----------
  function renderBoard() {
    const list = boot.tasks.filter(visible);
    const cols = boot.projects.map(p => {
      const items = list.filter(t => t.project_id === p.id);
      return h('section', { class: 'column all-col' + (p.inbox ? ' is-inbox' : ''), style: `--sc:${p.inbox ? '#B7F34A' : '#94a3b8'}` },
        h('header', { class: 'column-head' },
          h('span', { class: 'status-pill' }, p.inbox ? 'Inbox' : p.name),
          h('span', { class: 'count' }, items.length)),
        h('div', { class: 'cards', dataset: { project: p.id } }, items.map(card)),
        p.inbox ? null : h('a', { class: 'add-btn all-open', href: boot.base.replace(/\/$/, '') + `/o/${boot.org}/p/${p.id}` }, 'Open board →'));
    });
    groups.replaceChildren(h('div', { class: 'board all-board' }, cols));
  }
  function card(t) {
    const st = statusOf(t);
    const done = st.type === 'done';
    const parent = t.parent_id ? byId.get(t.parent_id) : null;
    const el = h('article', { class: 'tcard' + (done ? ' is-done' : '') + (canMoveTask(t) ? ' can-move' : '') + (landed === t.id ? ' is-landed' : ''), tabindex: '0', dataset: { id: t.id },
      onclick: () => openPeek(t), onkeydown: e => { if (e.key === 'Enter') openPeek(t); } },
      parent ? h('div', { class: 'card-parent' }, parent.title) : null,
      h('div', { class: 'tcard-title' }, t.title),
      h('div', { class: 'card-meta' },
        h('span', { class: 'chip all-chip-stage', style: `--sc:${st.color}` }, st.name),
        t.assignees.length ? avatars(t.assignees) : null,
        t.due_date ? h('span', { class: 'chip' + (isOverdue(t.due_date, done) ? ' is-overdue' : '') }, fmtDate(t.due_date)) : null));
    if (canMoveTask(t)) el.addEventListener('pointerdown', e => dragStart(e, t, el));
    return el;
  }

  // Drag a card to another project's column (same feel as the project board: lift, slot, settle).
  let drag = null, landed = null;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  function dragStart(e, t, el) {
    if (e.button !== 0 || drag) return;
    const touch = e.pointerType !== 'mouse';
    const d = drag = { t, el, x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY, active: false, id: e.pointerId, tilt: 0, vx: 0 };
    if (touch) d.hold = setTimeout(() => lift(d), 220);
    const move = ev => {
      if (ev.pointerId !== d.id) return;
      d.vx = ev.clientX - d.x; d.x = ev.clientX; d.y = ev.clientY;
      if (!d.active) {
        const far = Math.hypot(d.x - d.x0, d.y - d.y0) > (touch ? 8 : 5);
        if (far && touch) return end(false);
        if (far) lift(d);
        return;
      }
      ev.preventDefault();
      follow(d);
    };
    const up = ev => { if (ev.pointerId === d.id) end(d.active); };
    const key = ev => { if (ev.key === 'Escape' && d.active) { ev.preventDefault(); end(false); } };
    const noScroll = ev => { if (d.active) ev.preventDefault(); };
    function end(commit) {
      clearTimeout(d.hold);
      removeEventListener('pointermove', move); removeEventListener('pointerup', up); removeEventListener('pointercancel', up);
      removeEventListener('keydown', key, true); removeEventListener('touchmove', noScroll);
      if (d.active) drop(d, commit); else drag = null;
    }
    addEventListener('pointermove', move, { passive: false }); addEventListener('pointerup', up); addEventListener('pointercancel', up);
    addEventListener('keydown', key, true); addEventListener('touchmove', noScroll, { passive: false });
  }
  function lift(d) {
    if (d.active || drag !== d) return;
    d.active = true;
    const r = d.el.getBoundingClientRect();
    d.dx = d.x - r.left; d.dy = d.y - r.top;
    d.from = d.el.parentElement;
    d.slot = h('div', { class: 'drop-slot', style: `height:${r.height}px` });
    d.ghost = d.el.cloneNode(true);
    d.ghost.classList.add('is-ghost');
    Object.assign(d.ghost.style, { width: r.width + 'px', left: '0px', top: '0px', transform: `translate(${r.left}px, ${r.top}px)` });
    document.body.append(d.ghost);
    d.el.replaceWith(d.slot);
    document.body.classList.add('is-dragging-card');
    requestAnimationFrame(() => d.ghost.classList.add('is-lifted'));
    follow(d);
  }
  function follow(d) {
    d.tilt += (Math.max(-7, Math.min(7, d.vx * 0.9)) - d.tilt) * 0.3;
    d.ghost.style.transform = `translate(${d.x - d.dx}px, ${d.y - d.dy}px) rotate(${reduce ? 0 : d.tilt.toFixed(2)}deg) scale(1.03)`;
    const cols = [...groups.querySelectorAll('.all-col')];
    const target = cols.find(c => { const r = c.getBoundingClientRect(); return d.x >= r.left && d.x <= r.right; }) || d.from.closest('.all-col');
    cols.forEach(c => c.classList.toggle('is-target', c === target && c !== d.from.closest('.all-col')));
    const cards = target.querySelector('.cards');
    if (d.slot.parentElement !== cards) cards.prepend(d.slot);
    const board = groups.querySelector('.all-board'), br = board.getBoundingClientRect();
    if (d.x > innerWidth - 70) board.scrollLeft += 12; else if (d.x < br.left + 70) board.scrollLeft -= 12;
  }
  function drop(d, commit) {
    const block = ev => { ev.stopPropagation(); ev.preventDefault(); };
    addEventListener('click', block, true);
    setTimeout(() => removeEventListener('click', block, true), 0);
    if (!commit) d.from.prepend(d.slot);
    const projectId = +d.slot.parentElement.dataset.project;
    const sr = d.slot.getBoundingClientRect();
    d.ghost.classList.remove('is-lifted');
    d.ghost.classList.add('is-settling');
    d.ghost.style.transform = `translate(${sr.left}px, ${sr.top}px)`;
    let ended = false;
    const done = () => {
      if (ended) return;
      ended = true;
      d.ghost.remove();
      d.slot.replaceWith(d.el);
      groups.querySelectorAll('.is-target').forEach(c => c.classList.remove('is-target'));
      document.body.classList.remove('is-dragging-card');
      drag = null;
      if (commit && projectId !== d.t.project_id) moveAndGlow(d.t, projectId);
    };
    d.ghost.addEventListener('transitionend', done, { once: true });
    setTimeout(done, reduce ? 0 : 320);
  }
  async function moveAndGlow(t, projectId) {
    landed = t.id;
    setTimeout(() => { if (landed === t.id) landed = null; }, 900);
    try { await move(t, projectId); } catch { render(); }
    groups.querySelector(`.tcard[data-id="${t.id}"]`)?.focus({ preventScroll: true });
  }

  // ---------- keyboard ----------
  function setView(v) {
    view = v;
    store.set(`lpm:${boot.org}:all:view`, v);
    render();
  }
  document.querySelectorAll('[data-all-view]').forEach(b => b.addEventListener('click', () => setView(b.dataset.allView)));
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && picked.size && !e.defaultPrevented && !document.querySelector('dialog[open], .palette, .peek')) { picked.clear(); render(); return; }
    if (e.metaKey || e.ctrlKey || e.altKey || e.defaultPrevented || drag || window.LPMKeys?.typing(e.target) || document.querySelector('dialog[open], .palette, .peek')) return;
    const k = e.key.toLowerCase();
    if ((k === 'b' || k === 'l') && !e.shiftKey) { e.preventDefault(); setView(k === 'b' ? 'board' : 'list'); return; }
    const card = e.target.closest?.('.all-board .tcard');
    if (!card || !e.key.startsWith('Arrow')) return;
    e.preventDefault();
    const cols = [...groups.querySelectorAll('.all-col')].map(c => [...c.querySelectorAll('.tcard')]);
    const ci = cols.findIndex(c => c.includes(card)), ri = cols[ci].indexOf(card);
    const t = byId.get(+card.dataset.id);
    if (e.shiftKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
      // Move the task to the previous/next project.
      const next = boot.projects[boot.projects.findIndex(p => p.id === t.project_id) + (e.key === 'ArrowLeft' ? -1 : 1)];
      if (next && canMoveTask(t)) moveAndGlow(t, next.id);
      return;
    }
    if (e.key === 'ArrowUp') cols[ci][ri - 1]?.focus();
    else if (e.key === 'ArrowDown') cols[ci][ri + 1]?.focus();
    else {
      const step = e.key === 'ArrowLeft' ? -1 : 1;
      for (let c = ci + step; c >= 0 && c < cols.length; c += step) if (cols[c].length) { cols[c][Math.min(ri, cols[c].length - 1)].focus(); break; }
    }
    document.activeElement?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  });
  document.addEventListener('focusin', e => {
    const card = e.target.closest?.('.all-board .tcard');
    if (card && card.matches(':focus-visible')) window.LPMKeys?.showHint([[['←', '→', '↑', '↓'], 'move'], [['↵'], 'open'], [['⇧', '←', '→'], 'move to project']]);
  });
  document.addEventListener('focusout', e => { if (e.target.closest?.('.all-board .tcard, .all-title')) window.LPMKeys?.hideHint(150); });
  document.addEventListener('focusin', e => {
    if (e.target.matches?.('.all-title:focus-visible')) window.LPMKeys?.showHint([[['X'], 'select'], [['⇧', 'click'], 'select a range'], [['↵'], 'open']]);
  });

  document.getElementById('a-search').addEventListener('input', e => { f.q = e.target.value.trim().toLowerCase(); render(); });
  document.getElementById('a-mine').addEventListener('change', e => { f.mine = e.target.checked; render(); });
  document.getElementById('a-hide-done').addEventListener('change', e => { f.hideDone = e.target.checked; render(); });
  document.getElementById('a-subtasks').addEventListener('change', e => { f.subtasks = e.target.checked; render(); });
  render();
  // Opened from the ⌘K palette on another page.
  const initial = +new URL(location.href).searchParams.get('task');
  if (initial && byId.has(initial)) openPeek(byId.get(initial));
})();
