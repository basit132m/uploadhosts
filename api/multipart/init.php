<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/r2.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body     = json_decode(file_get_contents('php://input'), true);
$filename = trim($body['filename'] ?? '');
$mimeType = trim($body['mimeType'] ?? 'application/octet-stream');
$fileSize = (int)($body['fileSize'] ?? 0);

if (!$filename) { http_response_code(400); echo json_encode(['error' => 'filename required']); exit; }

$maxBytes = MAX_FILE_SIZE_MB * 1024 * 1024;
if ($fileSize > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'File exceeds ' . MAX_FILE_SIZE_MB . ' MB limit']);
    exit;
}

// Generate unique key
$ext  = pathinfo($filename, PATHINFO_EXTENSION);
$safe = substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($filename, PATHINFO_FILENAME)), 0, 60);
$key  = date('Y/m/d') . '/' . bin2hex(random_bytes(8)) . '_' . $safe . ($ext ? '.' . $ext : '');

$uploadId = r2_create_multipart($key, $mimeType);

if (!$uploadId) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to initiate upload on R2']);
    exit;
}

echo json_encode([
    'uploadId'  => $uploadId,
    'key'       => $key,
    'publicUrl' => rtrim(R2_PUBLIC_BASE_URL, '/') . '/' . $key,
]);
