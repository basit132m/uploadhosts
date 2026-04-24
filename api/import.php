<?php
// Guarantee a JSON response even if PHP hits a fatal error
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        ob_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'PHP fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']]);
    } else {
        ob_end_flush();
    }
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/r2.php';

ob_clean();
header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

@set_time_limit(0);
@ignore_user_abort(true);

$body     = json_decode(file_get_contents('php://input'), true);
$url      = trim($body['url']      ?? '');
$filename = trim($body['filename'] ?? '');

if (!$url) { http_response_code(400); echo json_encode(['error' => 'url required']); exit; }

$scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
$host   = parse_url($url, PHP_URL_HOST) ?: '';

if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    http_response_code(400); echo json_encode(['error' => 'Invalid URL — must start with http:// or https://']); exit;
}

if (preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|\[::1\])/i', $host)) {
    http_response_code(400); echo json_encode(['error' => 'Private/local URLs are not allowed']); exit;
}

// ── Browser-like cURL options ─────────────────────────────────────────────────
$browserUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$browserHdrs = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Accept-Encoding: identity',
    'Connection: keep-alive',
];

function _mk_curl(string $url, string $ua, array $hdrs, int $timeout = 30)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    return $ch;
}

// Check body content — catches Cloudflare regardless of Content-Type header
function _body_is_html(string $body): bool
{
    $s = ltrim($body);
    return stripos($s, '<!DOCTYPE html') !== false
        || stripos($s, '<html')          !== false;
}

function _body_is_cloudflare(string $body): bool
{
    return stripos($body, 'cloudflare')         !== false
        || stripos($body, 'DDoS protection')    !== false
        || stripos($body, 'cf-ray')             !== false
        || stripos($body, 'just a moment')      !== false
        || stripos($body, 'challenge-platform') !== false;
}

function _ct_is_html(string $ct): bool
{
    return strpos($ct, 'text/html') !== false || strpos($ct, 'application/xhtml') !== false;
}

// ── Probe: HEAD then ranged GET to get Content-Type, size, and a body sample ──
// We fetch the first 4 KB so we can inspect the body for HTML/Cloudflare even
// when the server sends a misleading Content-Type header.
$probe = '';          // body sample
$contentType   = '';
$contentLength = 0;
$effectiveUrl  = $url;

// Step 1: HEAD (fast)
$ch = _mk_curl($url, $browserUA, $browserHdrs);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_exec($ch);
$headStatus    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType   = trim(preg_replace('/;.*$/', '', curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ''));
$contentLength = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$effectiveUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
$headErr       = curl_error($ch);
curl_close($ch);

// Step 2: small GET to capture body sample + response headers
// (always run this — HEAD alone can't tell us if the body is HTML)
$capturedHdrs = [];
$ch = _mk_curl($effectiveUrl, $browserUA, array_merge($browserHdrs, ['Range: bytes=0-4095']));
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $h) use (&$capturedHdrs) {
    $capturedHdrs[] = $h;
    return strlen($h);
});
$probe        = (string)curl_exec($ch);
$probeStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$probeCT      = trim(preg_replace('/;.*$/', '', curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ''));
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $effectiveUrl;
$probeErr     = curl_error($ch);
curl_close($ch);

// Prefer probe results (more accurate than HEAD)
if ($probeCT !== '') $contentType = $probeCT;

// Extract total size from Content-Range: bytes 0-4095/TOTAL
foreach ($capturedHdrs as $h) {
    if (preg_match('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/i', $h, $m)) {
        $contentLength = (int)$m[1];
        break;
    }
}

// If range not supported, try Content-Length from probe response
if ($contentLength <= 0) {
    foreach ($capturedHdrs as $h) {
        if (preg_match('/^Content-Length:\s*(\d+)/i', $h, $m)) {
            $contentLength = (int)$m[1];
            break;
        }
    }
}

if ($probeErr && $headErr) {
    http_response_code(502);
    echo json_encode(['error' => 'Cannot reach URL: ' . ($probeErr ?: $headErr)]);
    exit;
}

// ── HTML / Cloudflare detection ───────────────────────────────────────────────
// Check BOTH the Content-Type header AND the actual body content.
// Cloudflare sometimes sends challenge pages with non-HTML content-type.
if (_ct_is_html($contentType) || _body_is_html($probe)) {
    http_response_code(422);
    if (_body_is_cloudflare($probe)) {
        echo json_encode(['error' =>
            'Cloudflare is blocking the download from our server. ' .
            'The site requires a real browser (JavaScript challenge). ' .
            'Download the file in Chrome and upload it directly instead.'
        ]);
    } else {
        echo json_encode(['error' =>
            'URL leads to a webpage, not a downloadable file. ' .
            'The link may have expired or require a browser login session.'
        ]);
    }
    exit;
}

