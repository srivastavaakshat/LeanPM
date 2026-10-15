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
