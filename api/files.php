<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/r2.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$user   = currentUser();
$db     = getDb();

// ── Save file record after a successful upload ────────────────────────────────
if ($action === 'save') {
    $filename  = trim($body['filename']  ?? '');
    $key       = trim($body['key']       ?? '');
    $publicUrl = trim($body['publicUrl'] ?? '');
    $fileSize  = (int)($body['fileSize'] ?? 0);
    $mimeType  = trim($body['mimeType']  ?? 'application/octet-stream');

    if (!$filename || !$key || !$publicUrl) {
        http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit;
    }

    $db->prepare("INSERT INTO uploads (user_id, filename, r2_key, public_url, file_size, mime_type)
                  VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$user['id'], $filename, $key, $publicUrl, $fileSize, $mimeType]);

    echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
    exit;
}

// ── Delete a file from R2 and the database ────────────────────────────────────
if ($action === 'delete') {
    $fileId = (int)($body['id'] ?? 0);
    if (!$fileId) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

    $stmt = $db->prepare("SELECT * FROM uploads WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user['id']]);
    $file = $stmt->fetch();

    if (!$file) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    r2_delete_object($file['r2_key']);
    $db->prepare("DELETE FROM uploads WHERE id = ?")->execute([$fileId]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Rename a file (display name only, R2 key unchanged) ──────────────────────
if ($action === 'rename') {
    $fileId  = (int)($body['id']       ?? 0);
    $newName = trim($body['filename']  ?? '');

    if (!$fileId || $newName === '') {
        http_response_code(400); echo json_encode(['error' => 'Missing id or filename']); exit;
    }

    $newName = trim(preg_replace('/[^\w.\- ]/', '_', $newName));
    if ($newName === '') {
        http_response_code(400); echo json_encode(['error' => 'Invalid filename']); exit;
    }

    $stmt = $db->prepare("SELECT id FROM uploads WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user['id']]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $db->prepare("UPDATE uploads SET filename = ? WHERE id = ? AND user_id = ?")->execute([$newName, $fileId, $user['id']]);
    echo json_encode(['ok' => true, 'filename' => $newName]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
