// Meeting notes doc: a running log of dated entries (newest first). Opening the doc starts a fresh
// entry for now; it's only saved once you write in it. Type / (or \) on a line to turn it into a task.
(() => {
  'use strict';
  const { boot, h, svg, ICON, api, toast, fmtDate, fmtTime, avatar, avatars, isOverdue, peek } = window.LPM;
  const DOC = boot.doc;
  const stream = document.getElementById('notes-stream');
  const rail = document.getElementById('notes-rail');
  const chips = {};          // task id → live summary
  const entries = [];        // { id, el, body, titleEl, meetingAt, canEdit, timer, chain, draft }
  let lastProject = DOC.default_project;

  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch { /* old browsers */ }

  // ---------- dates ----------
  const pad = n => String(n).padStart(2, '0');
  const localNow = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; };
  const dayLabel = dt => {
    const d = new Date(dt.replace(' ', 'T'));
    const t = new Date(); t.setHours(0, 0, 0, 0);
    const day = new Date(d); day.setHours(0, 0, 0, 0);
    const diff = Math.round((t - day) / 86400000);
    const long = d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    return diff === 0 ? `Today · ${long}` : diff === 1 ? `Yesterday · ${long}` : long;
  };

  // ---------- saving ----------
  /** The entry's HTML as stored: task chips reduced to their title, unfinished task drafts kept as text. */
  function serialize(body) {
    const c = body.cloneNode(true);
    c.querySelectorAll('.task-draft').forEach(w => w.replaceWith(document.createTextNode(w.dataset.text || '')));
    c.querySelectorAll('.img-uploading, .drop-caret').forEach(w => w.remove());
    c.querySelectorAll('img').forEach(i => i.removeAttribute('class'));
    c.querySelectorAll('.task-ref').forEach(s => { s.textContent = s.dataset.title || s.textContent; s.removeAttribute('contenteditable'); s.removeAttribute('title'); s.removeAttribute('tabindex'); s.className = 'task-ref'; });
    const html = c.innerHTML.trim();
    return /^(<p>(<br>)?<\/p>)*$/.test(html) ? '' : html;
  }
  const hasContent = e => serialize(e.body) !== '' || e.titleEl.value.trim() !== '';

  function setStatus(e, text) {
    const s = e.el.querySelector('.entry-saved');
    if (s) s.textContent = text;
  }

  function scheduleSave(e, delay = 800) {
    clearTimeout(e.timer);
    setStatus(e, 'Editing…');
    e.timer = setTimeout(() => save(e), delay);
  }

  /** Saves run one after another per entry, so a draft is created once and then updated. */
  function save(e) {
    clearTimeout(e.timer);
    e.chain = (e.chain || Promise.resolve()).then(async () => {
      const body = serialize(e.body);
      const title = e.titleEl.value.trim();
      if (!e.id && !body && !title) return; // an untouched draft is never saved
      setStatus(e, 'Saving…');
      try {
        if (!e.id) {
          const d = await api('POST', `/api/notes/${DOC.id}/entries`, { meeting_at: e.meetingAt, title, body });
          e.id = d.entry.id;
          e.draft = false;
          e.el.classList.remove('is-draft');
          e.el.dataset.id = e.id;
          e.el.querySelector('.draft-tag')?.remove();
          e.el.querySelector('.entry-actions')?.prepend(deleteButton(e));
          drawRail();
        } else {
          await api('POST', `/api/notes/entries/${e.id}`, { title, body });
        }
        setStatus(e, 'Saved');
      } catch {
        setStatus(e, 'Not saved');
      }
    });
    return e.chain;
  }
  addEventListener('beforeunload', ev => {
    entries.forEach(e => { if (e.timer) save(e); });
    if (entries.some(e => e.timer && e.el.querySelector('.entry-saved')?.textContent === 'Editing…')) ev.preventDefault();
  });

  // ---------- entries ----------
  function deleteButton(e) {
    return h('button', {
      type: 'button', class: 'icon-btn small', title: 'Delete this entry',
      onclick: async () => {
        if (!confirm('Delete this entry? Tasks created from it stay on their boards.')) return;
        if (e.id) await api('POST', `/api/notes/entries/${e.id}/delete`);
        e.el.remove();
        entries.splice(entries.indexOf(e), 1);
        drawRail();
      },
    }, svg(ICON.x));
  }

  function buildEntry(data, { draft = false, focus = false } = {}) {
    const e = { id: data.id || null, meetingAt: data.meeting_at, canEdit: draft || data.can_edit, draft };
    e.titleEl = h('input', { class: 'entry-title', value: data.title || '', placeholder: draft ? 'What’s this meeting about? (optional)' : 'Add a title (optional)', maxlength: 120, readonly: !e.canEdit });
    e.body = h('div', {
      class: 'entry-body', contenteditable: e.canEdit ? 'true' : 'false',
      'data-placeholder': e.canEdit ? 'Write notes… Type / at the end of a line to turn it into a task.' : '',
      html: data.body || (e.canEdit ? '<p><br></p>' : ''),
    });
    const author = boot.members.find(m => m.id === data.author_id);
    e.el = h('section', { class: 'entry' + (draft ? ' is-draft' : ''), id: e.id ? `entry-${e.id}` : null, dataset: e.id ? { id: e.id } : {} },
      h('header', { class: 'entry-head' },
        h('div', { class: 'entry-when' },
          h('span', { class: 'entry-day' }, dayLabel(e.meetingAt)),
          h('span', { class: 'entry-time' }, fmtTime(e.meetingAt)),
          draft ? h('span', { class: 'tag draft-tag' }, 'New entry') : null),
        e.canEdit ? toolbar(e) : null,
        h('div', { class: 'entry-actions' },
          !draft && author && author.id !== boot.me ? avatar(author.id) : null,
          e.canEdit ? h('span', { class: 'entry-saved muted small' }, draft ? '' : '') : null,
          !draft && e.canEdit ? deleteButton(e) : null)),
      e.titleEl,
      e.body);
    if (e.canEdit) wireEditor(e);
    hydrate(e.body);
    entries.push(e);
    if (focus) requestAnimationFrame(() => placeCaretAtEnd(e.body));
    return e;
  }

  function newDraft(focus = true) {
    const existing = entries.find(e => e.draft);
    if (existing && !hasContent(existing)) {
      if (focus) placeCaretAtEnd(existing.body);
      existing.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return existing;
    }
    const e = buildEntry({ meeting_at: localNow(), author_id: boot.me }, { draft: true, focus });
    stream.prepend(e.el);
    return e;
  }

  // ---------- editor ----------
  function toolbar(e) {
    const cmd = (label, command, arg, tip) => h('button', {
      type: 'button', title: tip, onmousedown: ev => { ev.preventDefault(); document.execCommand(command, false, arg); scheduleSave(e); },
    }, label);
    return h('div', { class: 'rt-toolbar entry-toolbar' },
      cmd(h('b', {}, 'B'), 'bold', null, 'Bold'), cmd(h('i', {}, 'I'), 'italic', null, 'Italic'),
      cmd('H', 'formatBlock', 'h3', 'Heading'), cmd('• List', 'insertUnorderedList', null, 'Bulleted list'), cmd('1. List', 'insertOrderedList', null, 'Numbered list'),
      h('button', { type: 'button', title: 'Add a link', onmousedown: ev => {
        ev.preventDefault();
        const u = prompt('Link (https://…)');
        if (!u) return;
        const url = /^https?:\/\//i.test(u) ? u : 'https://' + u;
        const sel = getSelection();
        if (sel.isCollapsed) document.execCommand('insertHTML', false, `<a href="${escapeAttr(url)}">${escapeHtml(url)}</a>&nbsp;`);
        else document.execCommand('createLink', false, url);
        scheduleSave(e);
      } }, 'Link'),
      imageButton(e),
      boot.can.create ? h('button', { type: 'button', class: 'tb-task', title: 'Turn this line into a task (or type / )', onmousedown: ev => { ev.preventDefault(); startTask(e, currentBlock(e.body)); } },
        svg(ICON.task), 'Task') : null);
  }

  function wireEditor(e) {
    e.body.addEventListener('input', ev => {
      e.body.classList.toggle('is-empty', !e.body.textContent.trim() && !e.body.querySelector('.task-ref, li, img, .img-uploading'));
      scheduleSave(e);
      if (ev.inputType === 'insertText' && (ev.data === '/' || ev.data === '\\')) maybeOpenSlash(e);
      else if (slash.open) updateSlash();
    });
    e.body.addEventListener('keydown', ev => slashKey(ev));
    e.body.addEventListener('blur', () => { if (e.timer) save(e); setTimeout(closeSlash, 150); });
    e.titleEl.addEventListener('input', () => scheduleSave(e));
    e.titleEl.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); placeCaretAtEnd(e.body); } });
    e.body.addEventListener('paste', ev => {
      ev.preventDefault();
      const pics = imageFiles(ev.clipboardData);
      if (pics.length) { insertImages(e, pics, caretRange(e)); return; }
      const text = ev.clipboardData.getData('text/plain');
      if (/^https?:\/\/\S+$/.test(text.trim())) {
        const sel = getSelection();
        if (!sel.isCollapsed) document.execCommand('createLink', false, text.trim());
        else document.execCommand('insertHTML', false, `<a href="${escapeAttr(text.trim())}">${escapeHtml(text.trim())}</a>&nbsp;`);
      } else {
        document.execCommand('insertText', false, text);
      }
    });
    wireDrop(e);
    e.body.classList.toggle('is-empty', !e.body.textContent.trim() && !e.body.querySelector('img'));
  }

  // ---------- images between the lines ----------
  const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  const imageFiles = dt => [...(dt?.files || [])].filter(f => IMAGE_TYPES.includes(f.type));
  const isBlank = el => !el.textContent.trim() && !el.querySelector('img, .task-ref, .task-draft, .img-uploading');

  function imageButton(e) {
    const input = h('input', { type: 'file', accept: IMAGE_TYPES.join(','), multiple: true, hidden: true,
      onchange: () => { insertImages(e, [...input.files], e.savedRange); e.savedRange = null; input.value = ''; } });
    return h('button', { type: 'button', class: 'tb-image', title: 'Add an image at the cursor (or paste / drag one in)', onmousedown: ev => {
      ev.preventDefault();
      e.savedRange = caretRange(e); // the file picker takes focus away, so remember where the cursor was
      input.click();
    } }, svg('<rect x="3" y="4" width="14" height="12" rx="2"/><circle cx="7.5" cy="8.5" r="1.5"/><path d="M17 13l-4-4-7 7"/>'), 'Image', input);
  }

  /** The cursor inside this entry (or the end of it). */
  function caretRange(e) {
    const sel = getSelection();
    if (sel.rangeCount && e.body.contains(sel.getRangeAt(0).startContainer)) return sel.getRangeAt(0).cloneRange();
    placeCaretAtEnd(e.body);
    return getSelection().getRangeAt(0).cloneRange();
  }
  function rangeAt(x, y) {
    if (document.caretRangeFromPoint) return document.caretRangeFromPoint(x, y);
    const p = document.caretPositionFromPoint?.(x, y);
    if (!p) return null;
    const r = document.createRange(); r.setStart(p.offsetNode, p.offset); r.collapse(true);
    return r;
  }

  /**
   * Make room for a block (an image line) exactly at the range: in an empty line, use that line;
   * at the start or end of a line, a new line before or after it; mid-line, split the line in two.
   */
  function lineAt(e, range) {
    if (!range || !e.body.contains(range.startContainer)) range = caretRange(e);
    range.deleteContents();
    let node = range.startContainer;
    let block = (node.nodeType === 1 ? node : node.parentElement).closest('p, li, h2, h3, blockquote, pre, div:not(.entry-body)');
    if (!block || !e.body.contains(block) || block === e.body) {
      const p = document.createElement('p');
      if (node === e.body) e.body.insertBefore(p, e.body.childNodes[range.startOffset] || null); else (node.nodeType === 3 && node.parentNode === e.body ? node.after(p) : e.body.append(p));
      return p;
    }
    if (block.closest('li')) block = block.closest('ul, ol'); // images sit between lists, not inside a bullet
    if (isBlank(block) && block.tagName === 'P') { block.replaceChildren(); return block; }
    const line = document.createElement('p');
    const isList = block.tagName === 'UL' || block.tagName === 'OL';
    const half = r => !r.toString().trim() && !r.cloneContents().querySelector('img, .task-ref');
    const before = document.createRange(); before.selectNodeContents(block); before.setEnd(range.startContainer, range.startOffset);
    const after = document.createRange(); after.selectNodeContents(block); after.setStart(range.startContainer, range.startOffset);
    if (isList || half(after)) block.after(line);       // in a list, or at the end of a line: below it
    else if (half(before)) block.before(line);           // at the start of a line: above it
    else {                                               // mid-line: split it and go between the halves
      const tail = block.cloneNode(false);
      tail.append(after.extractContents());
      block.after(line, tail);
    }
    return line;
  }

  /** Upload images and place them at the range, one per line, with the cursor ready below. */
  function insertImages(e, files, range) {
    files = files.filter(f => {
      if (f.size <= (boot.maxUpload || 25) * 1048576) return true;
      toast(`${f.name} is over ${boot.maxUpload || 25} MB.`, 'error');
      return false;
    });
    if (!files.length) return;
    let line = lineAt(e, range);
    files.forEach((file, i) => {
      if (i) { const p = document.createElement('p'); line.after(p); line = p; }
      const holder = h('span', { class: 'img-uploading', contenteditable: 'false' }, h('span', { class: 'spinner' }), `Uploading ${file.name || 'image'}…`);
      line.append(holder);
      upload(e, file, holder);
    });
    caretBelow(e, line);
    e.body.classList.remove('is-empty');
  }
  function caretBelow(e, line) {
    let next = line.nextElementSibling;
    if (!next || next.tagName !== 'P' || next.querySelector('img, .img-uploading, .task-ref')) {
      next = document.createElement('p'); next.append(document.createElement('br')); line.after(next);
    }
    const r = document.createRange(); r.setStart(next, 0); r.collapse(true);
    e.body.focus(); const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r);
  }

  async function upload(e, file, holder) {
    const fd = new FormData();
    fd.append('image', file, file.name || 'pasted-image.png');
    let d = null;
    try {
      const res = await fetch(boot.base.replace(/\/$/, '') + `/api/notes/${DOC.id}/images`, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': boot.csrf, Accept: 'application/json' } });
      d = await res.json().catch(() => ({}));
      if (!res.ok) { toast(d.error || 'That image didn’t upload.', 'error'); d = null; }
    } catch { toast('You seem to be offline. The image wasn’t added.', 'error'); }
    if (!holder.isConnected) return;
    const line = holder.parentElement;
    if (!d) {
      holder.remove();
      if (line && isBlank(line)) line.remove();
      return;
    }
    const img = h('img', { src: d.image.url, alt: (file.name || '').replace(/\.[a-z0-9]+$/i, ''), dataset: { image: d.image.id } });
    if (d.image.width < 480) img.dataset.size = d.image.width < 240 ? 's' : 'm'; // small screenshots stay small
    holder.replaceWith(img);
    save(e);
  }

  // ---------- drag and drop: files from the computer, or an image already in the notes ----------
  // A lime caret follows the pointer to show exactly where it will land, like in a docs editor.
  const drag = { img: null, caret: null, range: null };
  function showCaret(range) {
    const rects = range?.getClientRects?.();
    let r = rects && rects[0];
    if (!r || (!r.height && range.startContainer.nodeType === 1)) {
      const el = range?.startContainer.nodeType === 1 ? (range.startContainer.childNodes[range.startOffset] || range.startContainer) : range?.startContainer.parentElement;
      r = el?.getBoundingClientRect?.();
    }
    if (!r) return hideCaret();
    if (!drag.caret) { drag.caret = h('div', { class: 'drop-caret', 'aria-hidden': 'true' }); document.body.append(drag.caret); }
    Object.assign(drag.caret.style, { top: (r.top + scrollY) + 'px', left: (r.left + scrollX - 1) + 'px', height: Math.max(18, r.height || 20) + 'px' });
  }
  function hideCaret() { drag.caret?.remove(); drag.caret = null; }

  function wireDrop(e) {
    e.body.addEventListener('dragstart', ev => {
      const img = ev.target.closest?.('img');
      if (!img) return;
      closeImgBar();
      drag.img = img;
      img.classList.add('is-dragging');
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', ''); // Firefox needs some data to start a drag
    });
    e.body.addEventListener('dragend', () => { drag.img?.classList.remove('is-dragging'); drag.img = null; hideCaret(); });
    e.body.addEventListener('dragover', ev => {
      const files = [...(ev.dataTransfer?.items || [])].some(i => i.kind === 'file');
      if (!files && !drag.img) return;
      ev.preventDefault();
      ev.dataTransfer.dropEffect = drag.img ? 'move' : 'copy';
      drag.range = rangeAt(ev.clientX, ev.clientY);
      if (drag.range && e.body.contains(drag.range.startContainer) && !drag.img?.contains(drag.range.startContainer)) showCaret(drag.range);
      else hideCaret();
    });
    e.body.addEventListener('dragleave', ev => { if (!e.body.contains(ev.relatedTarget)) hideCaret(); });
    e.body.addEventListener('drop', ev => {
      const range = rangeAt(ev.clientX, ev.clientY);
      hideCaret();
      if (drag.img) {
        ev.preventDefault();
        const img = drag.img, from = img.parentElement, src = entries.find(x => x.body.contains(img));
        if (!range || !e.body.contains(range.startContainer)) return;
        img.classList.remove('is-dragging');
        img.remove();
        const line = lineAt(e, range);
        line.append(img);
        if (from && from !== line && from.isConnected && isBlank(from) && from !== src?.body) from.remove();
        caretBelow(e, line);
        save(e);
        if (src && src !== e) save(src);
        drag.img = null;
        return;
      }
      if (!ev.dataTransfer?.files?.length) return;
      ev.preventDefault();
      const pics = imageFiles(ev.dataTransfer);
      if (!pics.length) { toast('Only images (PNG, JPEG, GIF, WebP) can go in notes. Attach other files to a task.', 'error'); return; }
      insertImages(e, pics, range);
    });
  }
  // Dropping a file anywhere else on the page would open it in the tab and lose your notes.
  addEventListener('dragover', ev => { if (!ev.defaultPrevented && [...(ev.dataTransfer?.items || [])].some(i => i.kind === 'file')) ev.preventDefault(); });
  addEventListener('drop', ev => { if (!ev.defaultPrevented && ev.dataTransfer?.files?.length) { ev.preventDefault(); toast('Drop images into an entry’s text to add them there.'); } });

  // Click an image to size it (S / M / Full), open it, or remove it. Drag it to move it.
  const imgBar = { el: null, img: null, entry: null };
  function closeImgBar() { imgBar.el?.remove(); imgBar.img?.classList.remove('is-selected'); imgBar.el = imgBar.img = imgBar.entry = null; }
  function placeImgBar() {
    if (!imgBar.img) return;
    const r = imgBar.img.getBoundingClientRect();
    imgBar.el.style.top = (r.top + scrollY + 8) + 'px';
    imgBar.el.style.left = Math.max(8, r.left + scrollX + 8) + 'px';
  }
  function openImgBar(e, img) {
    closeImgBar();
    imgBar.img = img; imgBar.entry = e;
    img.classList.add('is-selected');
    const size = (v, label, tip) => h('button', { type: 'button', class: (img.dataset.size || '') === v ? 'is-on' : '', title: tip, onmousedown: ev => {
      ev.preventDefault();
      if (v) img.dataset.size = v; else delete img.dataset.size;
      save(e); openImgBar(e, img);
    } }, label);
    imgBar.el = h('div', { class: 'popover img-bar', role: 'toolbar', 'aria-label': 'Image' },
      size('s', 'S', 'Small'), size('m', 'M', 'Medium'), size('', 'Full', 'Full width'),
      h('span', { class: 'img-bar-sep' }),
      h('button', { type: 'button', title: 'Open full size', onmousedown: ev => { ev.preventDefault(); open(img.src, '_blank', 'noopener'); } }, 'Open'),
      h('button', { type: 'button', class: 'danger', title: 'Remove from the notes (Delete)', onmousedown: ev => { ev.preventDefault(); removeImage(); } }, 'Remove'));
    document.body.append(imgBar.el);
    placeImgBar();
  }
  function removeImage() {
    const { img, entry } = imgBar;
    if (!img) return;
    const line = img.parentElement;
    closeImgBar();
    img.remove();
    if (line && line !== entry.body && isBlank(line)) line.replaceChildren(document.createElement('br'));
    save(entry);
  }
  stream.addEventListener('click', ev => {
    const img = ev.target.closest('.entry-body img');
    if (!img) { if (!ev.target.closest('.img-bar')) closeImgBar(); return; }
    const e = entries.find(x => x.body.contains(img));
    if (e?.canEdit) openImgBar(e, img);
    else open(img.src, '_blank', 'noopener'); // read-only: open full size
  });
  stream.addEventListener('dblclick', ev => { const img = ev.target.closest('.entry-body img'); if (img) open(img.src, '_blank', 'noopener'); });
  document.addEventListener('keydown', ev => {
    if (!imgBar.img) return;
    if (ev.key === 'Backspace' || ev.key === 'Delete') { ev.preventDefault(); removeImage(); }
    else if (ev.key === 'Escape') { ev.preventDefault(); closeImgBar(); }
    else if (!ev.metaKey && !ev.ctrlKey && !ev.shiftKey) closeImgBar();
  }, true);
  document.addEventListener('mousedown', ev => { if (imgBar.el && !ev.target.closest('.img-bar') && ev.target !== imgBar.img) closeImgBar(); });
  addEventListener('resize', closeImgBar);
  addEventListener('scroll', placeImgBar, { passive: true });

  // Links open with Cmd/Ctrl+click (a plain click puts the cursor in the text).
  stream.addEventListener('click', ev => {
    const a = ev.target.closest('.entry-body a[href]');
    if (a && (ev.metaKey || ev.ctrlKey || a.closest('[contenteditable=false]'))) { ev.preventDefault(); open(a.href, '_blank', 'noopener'); }
  });
  stream.addEventListener('mouseover', ev => {
    const a = ev.target.closest('.entry-body[contenteditable=true] a[href]');
    if (a && !a.title) a.title = `${a.href}\n${navigator.platform.includes('Mac') ? '⌘' : 'Ctrl'}+click to open`;
  });

  const BLOCKS = 'p, li, h2, h3, blockquote, div:not(.entry-body)';
  function currentBlock(body) {
    const sel = getSelection();
    let node = sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
    if (!node || !body.contains(node)) {
      placeCaretAtEnd(body);
      node = getSelection().getRangeAt(0).startContainer;
    }
    let block = (node.nodeType === 1 ? node : node.parentElement).closest(BLOCKS);
    if (!block || !body.contains(block)) {
      // Loose text directly in the editor: wrap it in a paragraph first.
      block = document.createElement('p');
      const n = node.nodeType === 3 ? node : null;
      if (n && n.parentNode === body) { body.insertBefore(block, n); block.append(n); } else { body.append(block); }
    }
    return block;
  }

  function placeCaretAtEnd(el) {
    el.focus();
    const r = document.createRange();
    r.selectNodeContents(el.querySelector(':scope > :last-child') || el);
    r.collapse(false);
    const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r);
  }
  const escapeHtml = s => s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const escapeAttr = escapeHtml;

  // ---------- "/" menu ----------
  const slash = { open: false, entry: null, node: null, offset: 0, el: null };
  function maybeOpenSlash(e) {
    if (!boot.can.create) return;
    const sel = getSelection();
    const r = sel.getRangeAt(0);
    const node = r.startContainer;
    if (node.nodeType !== 3) return;
    const before = node.textContent.slice(0, r.startOffset - 1);
    if (before && !/\s$/.test(before) && !/[.!?:;,)]$/.test(before)) {
      // "/" in the middle of a word (like a URL or "and/or"): only when it's at the end of the line.
      if (node.textContent.slice(r.startOffset).trim() !== '' || /https?:\/?\/?\S*$/.test(before)) return;
    }
    slash.open = true; slash.entry = e; slash.node = node; slash.offset = r.startOffset - 1;
    const rect = r.getBoundingClientRect();
    slash.el?.remove();
    slash.el = h('div', { class: 'popover slash-menu' },
      h('div', { class: 'slash-label' }, 'Create'),
      h('button', { type: 'button', class: 'picker-item is-on', onmousedown: ev => { ev.preventDefault(); chooseSlash(); } },
        h('span', { class: 'slash-icon' }, svg(ICON.task)),
        h('span', { class: 'grow' }, h('strong', {}, 'Task'), h('span', { class: 'muted small slash-hint' }, ' — turn this line into a task')),
        h('kbd', {}, 'Enter')),
      h('div', { class: 'slash-foot muted small' }, DOC.task ? `Becomes a subtask of “${DOC.task.title}” · Esc to keep typing` : 'Esc to keep typing'));
    document.body.append(slash.el);
    slash.el.style.top = (rect.bottom + scrollY + 6) + 'px';
    slash.el.style.left = Math.max(8, Math.min(rect.left + scrollX - 8, innerWidth - 300)) + 'px';
  }
  function slashQuery() {
    const sel = getSelection();
    if (!sel.rangeCount || sel.getRangeAt(0).startContainer !== slash.node) return null;
    return slash.node.textContent.slice(slash.offset + 1, sel.getRangeAt(0).startOffset);
  }
  function updateSlash() {
    const q = slashQuery();
    if (q === null || !/^t?a?s?k?$/i.test(q) || !'task'.startsWith(q.toLowerCase())) closeSlash();
  }
  function closeSlash() { slash.open = false; slash.el?.remove(); slash.el = null; }
  function slashKey(ev) {
    if (!slash.open) return;
    if (ev.key === 'Enter' || ev.key === 'Tab') { ev.preventDefault(); chooseSlash(); }
    else if (ev.key === 'Escape') { ev.preventDefault(); closeSlash(); }
  }
  function chooseSlash() {
    const e = slash.entry;
    const q = slashQuery() ?? '';
    // Remove the "/" (and anything typed after it) from the text.
    const r = document.createRange();
    r.setStart(slash.node, slash.offset);
    r.setEnd(slash.node, Math.min(slash.node.textContent.length, slash.offset + 1 + q.length));
    r.deleteContents();
    let block = slash.node.parentElement?.closest(BLOCKS);
    if (!block || block === e.body || !e.body.contains(block)) block = currentBlock(e.body);
    closeSlash();
    startTask(e, block);
  }

  // ---------- task drafts and chips ----------
  /** Replace a line with an inline "new task" form, prefilled with the line's text. */
  function startTask(e, block) {
    if (!boot.can.create) return;
    if (!e.body.contains(block)) block = currentBlock(e.body);
    const hasOther = block.querySelector('.task-ref, .task-draft');
    const prefill = hasOther ? '' : block.textContent.replace(/\s+/g, ' ').trim();
    if (hasOther) { const p = document.createElement('p'); block.after(p); block = p; }

    const parent = DOC.task; // connected to a task: new tasks become its subtasks
    const input = h('input', { class: 'td-input', value: prefill, placeholder: parent ? 'Name the subtask, then press Enter' : 'Name the task, then press Enter', maxlength: 255 });
    const projectSel = parent
      ? h('span', { class: 'td-parent', title: `Becomes a subtask of “${parent.title}”` }, '↳ ', parent.title)
      : h('select', { class: 'td-project', title: 'Project for this task' },
        boot.projects.map(p => h('option', { value: p.id, selected: p.id === lastProject }, p.inbox ? 'Inbox' : p.name)));
    const widget = h('span', { class: 'task-draft', contenteditable: 'false', dataset: { text: prefill } },
      h('span', { class: 'td-tag' }, svg(ICON.task), parent ? 'Subtask' : 'Task'),
      input, projectSel,
      h('button', { type: 'button', class: 'td-ok', title: 'Create task (Enter)', onclick: () => create() }, svg(ICON.check)),
      h('button', { type: 'button', class: 'td-cancel', title: 'Cancel (Esc)', onclick: () => cancel() }, svg(ICON.x)));
    block.replaceChildren(widget);
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);

    input.addEventListener('input', () => { widget.dataset.text = input.value; scheduleSave(e, 1500); });
    if (!parent) projectSel.addEventListener('change', () => { lastProject = +projectSel.value; });
    [input, projectSel].forEach(el => el.addEventListener('keydown', ev => {
      ev.stopPropagation();
      if (ev.key === 'Enter') { ev.preventDefault(); create(); }
      if (ev.key === 'Escape') { ev.preventDefault(); cancel(); }
    }));

    let busy = false;
    async function create() {
      const title = input.value.trim();
      if (!title) { input.focus(); return toast('Give the task a name first.'); }
      if (busy) return;
      busy = true;
      widget.classList.add('is-busy');
      try {
        const d = await api('POST', `/api/notes/${DOC.id}/tasks`, parent ? { title } : { title, project_id: +projectSel.value });
        chips[d.task.id] = d.task;
        const chip = h('span', { class: 'task-ref', dataset: { task: d.task.id } }, title);
        widget.replaceWith(chip);
        drawChip(chip);
        // Carry on writing on a fresh line below.
        const next = document.createElement('p');
        next.append(document.createElement('br'));
        block.after(next);
        const r = document.createRange(); r.setStart(next, 0); r.collapse(true);
        e.body.focus(); const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r);
        await save(e);
        drawRail();
        toast(parent ? `Subtask added to “${parent.title}”` : `Task created in ${d.task.project.inbox ? 'the Inbox' : d.task.project.name}`);
      } catch {
        busy = false;
        widget.classList.remove('is-busy');
      }
    }
    function cancel() {
      const text = document.createTextNode(input.value || prefill);
      widget.replaceWith(text);
      if (!block.textContent) block.append(document.createElement('br'));
      const r = document.createRange(); r.setStartAfter(text); r.collapse(true);
      e.body.focus(); const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r);
      scheduleSave(e);
    }
  }

  function drawChip(span) {
    const id = +span.dataset.task;
    const t = chips[id];
    span.contentEditable = 'false';
    span.tabIndex = 0;
    if (!t) {
      span.dataset.title = span.dataset.title || span.textContent;
      span.classList.add('is-missing');
      span.title = 'This task was deleted or you can’t see its project.';
      span.replaceChildren(h('span', { class: 'tr-title' }, span.dataset.title));
      return;
    }
    const done = t.status.type === 'done';
    span.dataset.title = t.title;
    span.className = 'task-ref' + (done ? ' is-done' : '');
    span.title = 'Open task';
    span.replaceChildren(...[
      h('span', { class: 'tr-check' }, done ? svg(ICON.check) : null),
      h('span', { class: 'tr-title' }, t.title),
      h('span', { class: 'tr-stage', style: `--sc:${t.status.color}` }, t.status.name),
      h('span', { class: 'tr-project' }, t.project.inbox ? 'Inbox' : t.project.name),
      t.assignees.length ? avatars(t.assignees) : null,
      t.due_date ? h('span', { class: 'tr-due' + (isOverdue(t.due_date, done) ? ' is-overdue' : '') }, fmtDate(t.due_date)) : null,
    ].filter(Boolean));
  }
  function hydrate(root) { root.querySelectorAll('.task-ref').forEach(drawChip); }

  // A chip opens the quick view; changes there update every chip of that task.
  function openChip(id) {
    peek.open(id, {
      sections: DOC.sections,
      onChange: t => {
        chips[t.id] = { ...chips[t.id], title: t.title, due_date: t.due_date, assignees: t.assignees,
          status: t.statuses.find(s => s.id === t.status_id), project: t.project, url: t.url };
        document.querySelectorAll(`.task-ref[data-task="${t.id}"]`).forEach(drawChip);
        drawRail();
      },
    });
  }
  document.addEventListener('click', ev => {
    const chip = ev.target.closest('.task-ref[data-task]');
    if (chip && !chip.classList.contains('is-missing')) { ev.preventDefault(); openChip(+chip.dataset.task); }
    const row = ev.target.closest('[data-open-task]');
    if (row) { ev.preventDefault(); openChip(+row.dataset.openTask); }
  });
  document.addEventListener('keydown', ev => {
    const chip = ev.target.closest?.('.task-ref[data-task]');
    if (chip && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); openChip(+chip.dataset.task); }
  });

  // ---------- side rail: tasks from this doc + meetings ----------
  function drawRail() {
    const ids = [...new Set([...document.querySelectorAll('.entry-body .task-ref[data-task]')].map(s => +s.dataset.task))].filter(id => chips[id]);
    const list = ids.map(id => chips[id]);
    const open = list.filter(t => t.status.type !== 'done').sort((a, b) => b.created_at.localeCompare(a.created_at));
    const done = list.filter(t => t.status.type === 'done');
    const item = t => h('button', { type: 'button', class: 'rail-task' + (t.status.type === 'done' ? ' is-done' : ''), dataset: { openTask: t.id } },
      h('span', { class: 'dot', style: `--sc:${t.status.color}` }),
      h('span', { class: 'grow' }, h('span', { class: 'rail-task-title' }, t.title),
        h('span', { class: 'muted small' }, `${t.status.name} · ${t.project.inbox ? 'Inbox' : t.project.name}`)),
      t.assignees.length ? avatars(t.assignees) : null);

    const meetings = entries.filter(e => e.id).sort((a, b) => b.meetingAt.localeCompare(a.meetingAt));
    rail.replaceChildren(...[
      h('section', { class: 'rail-card' },
        h('h3', {}, 'Tasks from this doc', h('span', { class: 'count' }, open.length ? `${open.length} open` : '')),
        list.length ? null : h('p', { class: 'muted small' }, 'Tasks you create in these notes show up here, with their current stage.'),
        open.map(item),
        done.length ? h('details', { class: 'rail-done' }, h('summary', {}, `Done (${done.length})`), done.map(item)) : null),
      meetings.length ? h('section', { class: 'rail-card' },
        h('h3', {}, 'Meetings', h('span', { class: 'count' }, meetings.length)),
        h('div', { class: 'rail-meetings' }, meetings.slice(0, 40).map(e => h('a', {
          href: `#entry-${e.id}`, class: 'rail-meeting',
          onclick: ev => { ev.preventDefault(); e.el.scrollIntoView({ behavior: 'smooth', block: 'start' }); e.el.classList.add('is-flash'); setTimeout(() => e.el.classList.remove('is-flash'), 1200); },
        }, h('span', {}, fmtDate(e.meetingAt)), h('span', { class: 'muted small' }, e.titleEl.value.trim() || fmtTime(e.meetingAt)))))) : null,
    ].filter(Boolean));
  }

  // ---------- start ----------
  boot.entries.forEach(d => stream.append(buildEntry(d).el));
  newDraft(true);
  document.getElementById('new-entry')?.addEventListener('click', () => newDraft(true));
  drawRail();
  api('GET', `/api/notes/${DOC.id}/tasks`).then(d => {
    Object.assign(chips, d.tasks);
    entries.forEach(e => hydrate(e.body));
    drawRail();
  }).catch(() => {});
})();
