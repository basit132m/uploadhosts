'use strict';

const API = {
  sign:     '/api/sign.php',
  files:    '/api/files.php',
  init:     '/api/multipart/init.php',
  presign:  '/api/multipart/presign.php',
  complete: '/api/multipart/complete.php',
  abort:    '/api/multipart/abort.php',
};

const CHUNK      = 10 * 1024 * 1024;           // 10 MB per part
const MAX_BYTES  = 10 * 1024 * 1024 * 1024;    // 10 GB
const MAX_RETRY  = 3;
const CONCURRENT = 3;

// ── Drag & drop ───────────────────────────────────────────────────────────────
const dropzone  = document.getElementById('dropzone');
const fileInput = document.getElementById('file-input');
const fileList  = document.getElementById('file-list');
const clearBtn  = document.getElementById('clear-btn');

['dragenter', 'dragover'].forEach(e =>
  dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.add('drag-over'); })
);
['dragleave', 'drop'].forEach(e =>
  dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.remove('drag-over'); })
);
dropzone.addEventListener('drop', ev => handleFiles(ev.dataTransfer.files));
fileInput.addEventListener('change', () => { handleFiles(fileInput.files); fileInput.value = ''; });
clearBtn.addEventListener('click', () => { fileList.innerHTML = ''; clearBtn.style.display = 'none'; });

// ── Entry point ───────────────────────────────────────────────────────────────
function handleFiles(fileSet) {
  const files = [...fileSet];
  if (!files.length) return;
  clearBtn.style.display = 'inline-block';
  const queue = files.map(file => ({ file, card: createCard(file) }));
  queue.forEach(({ card }) => fileList.prepend(card));
  runQueue(queue);
}

