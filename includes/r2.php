<?php
/**
 * Cloudflare R2 signing helpers — all R2 API calls go through here.
 * Uses AWS Signature V4, pure PHP, no SDK needed.
 */

require_once __DIR__ . '/../config.php';

function _r2_host(): string
{
    return R2_ACCOUNT_ID . '.r2.cloudflarestorage.com';
}

function _r2_uri(string $key): string
{
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
    return '/' . rawurlencode(R2_BUCKET) . '/' . $encodedKey;
}

function _r2_signing_key(string $date): string
{
    $k1 = hash_hmac('sha256', $date,          'AWS4' . R2_SECRET_KEY, true);
    $k2 = hash_hmac('sha256', 'auto',         $k1,                    true);
    $k3 = hash_hmac('sha256', 's3',           $k2,                    true);
    return hash_hmac('sha256', 'aws4_request', $k3,                    true);
}

/**
 * Execute a signed server-side request to R2 via cURL.
 * Returns [httpStatusCode, responseBody].
 */
function r2_request(string $method, string $key, string $query, string $body = '', string $contentType = ''): array
{
    $host        = _r2_host();
    $uri         = _r2_uri($key);
    $datetime    = gmdate('Ymd\THis\Z');
    $date        = substr($datetime, 0, 8);
    $payloadHash = hash('sha256', $body);

    // Canonical headers must be lowercase and sorted alphabetically
    $canonicalHeaders = '';
    $signedList       = [];

    if ($contentType !== '') {
        $canonicalHeaders .= "content-type:{$contentType}\n";
        $signedList[]      = 'content-type';
    }
    $canonicalHeaders .= "host:{$host}\n";
    $signedList[]      = 'host';
    $canonicalHeaders .= "x-amz-content-sha256:{$payloadHash}\n";
    $signedList[]      = 'x-amz-content-sha256';
    $canonicalHeaders .= "x-amz-date:{$datetime}\n";
    $signedList[]      = 'x-amz-date';

    $signedHeaders = implode(';', $signedList);

    $canonicalRequest = implode("\n", [
        $method, $uri, $query,
        $canonicalHeaders, $signedHeaders, $payloadHash,
    ]);

    $scope        = "{$date}/auto/s3/aws4_request";
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256', $datetime, $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $signature     = hash_hmac('sha256', $stringToSign, _r2_signing_key($date));
    $authorization = 'AWS4-HMAC-SHA256 Credential=' . R2_ACCESS_KEY_ID . "/{$scope}, "
                   . "SignedHeaders={$signedHeaders}, Signature={$signature}";

    $curlHeaders = [
        "Authorization: {$authorization}",
        "Host: {$host}",
        "x-amz-date: {$datetime}",
        "x-amz-content-sha256: {$payloadHash}",
        "Expect:", // suppress curl's 100-continue
    ];
    if ($contentType !== '') $curlHeaders[] = "Content-Type: {$contentType}";

    $url = "https://{$host}{$uri}" . ($query !== '' ? "?{$query}" : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $response   = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$statusCode, (string)$response];
}

// ── Presigned URL helpers (browser uploads directly to R2) ────────────────────

function _r2_presign(string $method, string $key, array $extraParams, int $expires = 3600): string
{
    $host      = _r2_host();
    $uri       = _r2_uri($key);
    $datetime  = gmdate('Ymd\THis\Z');
    $date      = substr($datetime, 0, 8);
    $region    = 'auto';

    $scope = "{$date}/{$region}/s3/aws4_request";
    $params = array_merge([
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => R2_ACCESS_KEY_ID . "/{$scope}",
        'X-Amz-Date'          => $datetime,
        'X-Amz-Expires'       => (string)$expires,
        'X-Amz-SignedHeaders' => 'host',
    ], $extraParams);

    ksort($params);
    $canonicalQuery = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $canonicalRequest = implode("\n", [
        $method, $uri, $canonicalQuery,
        "host:{$host}\n", 'host', 'UNSIGNED-PAYLOAD',
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256', $datetime, $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate    = hash_hmac('sha256', $date,          'AWS4' . R2_SECRET_KEY, true);
    $kRegion  = hash_hmac('sha256', $region,        $kDate,                 true);
    $kService = hash_hmac('sha256', 's3',           $kRegion,               true);
    $kSign    = hash_hmac('sha256', 'aws4_request', $kService,              true);
    $sig      = hash_hmac('sha256', $stringToSign,  $kSign);

    return "https://{$host}{$uri}?{$canonicalQuery}&X-Amz-Signature={$sig}";
}

/** Presigned PUT URL for a single-part upload (files ≤ 10 MB). */
function r2_presign_put(string $key): string
{
    return _r2_presign('PUT', $key, [], PRESIGN_EXPIRES_SEC);
}

/** Presigned PUT URL for a multipart part (UploadPart). */
function r2_presign_part(string $key, string $uploadId, int $partNumber): string
{
    return _r2_presign('PUT', $key, [
        'partNumber' => (string)$partNumber,
        'uploadId'   => $uploadId,
    ], 3600);
}

// ── Multipart upload lifecycle ────────────────────────────────────────────────

/** Initiate a multipart upload, returns uploadId or null on failure. */
function r2_create_multipart(string $key, string $contentType): ?string
{
    // S3 SigV4 requires sub-resources as "key=" (empty value) in canonical query string
    [$status, $body] = r2_request('POST', $key, 'uploads=', '', $contentType);
    if ($status !== 200) return null;

    $xml = @simplexml_load_string($body);
    return $xml ? (string)$xml->UploadId : null;
}

/** Complete a multipart upload — R2 assembles all parts into one object. */
function r2_complete_multipart(string $key, string $uploadId, array $parts): bool
{
    usort($parts, fn($a, $b) => $a['partNumber'] <=> $b['partNumber']);

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
    foreach ($parts as $p) {
        // ETags from R2 include surrounding quotes — include them verbatim
        $xml .= "<Part><PartNumber>{$p['partNumber']}</PartNumber><ETag>{$p['etag']}</ETag></Part>";
    }
    $xml .= '</CompleteMultipartUpload>';

    $encodedId = rawurlencode($uploadId);
    [$status]  = r2_request('POST', $key, "uploadId={$encodedId}", $xml, 'application/xml');
    return $status >= 200 && $status < 300;
}

/** Abort a multipart upload and free partial storage. */
function r2_abort_multipart(string $key, string $uploadId): void
{
    r2_request('DELETE', $key, 'uploadId=' . rawurlencode($uploadId));
}

/** Delete a single object from the bucket. */
function r2_delete_object(string $key): bool
{
    [$status] = r2_request('DELETE', $key, '');
    return $status >= 200 && $status < 300;
}
