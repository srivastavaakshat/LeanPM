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
    clip: '<path d="M14.5 9.5l-5 5a3 3 0 01-4.2-4.2l6-6a2 2 0 012.8 2.8l-6 6a1 1 0 01-1.4-1.4l5-5"/>',
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
  // A full task editor in a side panel: every section can be switched off per meeting doc
  // (opts.sections); Stage and Project always show. The server re-checks every change.
  const ALL_SECTIONS = ['assignees', 'dates', 'priority', 'fields', 'description', 'subtasks', 'files', 'updates'];
  const PRIORITY_COLORS = { urgent: '#ef4444', high: '#f59e0b', normal: '#3b82f6', low: '#9ca3af' };
  let panel = null;
  let backdrop = null;
  let current = null;
  let onChange = null;
  let sections = ALL_SECTIONS;
  let descTimer = null;
  let descPending = null;

  function flushDescription() {
    clearTimeout(descTimer);
    if (!descPending) return;
    const { id, html } = descPending;
    descPending = null;
    api('POST', `/api/tasks/${id}`, { description: html }).then(d => { if (current && current.id === id) { current.description = d.task.description; onChange?.(d.task); } }).catch(() => {});
  }

  function close() {
    flushDescription();
    closePicker();
    panel?.remove(); backdrop?.remove();
    panel = backdrop = null; current = null;
  }
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape' || !panel) return;
    if (picker) { closePicker(); return; }
    if (!e.target.closest('input, textarea, select, [contenteditable=true]')) close();
  });

  async function open(taskId, opts = {}) {
    if (opts.onChange !== undefined) onChange = opts.onChange || null;
    if (opts.sections) sections = opts.sections;
    flushDescription();
    if (!panel) {
      backdrop = h('div', { class: 'peek-backdrop', onclick: close });
      panel = h('aside', { class: 'peek', 'aria-label': 'Task' });
      document.body.append(backdrop, panel);
    }
    if (!current || current.id !== taskId) panel.replaceChildren(h('div', { class: 'peek-loading' }, 'Loading…'));
    try {
      const d = await api('GET', `/api/tasks/${taskId}`);
      current = d.task;
      draw(true);
      // The cursor waits in the update box, so you can type straight away.
      panel?.querySelector('.peek-comment textarea')?.focus({ preventScroll: true });
    } catch {
      close();
    }
  }

  /** Save fields on the task, then redraw (keeps the scroll position). */
  async function save(fields) {
    try {
      const d = await api('POST', `/api/tasks/${current.id}`, fields);
      current = d.task; draw(); onChange?.(current);
    } catch { draw(); }
  }
  async function reload() {
    const d = await api('GET', `/api/tasks/${current.id}`);
    current = d.task; draw(); onChange?.(current);
  }

  // ----- small pickers -----
  let picker = null;
  function closePicker() { picker?.remove(); picker = null; }
  document.addEventListener('mousedown', e => { if (picker && !picker.contains(e.target) && !e.target.closest('.peek-assign')) closePicker(); });
  function peoplePicker(anchor, selected, onDone) {
    closePicker();
    let chosen = [...selected];
    const list = h('div', { class: 'picker-list' });
    const search = h('input', { type: 'search', placeholder: 'Search people', class: 'picker-search' });
    const people = boot.members.filter(m => m.active !== false || chosen.includes(m.id));
    const drawList = () => {
      const q = search.value.toLowerCase();
      list.replaceChildren(...people.filter(m => m.name.toLowerCase().includes(q)).map(m => {
        const on = chosen.includes(m.id);
        return h('button', { type: 'button', class: 'picker-item' + (on ? ' is-on' : ''), onclick: () => { chosen = on ? chosen.filter(x => x !== m.id) : [...chosen, m.id]; drawList(); } },
          h('span', { class: 'avatar sm', style: `--c:${m.color}` }, m.initials), h('span', { class: 'grow' }, m.name), on ? '✓' : '');
      }));
    };
    search.addEventListener('input', drawList);
    picker = h('div', { class: 'popover' }, search, list,
      h('div', { class: 'actions picker-actions' }, h('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: () => { closePicker(); onDone(chosen); } }, 'Done')));
    document.body.append(picker);
    const r = anchor.getBoundingClientRect();
    picker.style.top = Math.min(r.bottom + 6, innerHeight - 340) + scrollY + 'px';
    picker.style.left = Math.min(r.left, innerWidth - 270) + scrollX + 'px';
    drawList();
    search.focus();
  }

  // ----- drawing -----
  const show = key => sections.includes(key);
  const row = (label, control) => h('div', { class: 'field-row' }, h('div', { class: 'field-label' }, label), h('div', { class: 'field-value' }, control));
  const section = (title, ...kids) => h('section', { class: 'peek-section' }, h('h3', { class: 'peek-sub' }, title), ...kids);

  function draw(fresh = false) {
    const t = current;
    if (!t || !panel) return;
    const body = panel.querySelector('.peek-body');
    const scroll = fresh ? 0 : (body?.scrollTop || 0);
    const draft = panel.querySelector('.peek-comment textarea')?.value || '';
    const typingUpdate = !!document.activeElement?.matches?.('.peek-comment textarea');
    const st = t.statuses.find(s => s.id === t.status_id) || {};
    const done = st.type === 'done';

    // Title
    const title = h('textarea', { class: 'peek-title', rows: 1, maxlength: 255, readonly: !t.can.edit }, t.title);
    const fit = () => { title.style.height = 'auto'; title.style.height = title.scrollHeight + 'px'; };
    requestAnimationFrame(fit);
    title.addEventListener('input', fit);
    title.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); title.blur(); } });
    title.addEventListener('blur', () => { const v = title.value.trim(); if (v && v !== t.title) save({ title: v }); else title.value = t.title; });

    // Details
    const statusSel = h('select', { class: 'status-select big', style: `--sc:${st.color}`, disabled: !t.can.move, onchange: e => save({ status_id: +e.target.value }) },
      t.statuses.map(s => h('option', { value: s.id, selected: s.id === t.status_id }, s.name)));
    const assignBtn = h('button', { type: 'button', class: 'assignee-btn peek-assign', disabled: !t.can.assign },
      t.assignees.length ? h('span', { class: 'row-center gap-s wrap' }, t.assignees.map(id => h('span', { class: 'person' }, avatar(id), members.get(id)?.name)))
        : h('span', { class: 'muted' }, t.can.assign ? '+ Add people' : 'Nobody yet'));
    assignBtn.addEventListener('click', () => peoplePicker(assignBtn, t.assignees, ids => {
      if (ids.slice().sort().join() !== t.assignees.slice().sort().join()) save({ assignees: ids });
    }));
    const dateIn = field => h('input', { type: 'date', class: 'cf-input peek-date' + (field === 'due_date' && isOverdue(t.due_date, done) ? ' is-overdue' : ''), value: t[field] || '', disabled: !t.can.fields, onchange: e => save({ [field]: e.target.value || null }) });
    const prio = h('select', { class: 'cf-input prio-select' + (t.priority ? '' : ' is-empty'), style: t.priority ? `--pc:${PRIORITY_COLORS[t.priority]}` : null, disabled: !t.can.fields, onchange: e => save({ priority: e.target.value || null }) },
      h('option', { value: '' }, 'Empty'), Object.entries(boot.priorities || {}).map(([k, l]) => h('option', { value: k, selected: k === t.priority }, l)));
    const projects = boot.projects || [];
    const projectSel = h('select', {
      class: 'cf-input peek-project' + (t.project.inbox ? ' is-inbox' : ''), disabled: !t.can.edit || !projects.length || !!t.parent,
      title: t.parent ? 'Subtasks move with their parent task' : '',
      onchange: async e => {
        try {
          const d = await api('POST', `/api/tasks/${t.id}/project`, { project_id: +e.target.value });
          current = d.task; draw(); onChange?.(current);
          toast(`Moved to ${current.project.inbox ? 'the Inbox' : current.project.name}`);
        } catch { draw(); }
      },
    }, (projects.some(p => p.id === t.project.id) ? projects : [t.project, ...projects]).map(p => h('option', { value: p.id, selected: p.id === t.project.id }, p.inbox ? 'Inbox (not in a project)' : p.name)));
    const creator = members.get(t.created_by);

    const details = h('div', { class: 'fields' },
      row('Stage', statusSel),
      show('assignees') ? row('Assignees', assignBtn) : null,
      show('dates') ? row('Dates', h('div', { class: 'row-center gap-s' }, dateIn('start_date'), h('span', { class: 'muted' }, '→'), dateIn('due_date'))) : null,
      show('priority') ? row('Priority', prio) : null,
      row('Project', projectSel),
      t.project.inbox && !t.parent ? h('p', { class: 'hint peek-hint' }, 'Not in a project yet. Pick one to put it on that board.') : null,
      show('fields') ? t.field_defs.map(f => row(f.name, fieldControl(t, f))) : null,
      row('Created', h('span', { class: 'muted small' }, fmtWhen(t.created_at), creator ? ` by ${creator.name}` : '')),
      t.completed_at ? row('Completed', h('span', { class: 'muted small' }, fmtWhen(t.completed_at))) : null);

    panel.replaceChildren(
      h('header', { class: 'peek-head' },
        h('span', { class: 'peek-crumbs muted small' }, svg(ICON.folder), t.project.inbox ? 'Inbox' : t.project.name,
          t.parent ? h('span', {}, ' › ', h('a', { href: '#', onclick: e => { e.preventDefault(); open(t.parent.id); } }, t.parent.title)) : null),
        h('span', { class: 'row-center gap-s' },
          h('a', { class: 'btn btn-sm', href: t.url, title: 'Open the full task on its board' }, svg(ICON.out), 'Open task'),
          h('button', { type: 'button', class: 'icon-btn', title: 'Close (Esc)', onclick: close }, svg(ICON.x)))),
      h('div', { class: 'peek-body' },
        h('div', { class: 'task-type' }, h('span', { class: 'status-dot', style: `--sc:${st.color}` }), t.parent ? 'Subtask' : 'Task', h('span', { class: 'muted' }, ` #${t.id}`)),
        title, details,
        show('description') ? descriptionSection(t) : null,
        show('subtasks') && (!t.parent || t.subtask_list.length) ? subtasksSection(t) : null,
        show('files') ? filesSection(t) : null,
        show('updates') ? updatesSection(t, draft) : null));
    if (typingUpdate) panel.querySelector('.peek-comment textarea')?.focus({ preventScroll: true });
    const nb = panel.querySelector('.peek-body');
    if (nb) nb.scrollTop = scroll;
  }

  function fieldControl(t, f) {
    const v = (t.fields || {})[f.id] ?? '';
    const off = !t.can.fields;
    const put = value => { if (value !== v) save({ fields: { [f.id]: value } }); };
    if (f.type === 'select') {
      return h('select', { class: 'cf-input' + (v ? '' : ' is-empty'), disabled: off, onchange: e => put(e.target.value) },
        h('option', { value: '' }, 'Empty'), f.options.map(o => h('option', { value: o, selected: o === v }, o)));
    }
    if (f.type === 'checkbox') return h('input', { type: 'checkbox', class: 'cf-check', checked: v === '1', disabled: off, onchange: e => put(e.target.checked ? '1' : '') });
    const input = h('input', {
      type: { url: 'url', number: 'number', date: 'date' }[f.type] || 'text', class: 'cf-input' + (v ? '' : ' is-empty'), value: v, disabled: off,
      placeholder: off ? 'Empty' : f.type === 'url' ? 'Paste a link' : 'Empty', step: f.type === 'number' ? 'any' : null,
      onchange: e => put(e.target.value.trim()),
      onkeydown: e => { if (e.key === 'Enter') e.target.blur(); },
    });
    if (f.type !== 'url' || !v) return input;
    let label = 'Open';
    try { const host = new URL(v).hostname.replace(/^www\./, ''); label = host.endsWith('figma.com') ? 'Open Figma' : 'Open'; } catch { /* keep */ }
    return h('div', { class: 'row-center gap-s cf-url' }, input, h('a', { class: 'btn btn-sm', href: v, target: '_blank', rel: 'noopener noreferrer' }, svg(ICON.out), label));
  }

  function descriptionSection(t) {
    const desc = h('div', {
      class: 'description' + (t.description ? '' : ' is-empty'), contenteditable: t.can.edit ? 'true' : 'false',
      'data-placeholder': t.can.edit ? 'Add a description: goals, links, references…' : 'No description.', html: t.description || '',
    });
    desc.querySelectorAll('.mention, .task-ref').forEach(m => { m.contentEditable = 'false'; });
    desc.addEventListener('input', () => {
      desc.classList.toggle('is-empty', !desc.textContent.trim() && !desc.querySelector('li'));
      descPending = { id: t.id, html: desc.innerHTML };
      clearTimeout(descTimer);
      descTimer = setTimeout(flushDescription, 1000);
    });
    desc.addEventListener('blur', flushDescription);
    desc.addEventListener('paste', e => {
      e.preventDefault();
      const text = e.clipboardData.getData('text/plain');
      if (/^https?:\/\/\S+$/.test(text.trim())) document.execCommand('createLink', false, text.trim()) || document.execCommand('insertText', false, text);
      else document.execCommand('insertText', false, text);
    });
    desc.addEventListener('click', e => { const a = e.target.closest('a[href]'); if (a && (e.metaKey || e.ctrlKey || desc.contentEditable !== 'true')) { e.preventDefault(); window.open(a.href, '_blank', 'noopener'); } });
    const tool = (label, cmd, arg, tip) => h('button', { type: 'button', title: tip, onmousedown: e => { e.preventDefault(); document.execCommand(cmd, false, arg); desc.dispatchEvent(new Event('input')); } }, label);
    return section('Description',
      t.can.edit ? h('div', { class: 'rt-toolbar' }, tool(h('b', {}, 'B'), 'bold', null, 'Bold'), tool(h('i', {}, 'I'), 'italic', null, 'Italic'),
        tool('H', 'formatBlock', 'h3', 'Heading'), tool('• List', 'insertUnorderedList', null, 'Bulleted list'), tool('1. List', 'insertOrderedList', null, 'Numbered list'),
        h('button', { type: 'button', title: 'Link', onmousedown: e => {
          e.preventDefault();
          const u = prompt('Link (https://…)');
          if (u) { document.execCommand('createLink', false, /^https?:\/\//i.test(u) ? u : 'https://' + u); desc.dispatchEvent(new Event('input')); }
        } }, 'Link')) : null,
      desc);
  }

  function subtasksSection(t) {
    const list = h('div', { class: 'subtasks' }, t.subtask_list.map(sub => {
      const ss = t.statuses.find(x => x.id === sub.status_id) || {};
      return h('div', { class: 'sub-row' + (ss.type === 'done' ? ' is-done' : '') },
        h('span', { class: 'dot', style: `--sc:${ss.color}`, title: ss.name }),
        h('button', { type: 'button', class: 'sub-title', onclick: () => open(sub.id) }, sub.title),
        sub.due_date ? h('span', { class: 'chip' + (isOverdue(sub.due_date, ss.type === 'done') ? ' is-overdue' : '') }, fmtDate(sub.due_date)) : null,
        avatars(sub.assignees),
        t.can.edit ? h('button', { type: 'button', class: 'icon-btn small', title: 'Detach (make it a normal task)', onclick: async () => { await api('POST', `/api/tasks/${sub.id}/detach`); reload(); } }, svg(ICON.x)) : null);
    }));
    let adder = null;
    if (!t.parent && t.can.create) {
      const input = h('input', { type: 'text', class: 'add-input', placeholder: '+ Add a subtask, then press Enter', maxlength: 255 });
      input.addEventListener('keydown', async e => {
        if (e.key !== 'Enter' || !input.value.trim()) return;
        e.preventDefault();
        const title = input.value.trim();
        input.value = '';
        await api('POST', `/api/projects/${t.project.id}/tasks`, { title, parent_id: t.id });
        await reload();
        panel?.querySelector('.peek-section .add-input')?.focus();
      });
      adder = input;
    }
    return section(h('span', {}, 'Subtasks', t.subtasks ? h('span', { class: 'muted' }, ` ${t.subtasks_done}/${t.subtasks} done`) : null),
      t.subtasks ? h('div', { class: 'progress' }, h('span', { style: `width:${Math.round(t.subtasks_done / t.subtasks * 100)}%` })) : null,
      list, adder);
  }

  function fileTile(f) {
    const ext = (f.name.split('.').pop() || 'file').slice(0, 4).toUpperCase();
    return h('div', { class: 'file' },
      h('a', { href: f.url, target: '_blank', rel: 'noopener', class: 'file-link' },
        f.image ? h('img', { src: f.url, alt: '', loading: 'lazy' }) : h('span', { class: 'file-ext' }, ext),
        h('span', { class: 'file-name', title: f.name }, f.name), h('span', { class: 'muted small' }, f.size)),
      f.can_delete ? h('button', { type: 'button', class: 'icon-btn small file-del', title: 'Delete file', onclick: async () => {
        if (!confirm(`Delete ${f.name}?`)) return;
        const d = await api('POST', `/api/files/${f.id}/delete`); current = d.task; draw(); onChange?.(current);
      } }, svg(ICON.x)) : null);
  }

  async function upload(t, fileList, path = `/api/tasks/${t.id}/files`, extra = {}) {
    const fd = new FormData();
    Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
    for (const f of fileList) {
      if (f.size > (boot.maxUpload || 25) * 1048576) { toast(`${f.name} is over ${boot.maxUpload || 25} MB.`, 'error'); continue; }
      fd.append('files[]', f);
    }
    if (!fd.has('files[]') && !extra.body) return null;
    const res = await fetch(boot.base.replace(/\/$/, '') + path, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': boot.csrf, Accept: 'application/json' } });
    const d = await res.json().catch(() => ({}));
    if (!res.ok) { toast(d.error || 'That didn’t upload.', 'error'); return null; }
    (d.errors || []).forEach(e => toast(e, 'error'));
    current = d.task; draw(); onChange?.(current);
    return d;
  }

  function filesSection(t) {
    const input = h('input', { type: 'file', multiple: true, hidden: true, onchange: e => { toast('Uploading…'); upload(t, e.target.files); } });
    const zone = h('div', { class: 'peek-drop' },
      t.attachments.length ? h('div', { class: 'files' }, t.attachments.map(fileTile)) : null,
      t.can.upload ? h('button', { type: 'button', class: 'peek-drop-btn', onclick: () => input.click() }, svg(ICON.clip),
        t.attachments.length ? 'Add files' : `Drop files here or click to upload (up to ${boot.maxUpload || 25} MB each)`) : (t.attachments.length ? null : h('p', { class: 'muted small' }, 'No files.')),
      input);
    const sec = section('Files', zone);
    if (t.can.upload) window.LPMDrop?.(sec, 'Drop to add to Files', list => { toast('Uploading…'); upload(t, list); });
    return sec;
  }

  function updatesSection(t, draft) {
    const feed = h('div', { class: 'peek-feed' });
    t.feed.forEach(item => feed.append(item.type === 'comment'
      ? h('div', { class: 'comment' },
          h('div', { class: 'comment-head' }, avatar(item.user_id), h('strong', {}, members.get(item.user_id)?.name || 'Someone'), h('time', {}, fmtWhen(item.at)), item.edited ? h('span', { class: 'muted small' }, '(edited)') : null),
          h('div', { class: 'comment-body', html: item.html }),
          item.files.length ? h('div', { class: 'files compact' }, item.files.map(fileTile)) : null)
      : h('div', { class: 'event' }, h('span', {}, h('strong', {}, item.user_id === boot.me ? 'You' : (members.get(item.user_id)?.name || 'Someone')), ' ', item.text), h('time', {}, fmtWhen(item.at)))));
    if (!t.can.comment) return section('Updates', feed);
    const mod = window.LPMKeys?.mod || 'Ctrl';
    const box = h('textarea', { rows: 2, placeholder: `Add an update… ${mod}+Enter to send · Esc to close` }, draft);
    const files = h('input', { type: 'file', multiple: true, hidden: true });
    const chosen = h('div', { class: 'chosen-files' });
    files.addEventListener('change', () => chosen.replaceChildren(...[...files.files].map(f => h('span', { class: 'chip' }, f.name))));
    const send = async (dropped = []) => {
      const list = [...files.files, ...dropped];
      if (!box.value.trim() && !list.length) return;
      const text = box.value;
      box.value = '';
      if (dropped.length) toast('Uploading…');
      const d = await upload(t, list, `/api/tasks/${t.id}/comments`, { body: text });
      if (!d) box.value = text;
      else { const nb = panel?.querySelector('.peek-body'); if (nb) nb.scrollTop = nb.scrollHeight; }
    };
    box.addEventListener('keydown', e => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
      // Esc leaves the box; with nothing typed it closes the panel in one go.
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); if (box.value.trim()) box.blur(); else close(); }
    });
    const sec = section('Updates', feed,
      h('div', { class: 'peek-comment' }, box, chosen,
        h('div', { class: 'row-between' },
          t.can.upload ? h('button', { type: 'button', class: 'icon-btn', title: 'Attach files (or drop them here)', onclick: () => files.click() }, svg(ICON.clip)) : h('span'),
          h('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: () => send() }, 'Add update')),
        files));
    // Drop files on the updates to post them as an update (with anything already typed).
    if (t.can.upload) window.LPMDrop?.(sec, 'Drop to post as an update', list => send([...list]));
    return sec;
  }

  window.LPM = { boot, members, h, svg, ICON, api, toast, fmtDate, fmtTime, fmtWhen, isOverdue, avatar, avatars, today, peek: { open, close } };
})();
