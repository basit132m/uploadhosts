<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDb();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'approve' && $userId) {
        $db->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$userId]);
    } elseif ($action === 'reject' && $userId) {
        $db->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$userId]);
    } elseif ($action === 'delete' && $userId) {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    }

    header('Location: /admin/');
    exit;
}

$pending  = $db->query("SELECT * FROM users WHERE status = 'pending'  ORDER BY created_at DESC")->fetchAll();
$approved = $db->query("SELECT u.*, COUNT(f.id) AS file_count FROM users u
                        LEFT JOIN uploads f ON f.user_id = u.id
                        WHERE u.status = 'approved'
                        GROUP BY u.id ORDER BY u.created_at DESC")->fetchAll();
$rejected = $db->query("SELECT * FROM users WHERE status = 'rejected' ORDER BY created_at DESC")->fetchAll();

$totalFiles = (int)$db->query("SELECT COUNT(*) FROM uploads")->fetchColumn();
$totalSize  = (int)$db->query("SELECT COALESCE(SUM(file_size),0) FROM uploads")->fetchColumn();

function fmtSize(int $b): string {
    if ($b < 1048576)    return round($b/1024,1).' KB';
    if ($b < 1073741824) return round($b/1048576,1).' MB';
    return round($b/1073741824,2).' GB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Panel — UploadHost</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>☁️</text></svg>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/style.css" />
</head>
<body>

  <?php require __DIR__ . '/../includes/nav.php'; ?>

  <main class="main-content">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-value"><?= count($pending) ?></div>
        <div class="stat-label">Pending</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= count($approved) ?></div>
        <div class="stat-label">Active users</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= $totalFiles ?></div>
        <div class="stat-label">Total files</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= fmtSize($totalSize) ?></div>
        <div class="stat-label">Storage used</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn active" data-tab="pending">
        Pending requests
        <?php if (count($pending)): ?>
          <span class="tab-badge"><?= count($pending) ?></span>
        <?php endif; ?>
      </button>
      <button class="tab-btn" data-tab="users">Active users</button>
      <button class="tab-btn" data-tab="rejected">Rejected</button>
    </div>

    <!-- Pending -->
    <div class="tab-panel active" id="tab-pending">
      <?php if (empty($pending)): ?>
        <div class="empty-state"><span class="empty-icon">✅</span><p>No pending requests</p></div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr>
              <th>Name</th><th>Password</th><th>Requested</th><th>Actions</th>
            </tr></thead>
            <tbody>
              <?php foreach ($pending as $u): ?>
                <tr>
                  <td><?= htmlspecialchars($u['name']) ?></td>
                  <td><code class="pwd-cell"><?= htmlspecialchars($u['password']) ?></code></td>
                  <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                  <td class="action-cell">
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                    </form>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button name="action" value="reject" class="btn btn-sm btn-danger">Reject</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Active users -->
    <div class="tab-panel" id="tab-users">
      <?php if (empty($approved)): ?>
        <div class="empty-state"><span class="empty-icon">👥</span><p>No active users yet</p></div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr>
              <th>Name</th><th>Password</th><th>Files</th><th>Joined</th><th>Actions</th>
            </tr></thead>
            <tbody>
              <?php foreach ($approved as $u): ?>
                <tr>
                  <td><?= htmlspecialchars($u['name']) ?></td>
                  <td><code class="pwd-cell"><?= htmlspecialchars($u['password']) ?></code></td>
                  <td><?= $u['file_count'] ?></td>
                  <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                  <td class="action-cell">
                    <form method="POST"
                          onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($u['name'])) ?> and all their files?')">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button name="action" value="delete" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Rejected -->
    <div class="tab-panel" id="tab-rejected">
      <?php if (empty($rejected)): ?>
        <div class="empty-state"><span class="empty-icon">🚫</span><p>No rejected users</p></div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr>
              <th>Name</th><th>Password</th><th>Requested</th><th>Actions</th>
            </tr></thead>
            <tbody>
              <?php foreach ($rejected as $u): ?>
                <tr>
                  <td><?= htmlspecialchars($u['name']) ?></td>
                  <td><code class="pwd-cell"><?= htmlspecialchars($u['password']) ?></code></td>
                  <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                  <td class="action-cell">
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                    </form>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                      <button name="action" value="delete" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>

  <script>
    document.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn, .tab-panel').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
      });
    });
  </script>

</body>
</html>