async function runQueue(queue) {
  let i = 0;
  const next = async () => {
    if (i >= queue.length) return;
    const item = queue[i++];
    await uploadFile(item.file, item.card);
    await next();
  };
  await Promise.all(Array.from({ length: Math.min(CONCURRENT, queue.length) }, next));
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
      <div class="file-part-info"></div>
      <div class="progress-wrap"><div class="progress-bar" style="width:0%"></div></div>
    </div>
    <span class="badge badge-waiting">Waiting</span>
  `;
  return card;
}

// ── Route by file size ────────────────────────────────────────────────────────
async function uploadFile(file, card) {
  if (file.size > MAX_BYTES) {
    setBadge(card, 'error', 'Exceeds 10 GB');
    return;
  }
  return file.size > CHUNK
    ? uploadMultipart(file, card)
    : uploadSimple(file, card);
}

// ── Simple upload — files ≤ 10 MB ─────────────────────────────────────────────
async function uploadSimple(file, card) {
  setBadge(card, 'uploading', 'Signing…');

  let signData;
  try {
    const res = await postJSON(API.sign, { filename: file.name, mimeType: mime(file), fileSize: file.size });
    if (!res.ok) throw new Error((await res.json()).error || `HTTP ${res.status}`);
    signData = await res.json();
  } catch (e) {
    return fail(card, 'Sign failed', e.message);
  }

  setBadge(card, 'uploading', 'Uploading…');

  await new Promise(resolve => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', signData.uploadUrl, true);
    xhr.setRequestHeader('Content-Type', mime(file));
    xhr.upload.addEventListener('progress', ev => {
      if (ev.lengthComputable) setBar(card, ev.loaded / ev.total * 100);
    });
    xhr.addEventListener('load', async () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        setBar(card, 100);
        setBadge(card, 'done', 'Done');
        showLink(card, signData.publicUrl);
        await saveRecord(file, signData.key, signData.publicUrl);
      } else {
        fail(card, `Failed (${xhr.status})`);
      }
      resolve();
    });
    xhr.addEventListener('error', () => { fail(card, 'Network error'); resolve(); });
    xhr.send(file);
  });
}

// ── Multipart upload — files > 10 MB, with resume ────────────────────────────
async function uploadMultipart(file, card) {
  const totalParts = Math.ceil(file.size / CHUNK);
  const resumeKey  = `uh_mp_${file.name}_${file.size}_${file.lastModified}`;
  let   state      = JSON.parse(localStorage.getItem(resumeKey) || 'null');

  let uploadId, key, completedParts;

  if (state?.uploadId) {
    ({ uploadId, key } = state);
    completedParts = state.completedParts || [];
    const pct = Math.round(completedParts.length / totalParts * 100);
    setBadge(card, 'uploading', `Resuming ${pct}%`);
    setBar(card, pct);
  } else {
    setBadge(card, 'uploading', 'Initializing…');
    try {
      const res  = await postJSON(API.init, { filename: file.name, mimeType: mime(file), fileSize: file.size });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
      ({ uploadId, key } = data);
      completedParts = [];
      state = { uploadId, key, filename: file.name, fileSize: file.size, mimeType: mime(file), completedParts };
      localStorage.setItem(resumeKey, JSON.stringify(state));
    } catch (e) {
      return fail(card, 'Init failed', e.message);
    }
  }

  // Cancel button
  let aborted = false;
  const cancelBtn = addCancelBtn(card, async () => {
    aborted = true;
    localStorage.removeItem(resumeKey);
    postJSON(API.abort, { key, uploadId }).catch(() => {});
    setBadge(card, 'error', 'Cancelled');
    setBar(card, 100, 'var(--error)');
  });

  setBadge(card, 'uploading', 'Uploading…');

  // Upload each part sequentially
  for (let n = 1; n <= totalParts; n++) {
    if (aborted) return;

    if (completedParts.find(p => p.partNumber === n)) {
      updatePartProgress(card, completedParts, n - 1, totalParts, file.size);
      continue;
    }

    const start = (n - 1) * CHUNK;
    const chunk = file.slice(start, Math.min(start + CHUNK, file.size));

    setPartInfo(card, `Part ${n} of ${totalParts}`);
    updatePartProgress(card, completedParts, completedParts.length, totalParts, file.size);

    // Get presigned URL for this part
    let partUrl;
    try {
      const res  = await postJSON(API.presign, { key, uploadId, partNumber: n });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
      partUrl = data.url;
    } catch (e) {
      return fail(card, 'Presign failed', e.message);
    }

    // Upload part with retry
    let etag = null;
    for (let attempt = 1; attempt <= MAX_RETRY; attempt++) {
      if (aborted) return;
      try {
        etag = await uploadPart(chunk, partUrl, completedParts.length, n, totalParts, file.size, card);
        break;
      } catch (e) {
        if (attempt === MAX_RETRY) {
          return fail(card, `Part ${n} failed`, 'Drop the file again to resume from here.');
        }
        setBadge(card, 'uploading', `Retrying part ${n}…`);
        await sleep(attempt * 1500);
      }
    }

    completedParts.push({ partNumber: n, etag });
    state.completedParts = completedParts;
    localStorage.setItem(resumeKey, JSON.stringify(state));
    updatePartProgress(card, completedParts, completedParts.length, totalParts, file.size);
  }

  if (aborted) return;
  cancelBtn.remove();

  // Assemble all parts into one file
  setBadge(card, 'uploading', 'Assembling…');
  setPartInfo(card, 'Finalizing file on R2…');

  try {
    const res = await postJSON(API.complete, {
      key, uploadId, parts: completedParts,
      filename: file.name, fileSize: file.size, mimeType: mime(file),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);

    localStorage.removeItem(resumeKey);
    setBar(card, 100);
    setBadge(card, 'done', 'Done');
    setPartInfo(card, '');
    showLink(card, data.publicUrl);
  } catch (e) {
    fail(card, 'Assembly failed', e.message + ' — Drop again to retry.');
  }
}

// Upload one part via XHR, resolve with ETag string
function uploadPart(chunk, url, doneCount, partNum, totalParts, fileSize, card) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', url, true);
    xhr.upload.addEventListener('progress', ev => {
      if (ev.lengthComputable)
        updatePartProgress(card, null, doneCount, totalParts, fileSize, ev.loaded, CHUNK);
    });
    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        const etag = xhr.getResponseHeader('ETag');
        etag ? resolve(etag) : reject(new Error('Missing ETag in R2 response'));
      } else {
        reject(new Error(`HTTP ${xhr.status}`));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('Network error')));
    xhr.addEventListener('abort', () => reject(new Error('Aborted')));
    xhr.send(chunk);
  });
}

function updatePartProgress(card, completedParts, doneCount, totalParts, fileSize, currentBytes = 0, chunkSize = CHUNK) {
  const doneBytes  = doneCount * chunkSize;
  const pct        = Math.min(99, Math.round((doneBytes + currentBytes) / fileSize * 100));
  setBar(card, pct);
}

// ── Database record ───────────────────────────────────────────────────────────
async function saveRecord(file, key, publicUrl) {
  await postJSON(API.files, {
    action: 'save', filename: file.name, key, publicUrl,
    fileSize: file.size, mimeType: mime(file),
  }).catch(() => {});
}

// ── UI helpers ────────────────────────────────────────────────────────────────
function setBadge(card, type, text) {
  const b = card.querySelector('.badge');
  b.className = `badge badge-${type}`;
  b.textContent = text;
}

function setBar(card, pct, color = '') {
  const bar = card.querySelector('.progress-bar');
  bar.style.width = Math.min(100, pct) + '%';
  if (color) bar.style.background = color;
}

function setPartInfo(card, text) {
  card.querySelector('.file-part-info').textContent = text;
}

function fail(card, badge, msg = '') {
  setBadge(card, 'error', badge);
  setBar(card, 100, 'var(--error)');
  if (msg) {
    const p = document.createElement('p');
    p.className = 'file-meta';
    p.style.color = 'var(--error)';
    p.textContent = msg;
    card.querySelector('.file-info').appendChild(p);
  }
}

function addCancelBtn(card, onClick) {
  const btn = document.createElement('button');
  btn.className = 'btn btn-sm btn-danger';
  btn.style.marginTop = '8px';
  btn.textContent = 'Cancel upload';
  let clicked = false;
  btn.addEventListener('click', () => {
    if (clicked) return;
    clicked = true;
    btn.disabled = true;
    btn.textContent = 'Cancelling…';
    onClick();
  });
  card.querySelector('.file-info').appendChild(btn);
  return btn;
}

function showLink(card, url) {
  const wrap = document.createElement('div');
  wrap.innerHTML = `
    <p class="file-link">${esc(url)}</p>
    <button class="copy-btn" data-url="${esc(url)}">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <rect x="9" y="9" width="13" height="13" rx="2"/>
        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
      </svg>
      Copy link
    </button>`;
  wrap.querySelector('.copy-btn').addEventListener('click', function () {
    navigator.clipboard.writeText(this.dataset.url).then(() => {
      this.textContent = '✓ Copied!';
      this.classList.add('copied');
      setTimeout(() => {
        this.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy link`;
        this.classList.remove('copied');
      }, 2000);
    });
  });
  card.querySelector('.file-info').appendChild(wrap);
}

function postJSON(url, data) {
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
}

function mime(file) { return file.type || 'application/octet-stream'; }
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

function formatSize(b) {
  if (b < 1024)       return b + ' B';
  if (b < 1048576)    return (b / 1024).toFixed(1) + ' KB';
  if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
  return (b / 1073741824).toFixed(2) + ' GB';
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fileIcon(t = '') {
  if (t.startsWith('image/')) return '🖼️';
  if (t.startsWith('video/')) return '🎬';
  if (t.startsWith('audio/')) return '🎵';
  if (t.includes('pdf'))      return '📄';
  if (t.includes('zip') || t.includes('tar') || t.includes('compressed')) return '🗜️';
  if (t.includes('text'))     return '📝';
  return '📁';
}
