// All tasks: every visible task grouped by project, the Inbox first, with a "Move to" picker.
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

  function row(t) {
    const st = statusOf(t);
    const done = st.type === 'done';
    const canMove = boot.can.move && t.can.edit && !t.parent_id;
    const inInbox = projects.get(t.project_id)?.inbox;
    const sel = canMove ? h('select', { class: 'move-select' + (inInbox ? ' is-inbox' : ''), title: 'Move to another project', onchange: e => move(t, +e.target.value) },
      inInbox ? h('option', { value: t.project_id, selected: true }, 'Move to project…') : null,
      boot.projects.filter(p => !(inInbox && p.inbox)).map(p => h('option', { value: p.id, selected: p.id === t.project_id && !inInbox }, p.inbox ? 'Inbox' : p.name))) : null;
    const parent = t.parent_id ? byId.get(t.parent_id) : null;
    return h('div', { class: 'all-row' + (done ? ' is-done' : '') },
      h('span', { class: 'all-stage', style: `--sc:${st.color}` }, st.name),
      h('button', { type: 'button', class: 'all-title', onclick: () => peek.open(t.id, { onChange: task => { apply(task); render(); } }) },
        parent ? h('span', { class: 'muted small' }, parent.title + ' › ') : null, t.title),
      h('span', { class: 'all-people' }, t.assignees.length ? avatars(t.assignees) : null),
      h('span', { class: 'all-due' + (isOverdue(t.due_date, done) ? ' is-overdue' : '') }, t.due_date ? fmtDate(t.due_date) : ''),
      h('span', { class: 'all-move' }, sel));
  }

  function render() {
    const list = boot.tasks.filter(visible);
    groups.replaceChildren(...boot.projects.map(p => {
      const items = list.filter(t => t.project_id === p.id);
      if (!p.inbox && !items.length && (f.q || f.mine)) return null;
      const isCollapsed = collapsed.has(p.id) && !p.inbox;
      return h('section', { class: 'all-group' + (p.inbox ? ' is-inbox' : '') + (isCollapsed ? ' is-collapsed' : '') },
        h('header', { class: 'all-group-head' },
          h('button', { type: 'button', class: 'group-head', disabled: p.inbox, onclick: () => {
            collapsed.has(p.id) ? collapsed.delete(p.id) : collapsed.add(p.id);
            store.set(`lpm:${boot.org}:all:collapsed`, JSON.stringify([...collapsed]));
            render();
          } },
            p.inbox ? null : svg('<path d="M7 5l5 5-5 5"/>', 'i chev'),
            h('strong', {}, p.inbox ? 'Inbox' : p.name), h('span', { class: 'count' }, items.length)),
          p.inbox ? h('span', { class: 'muted small' }, 'Not in a project yet. Use “Move to project…” on each task.')
            : h('a', { class: 'small', href: boot.base.replace(/\/$/, '') + `/o/${boot.org}/p/${p.id}` }, 'Open board →')),
        isCollapsed ? null : (items.length ? h('div', { class: 'all-rows' }, items.map(row))
          : h('p', { class: 'muted small all-empty' }, p.inbox ? 'Nothing to sort. Tasks you create in meeting notes without a project land here.' : 'No tasks match.')));
    }).filter(Boolean));
  }

  document.getElementById('a-search').addEventListener('input', e => { f.q = e.target.value.trim().toLowerCase(); render(); });
  document.getElementById('a-mine').addEventListener('change', e => { f.mine = e.target.checked; render(); });
  document.getElementById('a-hide-done').addEventListener('change', e => { f.hideDone = e.target.checked; render(); });
  document.getElementById('a-subtasks').addEventListener('change', e => { f.subtasks = e.target.checked; render(); });
  render();
})();
