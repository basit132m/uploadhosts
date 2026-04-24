'use strict';

const SIGN_URL   = '/api/sign.php';
const MAX_MB     = 500;
const MAX_BYTES  = MAX_MB * 1024 * 1024;
const CONCURRENT = 3;                      // max parallel uploads

const dropzone   = document.getElementById('dropzone');
const fileInput  = document.getElementById('file-input');
const fileList   = document.getElementById('file-list');
const clearBtn   = document.getElementById('clear-btn');

// ── Drag & drop ───────────────────────────────────────────────────────────────
['dragenter','dragover'].forEach(e =>
  dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.add('drag-over'); })
);
['dragleave','drop'].forEach(e =>
  dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.remove('drag-over'); })
);
dropzone.addEventListener('drop', ev => handleFiles(ev.dataTransfer.files));
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

clearBtn.addEventListener('click', () => {
  fileList.innerHTML = '';
  clearBtn.style.display = 'none';
  fileInput.value = '';
});

// ── Entry point ───────────────────────────────────────────────────────────────
function handleFiles(fileSet) {
  const files = [...fileSet];
  if (!files.length) return;

  clearBtn.style.display = 'inline-block';

  // Render cards first, then upload with concurrency control
  const queue = files.map(file => {
    const card = createCard(file);
    fileList.prepend(card);
    return { file, card };
  });

  runQueue(queue);
  fileInput.value = '';
}

// Concurrency-limited upload runner
async function runQueue(queue) {
  let index = 0;

  async function next() {
    if (index >= queue.length) return;
    const item = queue[index++];
    await uploadFile(item.file, item.card);
    await next();
  }

  const workers = Array.from({ length: Math.min(CONCURRENT, queue.length) }, next);
  await Promise.all(workers);
}

// ── Card factory ──────────────────────────────────────────────────────────────
function createCard(file) {
  const card = document.createElement('div');
  card.className = 'file-card';
  card.innerHTML = `
    <span class="file-icon">${fileIcon(file.type)}</span>
    <div class="file-info">
      <div class="file-name" title="${esc(file.name)}">${esc(file.name)}</div>
      <div class="file-meta">${formatSize(file.size)}</div>
      <div class="progress-wrap"><div class="progress-bar" style="width:0%"></div></div>
    </div>
    <span class="badge badge-waiting">Waiting</span>
  `;
  return card;
}

// ── Upload logic ──────────────────────────────────────────────────────────────
async function uploadFile(file, card) {
  const badge    = card.querySelector('.badge');
  const bar      = card.querySelector('.progress-bar');
  const info     = card.querySelector('.file-info');

  // Validate size client-side
  if (file.size > MAX_BYTES) {
    setBadge(badge, 'error', 'Too large');
    bar.style.background = 'var(--error)';
    bar.style.width = '100%';
    return;
  }

  setBadge(badge, 'uploading', 'Signing…');

  // 1. Get presigned URL from our PHP backend
  let signData;
  try {
    const res = await fetch(SIGN_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filename: file.name, mimeType: file.type || 'application/octet-stream', fileSize: file.size }),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.error || `Server error ${res.status}`);
    }
    signData = await res.json();
  } catch (err) {
    setBadge(badge, 'error', 'Sign failed');
    appendError(info, err.message);
    return;
  }

  // 2. Upload directly to R2 using presigned PUT URL (XHR for progress events)
  setBadge(badge, 'uploading', 'Uploading…');

  await new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', signData.uploadUrl, true);
    xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');

    xhr.upload.addEventListener('progress', ev => {
      if (ev.lengthComputable) {
        bar.style.width = Math.round((ev.loaded / ev.total) * 100) + '%';
      }
    });

    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        bar.style.width = '100%';
        setBadge(badge, 'done', 'Done');
        appendCopyLink(info, signData.publicUrl);
      } else {
        setBadge(badge, 'error', `Upload failed (${xhr.status})`);
        bar.style.background = 'var(--error)';
      }
      resolve();
    });

    xhr.addEventListener('error', () => {
      setBadge(badge, 'error', 'Network error');
      bar.style.background = 'var(--error)';
      resolve();
    });

    xhr.send(file);
  });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function setBadge(el, type, text) {
  el.className = `badge badge-${type}`;
  el.textContent = text;
}

function appendError(info, msg) {
  const p = document.createElement('p');
  p.className = 'file-meta';
  p.style.color = 'var(--error)';
  p.textContent = msg;
  info.appendChild(p);
}

function appendCopyLink(info, url) {
  const wrap = document.createElement('div');
  wrap.innerHTML = `
    <p class="file-link">${esc(url)}</p>
    <button class="copy-btn" data-url="${esc(url)}">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      Copy link
    </button>
  `;
  wrap.querySelector('.copy-btn').addEventListener('click', function () {
    navigator.clipboard.writeText(this.dataset.url).then(() => {
      this.textContent = '✓ Copied!';
      this.classList.add('copied');
      setTimeout(() => { this.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy link`; this.classList.remove('copied'); }, 2000);
    });
  });
  info.appendChild(wrap);
}

function formatSize(bytes) {
  if (bytes < 1024)          return bytes + ' B';
  if (bytes < 1024 * 1024)   return (bytes / 1024).toFixed(1) + ' KB';
  if (bytes < 1024 ** 3)     return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  return (bytes / 1024 ** 3).toFixed(2) + ' GB';
}

function esc(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fileIcon(mime = '') {
  if (mime.startsWith('image/'))  return '🖼️';
  if (mime.startsWith('video/'))  return '🎬';
  if (mime.startsWith('audio/'))  return '🎵';
  if (mime.includes('pdf'))       return '📄';
  if (mime.includes('zip') || mime.includes('compressed') || mime.includes('tar')) return '🗜️';
  if (mime.includes('text'))      return '📝';
  return '📁';
}
