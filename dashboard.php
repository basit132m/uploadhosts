<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user = currentUser();

$db    = getDb();
$stmt  = $db->prepare("SELECT * FROM uploads WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['id']]);
$files = $stmt->fetchAll();

function phpFormatSize(int $bytes): string {
    if ($bytes < 1024)        return $bytes . ' B';
    if ($bytes < 1048576)     return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824)  return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Files — UploadHost</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>☁️</text></svg>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= asset('/assets/styles1.css') ?>" />
</head>
<body>

  <?php require __DIR__ . '/includes/nav.php'; ?>

  <main class="main-content">
    <div class="page-header">
      <h2>My Files</h2>
      <p><?= count($files) ?> file<?= count($files) !== 1 ? 's' : '' ?> uploaded</p>
    </div>

    <?php if (empty($files)): ?>
      <div class="empty-state">
        <span class="empty-icon">📂</span>
        <p>No files yet. <a href="/">Upload your first file</a></p>
      </div>
    <?php else: ?>
      <div class="file-grid">
        <?php foreach ($files as $file):
          $isImage = strpos($file['mime_type'], 'image/') === 0;
          $icon = match(true) {
            strpos($file['mime_type'], 'image/')  === 0 => '🖼️',
            strpos($file['mime_type'], 'video/')  === 0 => '🎬',
            strpos($file['mime_type'], 'audio/')  === 0 => '🎵',
            strpos($file['mime_type'], 'pdf')     !== false => '📄',
            strpos($file['mime_type'], 'zip')     !== false => '🗜️',
            strpos($file['mime_type'], 'text')    !== false => '📝',
            default => '📁',
          };
        ?>
          <div class="db-file-card" id="file-<?= $file['id'] ?>">
            <div class="db-file-thumb">
              <?php if ($isImage): ?>
                <img src="<?= htmlspecialchars($file['public_url']) ?>"
                     alt="<?= htmlspecialchars($file['filename']) ?>"
                     loading="lazy" />
              <?php else: ?>
                <span class="db-file-icon"><?= $icon ?></span>
              <?php endif; ?>
            </div>
            <div class="db-file-body">
              <div class="db-file-name" title="<?= htmlspecialchars($file['filename']) ?>">
                <?= htmlspecialchars($file['filename']) ?>
              </div>
              <div class="db-file-meta">
                <?= phpFormatSize((int)$file['file_size']) ?>
                &nbsp;·&nbsp;
                <?= date('M j, Y', strtotime($file['created_at'])) ?>
              </div>
              <div class="db-file-url"><?= htmlspecialchars($file['public_url']) ?></div>
            </div>
            <div class="db-file-actions">
              <button class="btn btn-sm btn-outline copy-db-btn"
                      data-url="<?= htmlspecialchars($file['public_url']) ?>">
                Copy link
              </button>
              <button class="btn btn-sm btn-outline rename-db-btn"
                      data-id="<?= $file['id'] ?>"
                      data-name="<?= htmlspecialchars($file['filename']) ?>">
                Rename
              </button>
              <button class="btn btn-sm btn-danger delete-db-btn"
                      data-id="<?= $file['id'] ?>"
                      data-key="<?= htmlspecialchars($file['r2_key']) ?>">
                Delete
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <script src="<?= asset('/assets/dashboard.js') ?>"></script>
</body>
</html>
