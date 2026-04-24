<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!currentUser()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$user   = currentUser();
$db     = getDb();

// ── Save a file record after successful R2 upload ─────────────────────────────
if ($action === 'save') {
    $filename  = trim($body['filename']  ?? '');
    $key       = trim($body['key']       ?? '');
    $publicUrl = trim($body['publicUrl'] ?? '');
    $fileSize  = (int)($body['fileSize'] ?? 0);
    $mimeType  = trim($body['mimeType']  ?? 'application/octet-stream');

    if (!$filename || !$key || !$publicUrl) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    $stmt = $db->prepare("
        INSERT INTO uploads (user_id, filename, r2_key, public_url, file_size, mime_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user['id'], $filename, $key, $publicUrl, $fileSize, $mimeType]);

    echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
    exit;
}

// ── Delete a file from R2 and the database ────────────────────────────────────
if ($action === 'delete') {
    $fileId = (int)($body['id'] ?? 0);

    if (!$fileId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing file id']);
        exit;
    }

    // Fetch the record — users can only delete their own files
    $stmt = $db->prepare("SELECT * FROM uploads WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user['id']]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }

    // Delete from R2
    $deleted = r2_delete($file['r2_key']);

    // Remove DB record regardless (R2 object may already be gone)
    $db->prepare("DELETE FROM uploads WHERE id = ?")->execute([$fileId]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);

// ── R2 signed DELETE request ──────────────────────────────────────────────────
function r2_delete(string $key): bool
{
    $accountId = R2_ACCOUNT_ID;
    $accessKey = R2_ACCESS_KEY_ID;
    $secretKey = R2_SECRET_KEY;
    $bucket    = R2_BUCKET;
    $region    = 'auto';

    $host     = "{$accountId}.r2.cloudflarestorage.com";
    $datetime = gmdate('Ymd\THis\Z');
    $date     = gmdate('Ymd');

    $encodedKey    = implode('/', array_map('rawurlencode', explode('/', $key)));
    $encodedBucket = rawurlencode($bucket);
    $uri           = "/{$encodedBucket}/{$encodedKey}";

    $payloadHash      = hash('sha256', '');
    $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$datetime}\n";
    $signedHeaders    = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = implode("\n", [
        'DELETE', $uri, '',
        $canonicalHeaders, $signedHeaders, $payloadHash,
    ]);

    $credentialScope = "{$date}/{$region}/s3/aws4_request";
    $stringToSign    = implode("\n", [
        'AWS4-HMAC-SHA256', $datetime, $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate    = hash_hmac('sha256', $date,          'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,        $kDate,              true);
    $kService = hash_hmac('sha256', 's3',           $kRegion,            true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService,           true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, "
                   . "SignedHeaders={$signedHeaders}, Signature={$signature}";

    $ch = curl_init("https://{$host}{$uri}");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$authorization}",
            "x-amz-date: {$datetime}",
            "x-amz-content-sha256: {$payloadHash}",
            "Host: {$host}",
        ],
    ]);

    $statusCode = curl_getinfo(($ch2 = $ch), CURLINFO_HTTP_CODE);
    curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $statusCode >= 200 && $statusCode < 300;
}
