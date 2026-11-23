// Notifications: grouped by day, filterable, and actionable in place. Clicking one opens the task in
// the quick view (peek.js) with the comment lit up and the cursor in the reply box.
(() => {
  'use strict';
  const { boot, h, svg, api, toast, avatar, peek, fmtWhen } = window.LPM;
  const list = document.getElementById('notif-list');
  const items = boot.items;
  const store = { get(k) { try { return localStorage.getItem(k); } catch { return null; } }, set(k, v) { try { localStorage.setItem(k, v); } catch { /* ignore */ } } };
  const FILTERS = ['all', 'unread', 'mention', 'assigned'];
  const started = Date.now();
  let filter = FILTERS.includes(store.get('lpm:notif:filter')) ? store.get('lpm:notif:filter') : 'all';

  const ICONS = {
    mention: '<path d="M13 10a3 3 0 11-6 0 3 3 0 016 0zm0 0v1.2a2 2 0 004 0V10a7 7 0 10-2.8 5.6"/>',
    comment: '<path d="M4 4h12v9H8l-4 3z"/>',
    assigned: '<circle cx="10" cy="7" r="3"/><path d="M4 17c1-3.5 3.5-5 6-5s5 1.5 6 5"/>',
    done: '<path d="M5.5 10.5l3 3 6-7"/>',
  };
  const name = id => (id && boot.members.find(m => m.id === id)?.name) || 'Someone';
  const matches = n => filter === 'all' || (filter === 'unread' ? !n.read : n.type === filter);

  function sentence(n) {
    const task = h('strong', { class: 'notif-task' }, n.parent ? h('span', { class: 'muted' }, n.parent + ' › ') : null, n.task);
    const who = h('strong', {}, name(n.actor));
    switch (n.type) {
      case 'mention': return [who, n.comment_id ? ' mentioned you in a comment on ' : ' mentioned you in the description of ', task];
      case 'comment': return [who, ' commented on ', task];
      case 'assigned': return [who, ' assigned you to ', task];
      case 'done': return [who, ' moved ', task, ` to ${n.excerpt || 'Done'}`];
      default: return [who, ' updated ', task];
    }
  }

  // "2 min ago" today, "Yesterday, 4:10 pm" after that.
  function when(at) {
    const mins = Math.round((Date.parse(boot.now.replace(' ', 'T')) - Date.parse(at.replace(' ', 'T'))) / 60000) + Math.round((Date.now() - started) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins} min ago`;
    if (at.slice(0, 10) === boot.today && mins < 720) return `${Math.round(mins / 60)} h ago`;
    return fmtWhen(at);
  }
  function group(at) {
    const days = Math.round((Date.parse(boot.today) - Date.parse(at.slice(0, 10))) / 86400000);
    return days <= 0 ? 'Today' : days === 1 ? 'Yesterday' : days < 7 ? 'Earlier this week' : 'Older';
  }

  function row(n) {
    const talk = n.type === 'mention' || n.type === 'comment';
    return h('article', { class: 'notif' + (n.read ? '' : ' is-unread'), tabindex: '0', dataset: { id: n.id }, onclick: e => { if (!e.target.closest('button')) openItem(n); },
      onkeydown: e => { if (e.key === 'Enter' && e.target === e.currentTarget) openItem(n); } },
      h('span', { class: 'notif-dot', 'aria-hidden': 'true' }),
      h('span', { class: 'notif-who' }, avatar(n.actor, 'md') || h('span', { class: 'avatar md' }, '?'),
        h('span', { class: `notif-type t-${n.type}`, title: { mention: 'Mention', comment: 'Comment', assigned: 'Assigned', done: 'Completed' }[n.type] }, svg(ICONS[n.type] || ICONS.comment))),
      h('div', { class: 'notif-main' },
        h('p', { class: 'notif-line' }, ...sentence(n)),
        talk && n.excerpt ? h('blockquote', { class: 'notif-quote' }, n.excerpt) : null,
        h('div', { class: 'notif-meta' },
          h('span', { class: 'notif-status', style: `--sc:${n.status.color}` }, n.status.name),
          h('span', {}, n.project), h('span', { 'aria-hidden': 'true' }, '·'), h('time', { title: n.at }, when(n.at)))),
      h('div', { class: 'notif-actions' },
        h('button', { type: 'button', class: 'btn btn-sm' + (talk ? ' btn-primary' : ''), onclick: () => openItem(n) }, talk ? 'Reply' : 'Open task'),
        h('button', { type: 'button', class: 'icon-btn notif-read-btn', title: n.read ? 'Mark as unread (U)' : 'Mark as read (U)', 'aria-label': n.read ? 'Mark as unread' : 'Mark as read', onclick: () => mark([n], !n.read) },
          svg(n.read ? '<circle cx="10" cy="10" r="4"/>' : '<path d="M5.5 10.5l3 3 6-7"/>'))));
  }

  function render() {
    document.querySelectorAll('[data-filter]').forEach(b => { const on = b.dataset.filter === filter; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', String(on)); });
    const unread = items.filter(n => !n.read);
    const counts = { unread: unread.length, mention: unread.filter(n => n.type === 'mention').length, assigned: unread.filter(n => n.type === 'assigned').length };
    document.querySelectorAll('[data-count]').forEach(c => { const v = counts[c.dataset.count]; c.textContent = v || ''; c.hidden = !v; });
    document.getElementById('n-all-read').disabled = !unread.length;
    window.LPMKeys?.setUnread?.(unread.length);

    const shown = items.filter(matches);
    if (!shown.length) { list.replaceChildren(empty()); return; }
    const out = [];
    let last = null;
    shown.forEach(n => {
      const g = group(n.at);
      if (g !== last) { out.push(h('h2', { class: 'notif-group' }, g)); last = g; }
      out.push(row(n));
    });
    list.replaceChildren(...out);
  }

  function empty() {
    const [title, text] = {
      all: ['No notifications yet', 'You’ll hear here when someone @mentions you, comments on a task you’re on, assigns you a task, or completes one of yours.'],
      unread: ['You’re all caught up', 'Nothing new since you last looked.'],
      mention: ['No mentions', 'When someone types @ and your name in a comment or a description, it shows up here.'],
      assigned: ['Nothing assigned to you', 'When someone else assigns you a task, it shows up here.'],
    }[filter];
    return h('div', { class: 'card empty-state notif-empty' },
      h('span', { class: 'notif-empty-icon' }, svg(filter === 'unread' ? ICONS.done : '<path d="M5.5 8.5a4.5 4.5 0 019 0c0 3.2 1.2 4.6 1.8 5.2.2.2.1.6-.2.6H3.9c-.3 0-.4-.4-.2-.6.6-.6 1.8-2 1.8-5.2z"/><path d="M8.2 16.5a2 2 0 003.6 0"/>')),
      h('h2', {}, title), h('p', { class: 'muted' }, text),
      filter !== 'all' ? h('button', { type: 'button', class: 'btn', onclick: () => setFilter('all') }, 'See all notifications') : null);
  }

  async function mark(ns, read, all = false) {
    const before = ns.map(n => n.read);
    ns.forEach(n => { n.read = read; });
    render();
    try {
      const d = await api('POST', `/api/o/${boot.org}/notifications/read`, all ? { all: true } : { ids: ns.map(n => n.id), read });
      window.LPMKeys?.setUnread?.(d.unread);
    } catch { ns.forEach((n, i) => { n.read = before[i]; }); render(); }
  }

  // Opening the task marks everything about it read (the server does the same when it loads).
  function openItem(n) {
    items.filter(x => x.task_id === n.task_id).forEach(x => { x.read = true; });
    render();
    focusRow(n.id);
    peek.open(n.task_id, { comment: n.comment_id, onChange: task => {
      const st = (task.statuses || []).find(s => s.id === task.status_id);
      items.filter(x => x.task_id === task.id).forEach(x => { x.task = task.title; if (st) x.status = { name: st.name, color: st.color, done: st.type === 'done' }; });
      render();
    } });
  }

  function setFilter(f) {
    filter = f;
    store.set('lpm:notif:filter', f);
    render();
  }
  document.querySelectorAll('[data-filter]').forEach(b => b.addEventListener('click', () => setFilter(b.dataset.filter)));
  document.getElementById('n-all-read').addEventListener('click', () => {
    const unread = items.filter(n => !n.read);
    if (unread.length) { mark(unread, true, true); toast(`Marked ${unread.length} as read`); }
  });

  // ---------- keyboard: ↑↓ (or J/K) to move, Enter to open, U read/unread, Shift+R all read ----------
  const rows = () => [...list.querySelectorAll('.notif')];
  function focusRow(id) { list.querySelector(`.notif[data-id="${id}"]`)?.focus({ preventScroll: true }); }
  document.addEventListener('keydown', e => {
    if (e.metaKey || e.ctrlKey || e.altKey || e.defaultPrevented || window.LPMKeys?.typing(e.target) || document.querySelector('dialog[open], .palette, .peek')) return;
    const k = e.key.toLowerCase();
    const all = rows(), cur = e.target.closest?.('.notif'), i = all.indexOf(cur);
    if (k === 'arrowdown' || k === 'j' || k === 'arrowup' || k === 'k') {
      e.preventDefault();
      const next = all[i < 0 ? 0 : Math.max(0, Math.min(all.length - 1, i + (k === 'arrowdown' || k === 'j' ? 1 : -1)))];
      next?.focus(); next?.scrollIntoView({ block: 'nearest' });
    } else if (k === 'u' && cur) {
      e.preventDefault();
      const n = items.find(x => x.id === +cur.dataset.id);
      mark([n], !n.read).then(() => focusRow(n.id));
    } else if (k === 'r' && e.shiftKey) {
      e.preventDefault();
      document.getElementById('n-all-read').click();
    } else if (['1', '2', '3', '4'].includes(k)) {
      e.preventDefault();
      setFilter(FILTERS[+k - 1]);
    }
  });
  document.addEventListener('focusin', e => {
    if (e.target.matches?.('.notif:focus-visible')) window.LPMKeys?.showHint([[['↑', '↓'], 'move'], [['↵'], 'open & reply'], [['U'], 'read / unread'], [['1', '4'], 'tabs']]);
  });
  document.addEventListener('focusout', e => { if (e.target.closest?.('.notif')) window.LPMKeys?.hideHint(150); });

  render();
})();
