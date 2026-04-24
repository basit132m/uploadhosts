<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/r2.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body     = json_decode(file_get_contents('php://input'), true);
$key      = trim($body['key']      ?? '');
$uploadId = trim($body['uploadId'] ?? '');
$parts    = $body['parts']    ?? [];
$filename = trim($body['filename'] ?? '');
$fileSize = (int)($body['fileSize'] ?? 0);
$mimeType = trim($body['mimeType'] ?? 'application/octet-stream');

if (!$key || !$uploadId || empty($parts) || !$filename) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// Validate parts structure
foreach ($parts as $p) {
    if (!isset($p['partNumber'], $p['etag']) || (int)$p['partNumber'] < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parts data']);
        exit;
    }
}

$ok = r2_complete_multipart($key, $uploadId, $parts);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'R2 failed to assemble the file']);
    exit;
}

// Save record to database
$user      = currentUser();
$db        = getDb();
$publicUrl = rtrim(R2_PUBLIC_BASE_URL, '/') . '/' . $key;

$db->prepare("INSERT INTO uploads (user_id, filename, r2_key, public_url, file_size, mime_type)
              VALUES (?, ?, ?, ?, ?, ?)")
   ->execute([$user['id'], $filename, $key, $publicUrl, $fileSize, $mimeType]);

echo json_encode(['ok' => true, 'publicUrl' => $publicUrl]);
