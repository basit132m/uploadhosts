<?php
function getDb(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    // Store DB one level above public_html — never web-accessible
    $dbPath = dirname(dirname(__DIR__)) . '/uploadhost.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            password   TEXT    NOT NULL UNIQUE,
            status     TEXT    NOT NULL DEFAULT 'pending',
            role       TEXT    NOT NULL DEFAULT 'user',
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS uploads (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            filename   TEXT    NOT NULL,
            r2_key     TEXT    NOT NULL,
            public_url TEXT    NOT NULL,
            file_size  INTEGER NOT NULL DEFAULT 0,
            mime_type  TEXT    NOT NULL DEFAULT 'application/octet-stream',
            created_at TEXT    NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    // Virtual admin row (id=0) — satisfies FK for uploads owned by the admin account.
    // Password sentinel can never be submitted through the login form.
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, password, status, role)
                VALUES (0, 'Admin', '__system_admin__', 'approved', 'admin')");

    return $pdo;
}
