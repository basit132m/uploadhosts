<?php
require_once __DIR__ . '/includes/auth.php';

if (currentUser()) {
    header('Location: /');
    exit;
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$name || !$password || !$confirm) {
        $error = 'All fields are required.';
    } elseif (strlen($name) < 2) {
        $error = 'Name must be at least 2 characters.';
    } elseif (strlen($password) < 4) {
        $error = 'Password must be at least 4 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $db   = getDb();
            $stmt = $db->prepare("INSERT INTO users (name, password) VALUES (?, ?)");
            $stmt->execute([$name, $password]);
            $success = true;
        } catch (PDOException $e) {
            // UNIQUE constraint on password
            $error = 'That password is already taken. Please choose a different one.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register — UploadHost</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>☁️</text></svg>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= asset('/assets/style.css') ?>" />
</head>
<body class="auth-page">

  <div class="auth-card">
    <div class="auth-logo">☁️</div>
    <h1 class="auth-title">Request access</h1>
    <p class="auth-sub">Your account will be reviewed by an admin</p>

    <?php if ($success): ?>
      <div class="alert alert-success">
        Request submitted! You'll be able to login once an admin approves your account.
      </div>
      <p class="auth-footer"><a href="/login.php">Back to login</a></p>
    <?php else: ?>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label for="name">Your name</label>
          <input type="text" id="name" name="name" class="form-input"
                 placeholder="e.g. Sarah" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                 autofocus required />
        </div>
        <div class="form-group">
          <label for="password">Choose a password</label>
          <input type="password" id="password" name="password" class="form-input"
                 placeholder="Min 4 characters" required />
        </div>
        <div class="form-group">
          <label for="confirm">Confirm password</label>
          <input type="password" id="confirm" name="confirm" class="form-input"
                 placeholder="Repeat password" required />
        </div>
        <button type="submit" class="btn btn-primary btn-full">Request access</button>
      </form>

      <p class="auth-footer">Already have access? <a href="/login.php">Login</a></p>

    <?php endif; ?>
  </div>

</body>
</html>
