<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentUser(): ?array
{
    return $_SESSION['uh_user'] ?? null;
}

function isAdmin(): bool
{
    return ($_SESSION['uh_user']['role'] ?? '') === 'admin';
}

function requireAuth(): void
{
    if (!currentUser()) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin(): void
{
    requireAuth();
    if (!isAdmin()) {
        header('Location: /');
        exit;
    }
}

function loginByPassword(string $password): bool
{
    // Admin password check (from config.php)
    if (defined('ADMIN_PASSWORD') && $password === ADMIN_PASSWORD) {
        $_SESSION['uh_user'] = ['id' => 0, 'name' => 'Admin', 'role' => 'admin'];
        return true;
    }

    $db   = getDb();
    $stmt = $db->prepare("SELECT id, name, role FROM users WHERE password = ? AND status = 'approved'");
    $stmt->execute([$password]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['uh_user'] = ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']];
        return true;
    }

    return false;
}

function logout(): void
{
    session_destroy();
    header('Location: /login.php');
    exit;
}
