<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/r2.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body     = json_decode(file_get_contents('php://input'), true);
$key      = trim($body['key']      ?? '');
$uploadId = trim($body['uploadId'] ?? '');

if (!$key || !$uploadId) { http_response_code(400); echo json_encode(['error' => 'Missing key or uploadId']); exit; }

r2_abort_multipart($key, $uploadId);

echo json_encode(['ok' => true]);
