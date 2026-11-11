// Keyboard layer for every signed-in page: the ⌘K command palette (search tasks, jump to any
// page), the "?" shortcuts sheet, "G then …" jumps, and a small floating hint pill other scripts
// can use to show which keys work right now. Plain JS, no build step.
(() => {
  'use strict';

  const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  const MOD = isMac ? '⌘' : 'Ctrl';
  const meta = n => document.querySelector(`meta[name="${n}"]`)?.content || '';
  const base = meta('lpm-base').replace(/\/$/, '');
  const org = meta('lpm-org');
  const isOwner = meta('lpm-owner') === '1';
  const typing = el => !!el?.closest?.('input, textarea, select, [contenteditable=""], [contenteditable=true]');

  function h(tag, attrs = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
    return el;
  }
  function searchIcon() {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 20 20');
    s.setAttribute('class', 'i');
    s.innerHTML = '<circle cx="9" cy="9" r="5.5"/><path d="M13.2 13.2L17 17"/>';
    return s;
  }
  const kbd = (...keys) => keys.map(k => h('kbd', {}, k === 'mod' ? MOD : k));

  // Show ⌘ on a Mac and Ctrl elsewhere, wherever a page prints a shortcut.
  document.querySelectorAll('[data-kbd="mod+k"]').forEach(el => { el.textContent = `${MOD}${isMac ? '' : ' '}K`; });
  document.querySelectorAll('[data-tip*="⌘K"]').forEach(el => { el.dataset.tip = el.dataset.tip.replace('⌘K', `${MOD}${isMac ? '' : '+'}K`); });

  // ---------- floating hint pill ----------
  let hintEl = null, hintTimer = 0;
  function showHint(parts) {
    clearTimeout(hintTimer);
    if (!hintEl) { hintEl = h('div', { class: 'key-hint', role: 'status' }); document.body.append(hintEl); }
    hintEl.replaceChildren(...parts.map(p => (Array.isArray(p) ? h('span', { class: 'key-hint-item' }, ...kbd(...p[0]), ' ', p[1]) : p)));
    requestAnimationFrame(() => hintEl.classList.add('is-on'));
  }
  function hideHint(delay = 0) {
    clearTimeout(hintTimer);
    hintTimer = setTimeout(() => hintEl?.classList.remove('is-on'), delay);
  }

  // ---------- pages you can jump to ----------
  function places() {
    const out = [];
    document.querySelectorAll('.sidebar a.side-link').forEach(a => {
      const label = a.querySelector('.label')?.textContent.trim() || a.getAttribute('aria-label');
      if (!label) return;
      const isProject = !!a.querySelector('.proj-badge');
      out.push({ kind: 'place', label, hint: isProject ? 'Project' : 'Go to', href: a.href, badge: isProject ? a.querySelector('.proj-badge').cloneNode(true) : null });
    });
    document.querySelectorAll('.org-switch .menu a').forEach(a => {
      if (a.classList.contains('is-active') || a.classList.contains('muted')) return;
      out.push({ kind: 'place', label: `Switch to ${a.textContent.trim()}`, hint: 'Organization', href: a.href });
    });
    out.push({ kind: 'place', label: 'Your account', hint: 'Go to', href: base + '/account' });
    if (isOwner && !out.some(p => p.href.endsWith('/admin'))) out.push({ kind: 'place', label: 'Manage LeanPM', hint: 'Go to', href: base + '/admin' });
    return out;
  }
  function actions() {
    const out = [];
    if (document.querySelector('.board-page')) out.push({ kind: 'action', label: 'New task', hint: 'N', run: () => dispatchEvent(new CustomEvent('lpm:new-task')) });
    out.push({ kind: 'action', label: 'Keyboard shortcuts', hint: '?', run: openHelp });
    out.push({ kind: 'action', label: 'Collapse or expand the sidebar', hint: '[', run: () => document.getElementById('side-toggle')?.click() });
    return out;
  }

  // ---------- open a task from anywhere ----------
  // The board (or a page with the quick view) handles it in place; otherwise go to the task's board.
  function openTask(t) {
    const ev = new CustomEvent('lpm:open-task', { detail: { id: t.id, projectId: t.project_id }, cancelable: true });
    if (!dispatchEvent(ev)) return;
    if (window.LPM?.peek) { window.LPM.peek.open(t.id); return; }
    location.href = t.inbox ? `${base}/o/${org}/tasks?task=${t.id}` : `${base}/o/${org}/p/${t.project_id}?task=${t.id}`;
  }

  // ---------- the palette ----------
  let pal = null;
  const ps = { items: [], index: 0, tasks: [], q: '', ctrl: null, timer: 0 };

  function openPalette() {
    if (pal) { pal.input.select(); return; }
    hideHint();
    const input = h('input', { type: 'text', class: 'palette-input', placeholder: org ? 'Search tasks, or jump to a page…' : 'Jump to a page…', 'aria-label': 'Search', autocomplete: 'off', spellcheck: 'false' });
    const list = h('div', { class: 'palette-list', role: 'listbox' });
    const box = h('div', { class: 'palette', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Search' },
      h('div', { class: 'palette-search' },
        searchIcon(),
        input,
        h('kbd', { class: 'palette-esc' }, 'esc')),
      list,
      h('div', { class: 'palette-foot' },
        h('span', {}, ...kbd('↑', '↓'), ' move'),
        h('span', {}, ...kbd('↵'), ' open'),
        h('span', {}, ...kbd('esc'), ' close'),
        h('span', { class: 'palette-foot-right' }, ...kbd('?'), ' all shortcuts')));
    const back = h('div', { class: 'palette-backdrop', onmousedown: e => { if (e.target === back) closePalette(); } }, box);
    document.body.append(back);
    pal = { back, box, input, list, restore: document.activeElement };
    input.addEventListener('input', () => { ps.q = input.value.trim(); ps.index = 0; draw(); search(); });
    input.addEventListener('keydown', paletteKey);
    ps.q = ''; ps.index = 0; ps.tasks = [];
    draw();
    search();
    input.focus();
  }
  function closePalette(restoreFocus = true) {
    if (!pal) return;
    ps.ctrl?.abort();
    pal.back.remove();
    if (restoreFocus && pal.restore?.isConnected) pal.restore.focus?.({ preventScroll: true });
    pal = null;
  }

  function search() {
    if (!org) return;
    clearTimeout(ps.timer);
    ps.timer = setTimeout(async () => {
      ps.ctrl?.abort();
      ps.ctrl = new AbortController();
      const q = ps.q;
      try {
        const r = await fetch(`${base}/api/o/${org}/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: ps.ctrl.signal, credentials: 'same-origin' });
        if (!r.ok) return;
        const d = await r.json();
        if (!pal || q !== ps.q) return;
        ps.tasks = d.tasks;
        ps.loaded = true;
        draw();
      } catch { /* aborted or offline: keep what's shown */ }
    }, ps.q ? 110 : 0);
  }

  function matches(label, q) { return label.toLowerCase().includes(q.toLowerCase()); }

  function draw() {
    if (!pal) return;
    const q = ps.q;
    const groups = [];
    const tasks = ps.tasks.map(t => ({ kind: 'task', t }));
    if (tasks.length) groups.push([q ? 'Tasks' : 'Recently updated', tasks]);
    const go = places().filter(p => !q || matches(p.label, q)).slice(0, q ? 6 : 8);
    if (go.length) groups.push(['Go to', go]);
    const act = actions().filter(a => !q || matches(a.label, q));
    if (act.length) groups.push(['Actions', act]);
    ps.items = groups.flatMap(g => g[1]);
    if (ps.index >= ps.items.length) ps.index = Math.max(0, ps.items.length - 1);
    let n = 0;
    pal.list.replaceChildren(...groups.map(([title, items]) => h('div', { class: 'palette-group' },
      h('div', { class: 'palette-label' }, title),
      ...items.map(item => row(item, n++)))));
    if (!ps.items.length) pal.list.append(h('p', { class: 'palette-empty' }, q ? `No tasks or pages match “${q}”.` : 'Type to search.'));
    pal.list.querySelector('.is-on')?.scrollIntoView({ block: 'nearest' });
  }

  function row(item, i) {
    const on = i === ps.index;
    const attrs = { class: 'palette-item' + (on ? ' is-on' : ''), role: 'option', 'aria-selected': on ? 'true' : 'false', onmousemove: () => { if (ps.index !== i) { ps.index = i; draw(); } }, onclick: () => choose(item) };
    if (item.kind === 'task') {
      const t = item.t;
      return h('div', attrs,
        h('span', { class: 'pi-dot', style: `--sc:${t.color}` }),
        h('span', { class: 'pi-main' },
          h('span', { class: 'pi-title' + (t.done ? ' is-done' : '') }, t.title),
          h('span', { class: 'pi-sub' }, `${t.project} · ${t.status}${t.subtask ? ' · subtask' : ''}`)),
        h('span', { class: 'pi-id' }, `#${t.id}`),
        on ? h('span', { class: 'pi-enter' }, ...kbd('↵')) : null);
    }
    return h('div', attrs,
      item.badge || h('span', { class: 'pi-icon' }, item.kind === 'action' ? '⌁' : '→'),
      h('span', { class: 'pi-main' }, h('span', { class: 'pi-title' }, item.label)),
      item.hint ? h('span', { class: 'pi-hint' }, item.kind === 'action' && item.hint.length <= 2 ? kbd(item.hint) : item.hint) : null);
  }

  function choose(item) {
    if (!item) return;
    closePalette(item.kind === 'action');
    if (item.kind === 'task') openTask(item.t);
    else if (item.kind === 'place') location.href = item.href;
    else item.run();
  }

  function paletteKey(e) {
    if (e.key === 'ArrowDown' || (e.key === 'n' && e.ctrlKey)) { e.preventDefault(); ps.index = (ps.index + 1) % Math.max(1, ps.items.length); draw(); }
    else if (e.key === 'ArrowUp' || (e.key === 'p' && e.ctrlKey)) { e.preventDefault(); ps.index = (ps.index - 1 + ps.items.length) % Math.max(1, ps.items.length); draw(); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(ps.items[ps.index]); }
    else if (e.key === 'Escape') { e.preventDefault(); closePalette(); }
    else if (e.key === 'Tab') e.preventDefault();
  }

  // ---------- shortcuts sheet ----------
  const SHEET = [
    ['Anywhere', [
      [['mod', 'K'], 'Search tasks and jump to any page'],
      [['/'], 'Search (when you aren’t typing)'],
      [['G', 'A'], 'Go to All tasks'],
      [['G', 'N'], 'Go to Meeting notes'],
      [['G', 'M'], 'Go to Members'],
      [['['], 'Collapse or expand the sidebar'],
      [['?'], 'Show these shortcuts'],
    ]],
    ['On a board', [
      [['N'], 'New task in the first stage'],
      [['B'], 'Board view'], [['L'], 'List view'], [['F'], 'Files'],
      [['←', '→', '↑', '↓'], 'Move between cards'],
      [['↵'], 'Open the card'],
      [['⇧', '←'], 'Move the card to the previous stage'],
      [['⇧', '→'], 'Move the card to the next stage'],
      [['S'], 'Show or hide the card’s subtasks'],
    ]],
    ['All tasks', [
      [['X'], 'Tick the focused task for bulk actions'],
      [['esc'], 'Clear the ticked tasks'],
    ]],
    ['In a task', [
      [['mod', '↵'], 'Send the comment (the cursor starts in the comment box)'],
      [['esc'], 'Leave the comment box, then close the task'],
    ]],
  ];
  function openHelp() {
    closePalette(false);
    let dlg = document.getElementById('shortcuts');
    if (!dlg) {
      dlg = h('dialog', { id: 'shortcuts', class: 'dialog dialog-wide shortcuts' },
        h('div', { class: 'row-between' }, h('h2', {}, 'Keyboard shortcuts'), h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Close', onclick: () => dlg.close() }, '✕')),
        ...SHEET.map(([title, rows]) => h('section', { class: 'sc-group' },
          h('h3', {}, title),
          ...rows.map(([keys, label]) => h('div', { class: 'sc-row' }, h('span', {}, label), h('span', { class: 'sc-keys' }, ...kbd(...keys)))))));
      document.body.append(dlg);
    }
    dlg.showModal();
  }

  // ---------- global keys ----------
  let gPending = 0;
  document.addEventListener('keydown', e => {
    if ((e.metaKey || e.ctrlKey) && !e.altKey && e.key.toLowerCase() === 'k') { e.preventDefault(); pal ? closePalette() : openPalette(); return; }
    if (pal || e.metaKey || e.ctrlKey || e.altKey || e.defaultPrevented || typing(e.target) || document.querySelector('dialog[open]')) return;
    if (gPending) {
      clearTimeout(gPending); gPending = 0; hideHint();
      const to = { a: '/tasks', n: '/notes', m: '/members' }[e.key.toLowerCase()];
      if (to && org) { e.preventDefault(); location.href = `${base}/o/${org}${to}`; }
      return;
    }
    if (e.key === '/') { e.preventDefault(); openPalette(); }
    else if (e.key === '?') { e.preventDefault(); openHelp(); }
    else if (e.key.toLowerCase() === 'g' && org) {
      showHint([h('span', { class: 'key-hint-item' }, 'Go to'), [['A'], 'All tasks'], [['N'], 'Meeting notes'], [['M'], 'Members']]);
      gPending = setTimeout(() => { gPending = 0; hideHint(); }, 1500);
    }
  });
  document.addEventListener('click', e => { if (e.target.closest('[data-palette]')) { e.preventDefault(); openPalette(); } });

  window.LPMKeys = { mod: MOD, isMac, showHint, hideHint, openPalette, openHelp, typing };
})();
