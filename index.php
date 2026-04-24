<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Upload — UploadHost</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>☁️</text></svg>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= asset('/assets/style.css') ?>" />
</head>
<body>

  <?php require __DIR__ . '/includes/nav.php'; ?>

  <main class="main-content">
    <div class="page-header">
      <h2>Upload Files</h2>
      <p>Files go directly to Cloudflare R2 — fast &amp; secure.</p>
    </div>

    <div class="dropzone" id="dropzone">
      <input type="file" id="file-input" multiple />
      <span class="drop-icon">⬆️</span>
      <h2>Drag &amp; drop files here</h2>
      <p>or click to browse your device</p>
      <span class="browse-btn">Browse files</span>
      <p class="limits">Up to 10 GB per file &nbsp;·&nbsp; Auto-resumes if connection drops</p>
    </div>

    <div id="file-list"></div>
    <button id="clear-btn">Clear all</button>
  </main>

  <script>
    const CURRENT_USER_ID = <?= (int)$user['id'] ?>;
  </script>
  <script src="<?= asset('/assets/app.js') ?>"></script>

</body>
</html>
