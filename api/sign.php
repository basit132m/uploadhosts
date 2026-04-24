<?php
/**
 * Generates an AWS Signature V4 presigned PUT URL for Cloudflare R2.
 * The browser uses this URL to upload a file directly to R2 — zero file
 * bytes pass through this server.
 */

require_once __DIR__ . '/../config.php';

// ── CORS ─────────────────────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === ALLOWED_ORIGIN || $origin === 'http://localhost' || (defined('WP_DEBUG') && WP_DEBUG)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Parse request ─────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$filename  = $body['filename']  ?? '';
$mimeType  = $body['mimeType']  ?? 'application/octet-stream';
$fileSize  = (int)($body['fileSize'] ?? 0);

if (!$filename) {
    http_response_code(400);
    echo json_encode(['error' => 'filename is required']);
    exit;
}

$maxBytes = MAX_FILE_SIZE_MB * 1024 * 1024;
if ($fileSize > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'File exceeds ' . MAX_FILE_SIZE_MB . ' MB limit']);
    exit;
}

// ── Build a unique object key ─────────────────────────────────────────────────
$ext      = pathinfo($filename, PATHINFO_EXTENSION);
$safe     = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($filename, PATHINFO_FILENAME));
$safe     = substr($safe, 0, 60);
$key      = date('Y/m/d') . '/' . bin2hex(random_bytes(8)) . '_' . $safe . ($ext ? '.' . $ext : '');

// ── Generate presigned PUT URL ────────────────────────────────────────────────
$url = r2_presigned_put($key, $mimeType);

echo json_encode([
    'uploadUrl' => $url,
    'publicUrl' => rtrim(R2_PUBLIC_BASE_URL, '/') . '/' . $key,
    'key'       => $key,
]);

// ═════════════════════════════════════════════════════════════════════════════
// AWS Signature V4 presigned URL — pure PHP, no dependencies
// ═════════════════════════════════════════════════════════════════════════════

function r2_presigned_put(string $key, string $contentType): string
{
    $accountId  = R2_ACCOUNT_ID;
    $accessKey  = R2_ACCESS_KEY_ID;
    $secretKey  = R2_SECRET_KEY;
    $bucket     = R2_BUCKET;
    $region     = 'auto';            // R2 always uses "auto"
    $expires    = PRESIGN_EXPIRES_SEC;

    $host       = "{$accountId}.r2.cloudflarestorage.com";
    $datetime   = gmdate('Ymd\THis\Z');
    $date       = gmdate('Ymd');

    // URL-encode each path segment individually
    $encodedKey    = implode('/', array_map('rawurlencode', explode('/', $key)));
    $encodedBucket = rawurlencode($bucket);
    $uri           = "/{$encodedBucket}/{$encodedKey}";

    // ── Canonical query string (alphabetical) ─────────────────────────────────
    $credentialScope = "{$date}/{$region}/s3/aws4_request";
    $queryParams = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "{$accessKey}/{$credentialScope}",
        'X-Amz-Date'          => $datetime,
        'X-Amz-Expires'       => (string)$expires,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($queryParams);
    $canonicalQuery = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

    // ── Canonical request ─────────────────────────────────────────────────────
    $canonicalHeaders = "host:{$host}\n";
    $signedHeaders    = 'host';

    $canonicalRequest = implode("\n", [
        'PUT',
        $uri,
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        'UNSIGNED-PAYLOAD',      // R2 supports unsigned payload for presigned URLs
    ]);

    // ── String to sign ────────────────────────────────────────────────────────
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $datetime,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    // ── Signing key (HMAC chain) ──────────────────────────────────────────────
    $kDate    = hash_hmac('sha256', $date,           'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,         $kDate,              true);
    $kService = hash_hmac('sha256', 's3',            $kRegion,            true);
    $kSigning = hash_hmac('sha256', 'aws4_request',  $kService,           true);
    $signature = hash_hmac('sha256', $stringToSign,  $kSigning);

    return "https://{$host}{$uri}?{$canonicalQuery}&X-Amz-Signature={$signature}";
}
