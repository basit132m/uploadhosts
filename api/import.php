<?php
// Suppress PHP notices/warnings so they never corrupt our JSON output
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/r2.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

set_time_limit(0);
ignore_user_abort(true);

$body     = json_decode(file_get_contents('php://input'), true);
$url      = trim($body['url']      ?? '');
$filename = trim($body['filename'] ?? '');

if (!$url) { http_response_code(400); echo json_encode(['error' => 'url required']); exit; }

// FILTER_VALIDATE_URL rejects many valid URLs (percent-encoded paths, long tokens, etc.)
// Use a simple scheme+host check instead
$scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
$host   = parse_url($url, PHP_URL_HOST) ?: '';

if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    http_response_code(400); echo json_encode(['error' => 'Invalid URL — must start with http:// or https://']); exit;
}

// Basic SSRF guard — block private/loopback addresses
if (preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|\[::1\])/i', $host)) {
    http_response_code(400); echo json_encode(['error' => 'Private/local URLs are not allowed']); exit;
}

// HEAD the URL to discover Content-Type and Content-Length
$hch = curl_init($url);
curl_setopt_array($hch, [
    CURLOPT_NOBODY         => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; UploadHost/1.0)',
]);
curl_exec($hch);
$contentLength = (int)curl_getinfo($hch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$contentType   = curl_getinfo($hch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
$effectiveUrl  = curl_getinfo($hch, CURLINFO_EFFECTIVE_URL) ?: $url;
curl_close($hch);

// Strip charset suffix from content-type
$contentType = trim(preg_replace('/;.*$/', '', $contentType)) ?: 'application/octet-stream';

// Derive filename from URL path if not provided
if ($filename === '') {
    $path     = urldecode(parse_url($effectiveUrl, PHP_URL_PATH) ?: '');
    $filename = basename($path) ?: 'imported-file';
    $filename = preg_replace('/\?.*$/', '', $filename); // strip stray query noise
}

// Also check the ?filename= query param of the original URL as fallback
if ($filename === 'imported-file' || $filename === '') {
    $qs = [];
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $qs);
    if (!empty($qs['filename'])) $filename = basename($qs['filename']);
}

// Sanitize display filename (keep spaces and common punctuation)
$filename = trim(preg_replace('/[^\w.\- ]/', '_', $filename));
if ($filename === '') $filename = 'imported-file';

// Enforce size limit when Content-Length is known
if ($contentLength > 0) {
    $maxBytes = MAX_FILE_SIZE_MB * 1024 * 1024;
    if ($contentLength > $maxBytes) {
        http_response_code(413);
        echo json_encode(['error' => 'File exceeds ' . MAX_FILE_SIZE_MB . ' MB limit']);
        exit;
    }
}

// Build R2 key
$ext  = pathinfo($filename, PATHINFO_EXTENSION);
$safe = substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($filename, PATHINFO_FILENAME)), 0, 60);
$key  = date('Y/m/d') . '/' . bin2hex(random_bytes(8)) . '_' . $safe . ($ext ? '.' . $ext : '');

$chunkSize = 10 * 1024 * 1024; // 10 MB

// ── Small file: download entirely then PUT ─────────────────────────────────
if ($contentLength > 0 && $contentLength <= $chunkSize) {
    $dch = curl_init($url);
    curl_setopt_array($dch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; UploadHost/1.0)',
    ]);
    $data    = curl_exec($dch);
    $curlErr = curl_error($dch);
    curl_close($dch);

    if ($data === false || $data === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Download failed: ' . ($curlErr ?: 'empty response')]);
        exit;
    }

    [$status] = r2_request('PUT', $key, '', $data, $contentType);
    if ($status < 200 || $status >= 300) {
        http_response_code(500);
        echo json_encode(['error' => "R2 upload failed (HTTP {$status})"]);
        exit;
    }

    $actualSize = strlen($data);
} else {
    // ── Large / unknown-size file: streaming multipart ─────────────────────
    $uploadId = r2_create_multipart($key, $contentType);
    if (!$uploadId) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to start multipart upload on R2']);
        exit;
    }

    $buffer    = '';
    $partNum   = 0;
    $parts     = [];
    $failed    = false;
    $errMsg    = '';
    $totalRead = 0;

    $dch = curl_init($url);
    curl_setopt_array($dch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; UploadHost/1.0)',
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (
            &$buffer, &$parts, &$partNum, &$failed, &$errMsg, &$totalRead,
            $key, $uploadId, $chunkSize
        ) {
            if ($failed) return -1;
            $buffer    .= $chunk;
            $totalRead += strlen($chunk);

            while (strlen($buffer) >= $chunkSize) {
                $partNum++;
                $data   = substr($buffer, 0, $chunkSize);
                $buffer = substr($buffer, $chunkSize);
                $etag   = r2_upload_part_server($key, $uploadId, $partNum, $data);
                if (!$etag) {
                    $failed = true;
                    $errMsg = "Upload failed at part {$partNum}";
                    return -1;
                }
                $parts[] = ['partNumber' => $partNum, 'etag' => $etag];
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($dch);
    $curlErr = curl_error($dch);
    curl_close($dch);

    // Upload any remaining buffer as the final part
    if (!$failed && $buffer !== '') {
        $partNum++;
        $etag = r2_upload_part_server($key, $uploadId, $partNum, $buffer);
        if (!$etag) {
            $failed = true;
            $errMsg = 'Upload failed on final part';
        } else {
            $parts[] = ['partNumber' => $partNum, 'etag' => $etag];
        }
    }

    if ($failed || $curlErr) {
        r2_abort_multipart($key, $uploadId);
        http_response_code(500);
        echo json_encode(['error' => $errMsg ?: "Download error: {$curlErr}"]);
        exit;
    }

    if (empty($parts)) {
        r2_abort_multipart($key, $uploadId);
        http_response_code(500);
        echo json_encode(['error' => 'No data received from URL']);
        exit;
    }

    if (!r2_complete_multipart($key, $uploadId, $parts)) {
        r2_abort_multipart($key, $uploadId);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to assemble file on R2']);
        exit;
    }

    $actualSize = $totalRead;
}

$publicUrl = rtrim(R2_PUBLIC_BASE_URL, '/') . '/' . $key;

// Save to database
$db   = getDb();
$user = currentUser();
$db->prepare(
    "INSERT INTO uploads (user_id, filename, r2_key, public_url, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?)"
)->execute([$user['id'], $filename, $key, $publicUrl, $actualSize, $contentType]);

echo json_encode([
    'ok'        => true,
    'id'        => (int)$db->lastInsertId(),
    'filename'  => $filename,
    'fileSize'  => $actualSize,
    'mimeType'  => $contentType,
    'key'       => $key,
    'publicUrl' => $publicUrl,
]);
