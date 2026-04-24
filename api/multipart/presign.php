<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/r2.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body       = json_decode(file_get_contents('php://input'), true);
$key        = trim($body['key']        ?? '');
$uploadId   = trim($body['uploadId']   ?? '');
$partNumber = (int)($body['partNumber'] ?? 0);

if (!$key || !$uploadId || $partNumber < 1 || $partNumber > 10000) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

echo json_encode(['url' => r2_presign_part($key, $uploadId, $partNumber)]);
