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

// Call R2 directly so we can surface the actual error
[$r2Status, $r2Body] = r2_request('POST', $key, 'uploads', '', $mimeType);

$xml      = $r2Status === 200 ? @simplexml_load_string($r2Body) : false;
$uploadId = $xml ? (string)$xml->UploadId : null;

if (!$uploadId) {
    http_response_code(500);
    $detail = $r2Status === 0
        ? 'cURL failed — outbound requests may be blocked on this host'
        : "R2 returned HTTP {$r2Status}: " . substr(strip_tags($r2Body), 0, 300);
    echo json_encode(['error' => $detail]);
    exit;
}

echo json_encode([
    'uploadId'  => $uploadId,
    'key'       => $key,
    'publicUrl' => rtrim(R2_PUBLIC_BASE_URL, '/') . '/' . $key,
]);
