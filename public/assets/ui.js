// Small page helpers: dialogs, confirmations, copy buttons, flash messages.
document.addEventListener('click', e => {
  const open = e.target.closest('[data-open]');
  if (open) document.getElementById(open.dataset.open)?.showModal();
  const close = e.target.closest('[data-close]');
  if (close) close.closest('dialog')?.close();
  const copy = e.target.closest('[data-copy]');
  if (copy) {
    const text = document.querySelector(copy.dataset.copy)?.textContent || '';
    navigator.clipboard?.writeText(text).then(() => { copy.textContent = 'Copied'; });
  }
  // Click outside a dialog's box closes it.
  if (e.target.tagName === 'DIALOG') e.target.close();
  // Close the mobile menu when tapping outside it.
  if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar, .menu-toggle')) {
    document.body.classList.remove('nav-open');
  }
  // Org switcher closes on outside click.
  document.querySelectorAll('details.org-switch[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; });
});

document.addEventListener('submit', e => {
  const msg = e.target.dataset.confirm;
  if (msg && !confirm(msg)) e.preventDefault();
});

document.querySelectorAll('[data-autohide]').forEach(el => setTimeout(() => el.classList.add('is-hiding'), 4000));

// Sidebar: full width or a thin icon rail. Remembered per browser.
(() => {
  const root = document.documentElement;
  const btn = document.getElementById('side-toggle');
  if (!btn) return;
  const sync = () => {
    const c = root.classList.contains('side-collapsed');
    btn.dataset.tip = c ? 'Expand sidebar ([)' : 'Collapse sidebar ([)';
    btn.setAttribute('aria-label', btn.dataset.tip);
    btn.setAttribute('aria-expanded', String(!c));
  };
  const toggle = () => {
    root.classList.toggle('side-collapsed');
    try { localStorage.setItem('lpm:side', root.classList.contains('side-collapsed') ? 'collapsed' : 'open'); } catch (e) { /* ignore */ }
    sync();
  };
  btn.addEventListener('click', toggle);
  document.addEventListener('keydown', e => {
    if (e.key === '[' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.target.closest('input, textarea, select, [contenteditable=true]')) toggle();
  });
  sync();
})();

// Sidebar tooltips: in the thin rail, hovering (or keyboard-focusing) an icon shows its name
// to the right. Items marked data-tip-always (icon-only buttons) show it in both modes.
(() => {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  const tip = document.createElement('div');
  tip.className = 'side-tip';
  tip.setAttribute('role', 'tooltip');
  tip.hidden = true;
  document.body.append(tip);
  const show = el => {
    const collapsed = document.documentElement.classList.contains('side-collapsed') && matchMedia('(min-width: 761px)').matches;
    if (!el.dataset.tip || (!collapsed && !('tipAlways' in el.dataset))) return hide();
    if (el.closest('details[open]')) return hide(); // the org menu is open
    tip.textContent = el.dataset.tip;
    tip.hidden = false;
    const r = el.getBoundingClientRect();
    tip.style.top = Math.round(r.top + r.height / 2 - tip.offsetHeight / 2) + 'px';
    tip.style.left = Math.round(r.right + 10) + 'px';
  };
  const hide = () => { tip.hidden = true; };
  sidebar.addEventListener('mouseover', e => { const el = e.target.closest('[data-tip]'); el ? show(el) : hide(); });
  sidebar.addEventListener('mouseleave', hide);
  sidebar.addEventListener('focusin', e => { const el = e.target.closest('[data-tip]'); if (el) show(el); });
  sidebar.addEventListener('focusout', hide);
  sidebar.addEventListener('click', hide);
  sidebar.querySelector('.side-nav')?.addEventListener('scroll', hide);
})();

// Drop files onto an area to upload them, no button needed. While files are over the area it
// lights up and a label says what will happen. Used by the task panel and the quick view.
(() => {
  const hasFiles = e => [...(e.dataTransfer?.types || [])].includes('Files');
  window.LPMDrop = (el, label, onFiles) => {
    let depth = 0, tag = null;
    const on = () => {
      el.classList.add('is-drop-over');
      if (tag) return;
      const r = el.getBoundingClientRect();
      const top = Math.max(r.top, 0), bottom = Math.min(r.bottom, innerHeight);
      tag = document.createElement('div');
      tag.className = 'drop-label';
      tag.textContent = label;
      tag.style.left = r.left + r.width / 2 + 'px';
      tag.style.top = (top + bottom) / 2 + 'px';
      document.body.append(tag);
    };
    const off = () => { depth = 0; el.classList.remove('is-drop-over'); tag?.remove(); tag = null; };
    el.addEventListener('dragenter', e => { if (!hasFiles(e)) return; e.preventDefault(); depth++; on(); });
    el.addEventListener('dragover', e => { if (!hasFiles(e)) return; e.preventDefault(); e.stopPropagation(); e.dataTransfer.dropEffect = 'copy'; on(); });
    el.addEventListener('dragleave', e => { if (hasFiles(e) && --depth <= 0) off(); });
    el.addEventListener('drop', e => {
      if (!hasFiles(e)) return;
      e.preventDefault(); e.stopPropagation(); off();
      if (e.dataTransfer.files.length) onFiles(e.dataTransfer.files);
    });
  };
  // A file dropped anywhere else shouldn't make the browser open it and leave the page
  // (file inputs, like the CSV import, still take drops as usual).
  for (const type of ['dragover', 'drop']) {
    addEventListener(type, e => { if (hasFiles(e) && !e.target.closest?.('input[type=file]')) e.preventDefault(); });
  }
})();

// Small <details> menus (Export, …) close when you click elsewhere or press Esc.
document.addEventListener('click', e => document.querySelectorAll('details.export-menu[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('details.export-menu[open]').forEach(d => { d.open = false; }); });
