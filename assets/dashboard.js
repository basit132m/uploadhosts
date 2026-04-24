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
