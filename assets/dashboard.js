'use strict';

// Copy link buttons
document.querySelectorAll('.copy-db-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    navigator.clipboard.writeText(this.dataset.url).then(() => {
      const orig = this.textContent;
      this.textContent = '✓ Copied!';
      this.classList.add('copied');
      setTimeout(() => { this.textContent = orig; this.classList.remove('copied'); }, 2000);
    });
  });
});

// Rename buttons
document.querySelectorAll('.rename-db-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    const card    = document.getElementById('file-' + this.dataset.id);
    const nameEl  = card.querySelector('.db-file-name');
    const fileId  = this.dataset.id;
    const renameBtn = this;

    // Toggle: if already open, close it
    const existing = card.querySelector('.rename-inline');
    if (existing) { existing.remove(); return; }

    const wrap = document.createElement('div');
    wrap.className = 'rename-inline';
    wrap.innerHTML = `
      <input type="text" class="form-input rename-input" value="${nameEl.textContent.trim().replace(/"/g, '&quot;')}" />
      <div class="rename-actions">
        <button class="btn btn-sm btn-success rename-save-btn">Save</button>
        <button class="btn btn-sm btn-outline rename-cancel-btn">Cancel</button>
      </div>`;

    // Insert after the file body
    card.querySelector('.db-file-body').after(wrap);

    const input = wrap.querySelector('.rename-input');
    input.focus();
    input.select();

    wrap.querySelector('.rename-cancel-btn').addEventListener('click', () => wrap.remove());

    const doRename = async () => {
      const newName = input.value.trim();
      if (!newName) { input.focus(); return; }

      const saveBtn = wrap.querySelector('.rename-save-btn');
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';

      try {
        const res  = await fetch('/api/files.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'rename', id: parseInt(fileId), filename: newName }),
        });
        const data = await res.json();

        if (res.ok) {
          nameEl.textContent = data.filename;
          nameEl.title = data.filename;
          renameBtn.dataset.name = data.filename;
          wrap.remove();
        } else {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save';
          alert(data.error || 'Rename failed');
        }
      } catch {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        alert('Network error — please try again');
      }
    };

    wrap.querySelector('.rename-save-btn').addEventListener('click', doRename);
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter')  doRename();
      if (e.key === 'Escape') wrap.remove();
    });
  });
});

// Delete buttons
document.querySelectorAll('.delete-db-btn').forEach(btn => {
  btn.addEventListener('click', async function () {
    if (!confirm('Delete this file permanently?')) return;

    this.disabled = true;
    this.textContent = 'Deleting…';

    const res = await fetch('/api/files.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id: parseInt(this.dataset.id) }),
    });

    if (res.ok) {
      const card = document.getElementById('file-' + this.dataset.id);
      card.style.transition = 'opacity .3s';
      card.style.opacity = '0';
      setTimeout(() => card.remove(), 300);
    } else {
      this.disabled = false;
      this.textContent = 'Delete';
      alert('Failed to delete file. Please try again.');
    }
  });
});
