<?php
$user = currentUser();
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<nav class="navbar">
  <a class="nav-brand" href="/">☁️ UploadHost</a>
  <div class="nav-links">
    <?php if ($user): ?>
      <a href="/" class="nav-link<?= $currentPath === '/' ? ' active' : '' ?>">Upload</a>
      <a href="/dashboard.php" class="nav-link<?= $currentPath === '/dashboard.php' ? ' active' : '' ?>">My Files</a>
      <?php if (isAdmin()): ?>
        <a href="/admin/" class="nav-link<?= strpos($currentPath, '/admin') === 0 ? ' active' : '' ?>">Admin</a>
      <?php endif; ?>
      <span class="nav-user">
        <span class="nav-avatar"><?= htmlspecialchars(mb_substr($user['name'], 0, 1)) ?></span>
        <?= htmlspecialchars($user['name']) ?>
      </span>
      <a href="/logout.php" class="nav-link nav-logout">Logout</a>
    <?php endif; ?>
  </div>
</nav>
