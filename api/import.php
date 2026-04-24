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

// ── Realistic browser headers that bypass bot checks ──────────────────────────
$browserHeaders = [
    'Accept: */*',
    'Accept-Language: en-US,en;q=0.9',
    'Accept-Encoding: identity',
    'Connection: keep-alive',
];
$browserUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

// ── Probe the URL: try HEAD first, fall back to ranged GET ───────────────────
//    Many download hosts block HEAD or return misleading info.
//    A ranged GET (bytes=0-0) follows all HTTP redirects and reveals
//    the real Content-Type and Content-Length without downloading the file.

function _curl_base(string $url, string $ua, array $hdrs): \CurlHandle|false
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    return $ch;
}

function _is_html(string $ct): bool
{
    return str_contains($ct, 'text/html') || str_contains($ct, 'application/xhtml');
}

// Step 1: HEAD
$ch = _curl_base($url, $browserUA, $browserHeaders);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_exec($ch);
$headStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = trim(preg_replace('/;.*$/', '', curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '')) ?: '';
$contentLength = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
$effectiveUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
$headErr       = curl_error($ch);
curl_close($ch);

// Step 2: fall back to ranged GET if HEAD failed or returned HTML
$usedRangeProbe = false;
if ($headErr || $headStatus < 200 || $headStatus >= 400 || _is_html($contentType) || $contentType === '') {
    $ch = _curl_base($effectiveUrl ?: $url, $browserUA, array_merge($browserHeaders, ['Range: bytes=0-0']));
    $rangeBody = curl_exec($ch);
    $rangeStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $rangeCT      = trim(preg_replace('/;.*$/', '', curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '')) ?: '';
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $effectiveUrl ?: $url;
    $rangeErr     = curl_error($ch);

    // Parse total size from Content-Range: bytes 0-0/TOTAL
    $rangeHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $h) use (&$rangeHeaders) {
        $rangeHeaders[] = $h; return strlen($h);
    });
    curl_close($ch);

    if (!$rangeErr && $rangeStatus >= 200 && $rangeStatus < 400) {
        if ($rangeCT !== '') $contentType   = $rangeCT;
        // Re-probe with header capture to get Content-Range total
        $ch2 = _curl_base($effectiveUrl, $browserUA, array_merge($browserHeaders, ['Range: bytes=0-0']));
        $captured = [];
        curl_setopt($ch2, CURLOPT_HEADERFUNCTION, function ($c, $h) use (&$captured) {
            $captured[] = $h; return strlen($h);
        });
        curl_exec($ch2);
        curl_close($ch2);
        foreach ($captured as $h) {
            // Content-Range: bytes 0-0/12345678
            if (preg_match('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/i', $h, $m)) {
                $contentLength = (int)$m[1];
            }
            if ($contentType === '' && preg_match('/^Content-Type:\s*(.+)/i', $h, $m)) {
                $contentType = trim(preg_replace('/;.*$/', '', $m[1]));
            }
        }
    }
    $usedRangeProbe = true;
}

// If we still have HTML content-type after probing, the URL is a webpage, not a file
if (_is_html($contentType)) {
    http_response_code(422);
    echo json_encode(['error' => 'URL leads to a webpage, not a downloadable file. The link may have expired or requires a browser session.']);
    exit;
}

// Derive filename: caller-supplied → ?filename= param → URL path
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

// Guess MIME type from extension if server returned generic type
if ($contentType === '' || $contentType === 'application/octet-stream') {
    $extMap = [
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
        'nsp' => 'application/octet-stream', 'xci' => 'application/octet-stream',
        'iso' => 'application/x-iso9660-image', '7z' => 'application/x-7z-compressed',
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
    $dch = _curl_base($effectiveUrl, $browserUA, $browserHeaders);
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

    $dch = _curl_base($effectiveUrl, $browserUA, $browserHeaders);
    curl_setopt($dch, CURLOPT_TIMEOUT, 0);
    curl_setopt($dch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($dch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (
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

    // Upload remaining buffer as final part
    if (!$failed && $buffer !== '') {
        $partNum++;
        $etag = r2_upload_part_server($key, $uploadId, $partNum, $buffer);
        if (!$etag) {
            $failed = true;
            $errMsg = 'R2 final part upload failed';
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
