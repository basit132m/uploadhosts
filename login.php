<?php
require_once __DIR__ . '/includes/auth.php';

if (currentUser()) {
    header('Location: /');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password'] ?? '');
    if (!$password) {
        $error = 'Please enter your password.';
    } elseif (!loginByPassword($password)) {
        $error = 'Invalid password or account not approved yet.';
    } else {
        header('Location: /');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login — UploadHost</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>☁️</text></svg>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= asset('/assets/style.css') ?>" />
</head>
<body class="auth-page">

  <div class="auth-card">
    <div class="auth-logo">☁️</div>
    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-sub">Enter your password to continue</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="form-input"
               placeholder="Enter your password" autofocus required />
      </div>
      <button type="submit" class="btn btn-primary btn-full">Login</button>
    </form>

    <p class="auth-footer">Don't have an account? <a href="/register.php">Request access</a></p>
  </div>

</body>
</html>