// ── Derive filename ───────────────────────────────────────────────────────────
if ($filename === '') {
    $qs = [];
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $qs);
    $filename = isset($qs['filename']) ? basename(urldecode($qs['filename'])) : '';
}
if ($filename === '') {
    $path     = urldecode(parse_url($effectiveUrl, PHP_URL_PATH) ?: '');
    $filename = basename($path) ?: 'imported-file';
    $filename = preg_replace('/\?.*$/', '', $filename);
}
$filename = trim(preg_replace('/[^\w.\- ]/', '_', $filename));
if ($filename === '') $filename = 'imported-file';

// Guess MIME from extension when server gives generic type
if ($contentType === '' || $contentType === 'application/octet-stream') {
    $extMap = [
        'zip' => 'application/zip',      'rar' => 'application/vnd.rar',
        '7z'  => 'application/x-7z-compressed',
        'iso' => 'application/x-iso9660-image',
        'nsp' => 'application/octet-stream', 'xci' => 'application/octet-stream',
        'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska',
        'pdf' => 'application/pdf',
    ];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $contentType = $extMap[$ext] ?? 'application/octet-stream';
}

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

// ── Small file (known size ≤ 10 MB): single PUT ────────────────────────────
if ($contentLength > 0 && $contentLength <= $chunkSize) {
    $dch     = _mk_curl($effectiveUrl, $browserUA, $browserHdrs, 300);
    $data    = curl_exec($dch);
    $curlErr = curl_error($dch);
    curl_close($dch);

    if ($data === false || $data === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Download failed: ' . ($curlErr ?: 'empty response')]);
        exit;
    }

    // Body check again on the actual download
    if (_body_is_html($data)) {
        http_response_code(422);
        echo json_encode(['error' => _body_is_cloudflare($data)
            ? 'Cloudflare blocked the download from our server. Download the file in Chrome and upload it directly.'
            : 'Downloaded content is an HTML page, not a file.'
        ]);
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
    // ── Large / unknown-size: streaming multipart ──────────────────────────
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
    $firstChunk = true;

    $dch = _mk_curl($effectiveUrl, $browserUA, $browserHdrs, 0);
    curl_setopt($dch, CURLOPT_TIMEOUT, 0);
    curl_setopt($dch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($dch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (
        &$buffer, &$parts, &$partNum, &$failed, &$errMsg, &$totalRead, &$firstChunk,
        $key, $uploadId, $chunkSize
    ) {
        if ($failed) return -1;

        // Check the very first bytes for HTML/Cloudflare
        if ($firstChunk) {
            $firstChunk = false;
            if (_body_is_html($chunk)) {
                $failed = true;
                $errMsg = _body_is_cloudflare($chunk)
                    ? 'Cloudflare blocked the download from our server. Download the file in Chrome and upload it directly.'
                    : 'Server returned an HTML page instead of the file.';
                return -1;
            }
        }

        $buffer    .= $chunk;
        $totalRead += strlen($chunk);

        while (strlen($buffer) >= $chunkSize) {
            $partNum++;
            $data   = substr($buffer, 0, $chunkSize);
            $buffer = substr($buffer, $chunkSize);
            $etag   = r2_upload_part_server($key, $uploadId, $partNum, $data);
            if (!$etag) {
                $failed = true;
                $errMsg = "R2 part {$partNum} upload failed";
                return -1;
            }
            $parts[] = ['partNumber' => $partNum, 'etag' => $etag];
        }
        return strlen($chunk);
    });

    curl_exec($dch);
    $curlErr = curl_error($dch);
    curl_close($dch);

    if ($failed || $curlErr) {
        r2_abort_multipart($key, $uploadId);
        http_response_code($failed ? 422 : 500);
        echo json_encode(['error' => $errMsg ?: "Download error: {$curlErr}"]);
        exit;
    }

    // Upload remaining buffer as final part
    if ($buffer !== '') {
        $partNum++;
        $etag = r2_upload_part_server($key, $uploadId, $partNum, $buffer);
        if (!$etag) {
            r2_abort_multipart($key, $uploadId);
            http_response_code(500);
            echo json_encode(['error' => 'R2 final part upload failed']);
            exit;
        }
        $parts[] = ['partNumber' => $partNum, 'etag' => $etag];
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
        echo json_encode(['error' => 'Failed to assemble parts on R2']);
        exit;
    }

    $actualSize = $totalRead;
}

$publicUrl = rtrim(R2_PUBLIC_BASE_URL, '/') . '/' . $key;

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
