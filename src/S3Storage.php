<?php

namespace WPStow;

use WPStow\StorageInterface;
use WPStow\MediaHandler;
use WPStow\Utils;

class S3Storage extends StorageInterface
{
    public static function getConfig()
    {
        return [
            'endpoint' => MediaHandler::config('s3_endpoint'),
            'access_key' => MediaHandler::config('s3_access_key'),
            'secret_key' => MediaHandler::config('s3_secret_key'),
            'bucket' => MediaHandler::config('s3_bucket'),
            'region' => MediaHandler::config('s3_region') ?: 'us-east-1',
            'path_style' => MediaHandler::config('s3_path_style') === 'yes',
            'custom_url' => MediaHandler::config('s3_custom_url'),
        ];
    }

    public static function upload($filepath, $cloudKey = null)
    {
        $config = static::getConfig();

        if (!self::isCompleteConfig($config)) {
            return ['status' => false, 'message' => '对象存储配置不完整或 Endpoint 无效'];
        }

        if (!file_exists($filepath)) {
            return ['status' => false, 'message' => '文件不存在'];
        }

        if ($cloudKey === null) {
            $cloudKey = self::getCloudKey($filepath);
        }

        $mimetype = self::getMimeType($filepath);
        $filesize = filesize($filepath);
        $payloadHash = hash_file('sha256', $filepath);
        if ($filesize === false || $payloadHash === false) {
            return ['status' => false, 'message' => '无法读取待上传文件'];
        }

        Utils::writeLog('S3上传配置: ' . json_encode([
            'endpoint' => $config['endpoint'],
            'bucket' => $config['bucket'],
            'region' => $config['region'],
            'path_style' => $config['path_style'],
            'cloudKey' => $cloudKey,
            'mimetype' => $mimetype,
            'filesize' => $filesize
        ]));

        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');

        $url = self::buildUrl($config, $cloudKey);
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPath($config, $cloudKey);

        $canonicalRequest = self::createCanonicalRequest('PUT', $path, $host, '', '', $payloadHash, $date);
        $stringToSign = self::createStringToSign($date, $shortDate, $config['region'], $canonicalRequest);
        $signature = self::calculateSignature($config['secret_key'], $shortDate, $config['region'], $stringToSign);

        $authorization = 'AWS4-HMAC-SHA256 ' .
            'Credential=' . $config['access_key'] . '/' . $shortDate . '/' . $config['region'] . '/s3/aws4_request, ' .
            'SignedHeaders=host;x-amz-content-sha256;x-amz-date, ' .
            'Signature=' . $signature;

        $headers = [
            'Host: ' . $host,
            'Content-Type: ' . $mimetype,
            'Authorization: ' . $authorization,
            'Content-Length: ' . $filesize,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $date
        ];

        Utils::writeLog('S3请求URL: ' . $url);
        Utils::writeLog('S3签名路径: ' . $path);
        Utils::writeLog('S3请求已签名，Host=' . $host . ', Content-Type=' . $mimetype);

        $stream = fopen($filepath, 'rb');
        if ($stream === false) {
            return ['status' => false, 'message' => '无法打开待上传文件'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $stream);
        curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($stream);

        Utils::writeLog('S3响应码: ' . $httpCode);
        if ($httpCode < 200 || $httpCode >= 300) {
            Utils::writeLog('S3错误响应: ' . substr((string) $response, 0, 1000));
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $publicUrl = self::getPublicUrl($config, $cloudKey);
            return [
                'status' => true,
                'data' => [
                    'url' => $publicUrl,
                    'key' => $cloudKey,
                    'name' => basename($cloudKey),
                    'mimetype' => $mimetype,
                    'pathname' => $cloudKey
                ]
            ];
        }

        return [
            'status' => false,
            'message' => '上传失败: ' . ($error ?: "HTTP $httpCode"),
            'response' => $response
        ];
    }

    public static function delete($key)
    {
        $response = self::signedApiRequest('DELETE', $key);
        return !empty($response['status']) || (int) ($response['http_code'] ?? 0) === 404;
    }

    public static function testConnection()
    {
        $config = static::getConfig();

        if (!self::isCompleteConfig($config)) {
            return ['status' => false, 'message' => '请填写完整且有效的S3配置信息'];
        }

        $response = self::signedApiRequest('GET', '');
        if (!empty($response['status'])) {
            return ['status' => true, 'message' => 'S3连接成功'];
        }

        return ['status' => false, 'message' => 'S3连接失败: ' . ($response['message'] ?? '未知错误')];
    }

    public static function getCloudUrl($key)
    {
        $config = static::getConfig();
        return self::getPublicUrl($config, $key);
    }

    public static function download($key)
    {
        $config = static::getConfig();

        if (!self::isCompleteConfig($config)) {
            return ['status' => false, 'http_code' => 0, 'message' => '对象存储配置不完整或 Endpoint 无效'];
        }

        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');

        $url = self::buildUrl($config, $key);
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPath($config, $key);

        $rangeHeader = self::getRequestedRange();

        $canonicalRequest = self::createCanonicalRequest('GET', $path, $host, '', $rangeHeader, null, $date);
        $stringToSign = self::createStringToSign($date, $shortDate, $config['region'], $canonicalRequest);
        $signature = self::calculateSignature($config['secret_key'], $shortDate, $config['region'], $stringToSign);

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        if ($rangeHeader) {
            $signedHeaders = 'host;range;x-amz-content-sha256;x-amz-date';
        }

        $authorization = 'AWS4-HMAC-SHA256 ' .
            'Credential=' . $config['access_key'] . '/' . $shortDate . '/' . $config['region'] . '/s3/aws4_request, ' .
            'SignedHeaders=' . $signedHeaders . ', ' .
            'Signature=' . $signature;

        $headers = [
            'Host: ' . $host,
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . hash('sha256', ''),
            'x-amz-date: ' . $date,
        ];

        if ($rangeHeader) {
            $headers[] = 'Range: ' . $rangeHeader;
        }

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $key = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                if (in_array($key, ['content-type', 'content-length', 'content-range', 'cache-control', 'etag', 'last-modified', 'accept-ranges'])) {
                    $responseHeaders[$key] = $value;
                }
            }
            return $len;
        });

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'status' => true,
                'data' => $data,
                'http_code' => $httpCode,
                'headers' => $responseHeaders
            ];
        }

        return [
            'status' => false,
            'http_code' => $httpCode,
            'message' => $error ?: "HTTP $httpCode"
        ];
    }

    /**
     * Create a short-lived SigV4 URL without exposing storage credentials.
     */
    public static function createPresignedRequestUrl($method, $key, array $query = [], $ttl = 900, array $requestHeaders = [])
    {
        $config = static::getConfig();
        if (!self::isCompleteConfig($config)) {
            return '';
        }

        $method = strtoupper((string) $method);
        $ttl = min(3600, max(60, (int) $ttl));
        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        $scope = $shortDate . '/' . $config['region'] . '/s3/aws4_request';
        $url = self::buildUrl($config, $key);
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPath($config, $key);

        $canonicalHeaders = ['host' => $host];
        if (isset($requestHeaders['Content-Type']) || isset($requestHeaders['content-type'])) {
            $contentType = $requestHeaders['Content-Type'] ?? $requestHeaders['content-type'];
            $canonicalHeaders['content-type'] = self::sanitizeMimeType($contentType);
        }
        ksort($canonicalHeaders, SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));
        $canonicalHeaderBlock = '';
        foreach ($canonicalHeaders as $name => $value) {
            $canonicalHeaderBlock .= $name . ':' . trim((string) preg_replace('/\s+/', ' ', $value)) . "\n";
        }

        $query = array_merge($query, [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $config['access_key'] . '/' . $scope,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $ttl,
            'X-Amz-SignedHeaders' => $signedHeaders,
        ]);
        $canonicalQuery = self::buildCanonicalQuery($query);
        $canonicalRequest = $method . "\n" .
            $path . "\n" .
            $canonicalQuery . "\n" .
            $canonicalHeaderBlock . "\n" .
            $signedHeaders . "\n" .
            'UNSIGNED-PAYLOAD';
        $stringToSign = "AWS4-HMAC-SHA256\n" .
            $date . "\n" .
            $scope . "\n" .
            hash('sha256', $canonicalRequest);
        $query['X-Amz-Signature'] = self::calculateSignature(
            $config['secret_key'],
            $shortDate,
            $config['region'],
            $stringToSign
        );

        return $url . '?' . self::buildCanonicalQuery($query);
    }

    public static function createMultipartUpload($key, $mimetype)
    {
        $response = self::signedApiRequest('POST', $key, ['uploads' => ''], '', [
            'Content-Type: ' . self::sanitizeMimeType($mimetype),
        ]);
        if (empty($response['status'])) {
            return $response;
        }

        $uploadId = '';
        if (preg_match('/<UploadId>(.*?)<\/UploadId>/s', (string) $response['body'], $matches)) {
            $uploadId = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        if ($uploadId === '') {
            return ['status' => false, 'message' => '对象存储未返回 UploadId'];
        }

        return ['status' => true, 'upload_id' => $uploadId];
    }

    public static function createMultipartPartUrl($key, $uploadId, $partNumber, $ttl = 900)
    {
        return static::createPresignedRequestUrl('PUT', $key, [
            'partNumber' => (string) (int) $partNumber,
            'uploadId' => (string) $uploadId,
        ], $ttl);
    }

    public static function completeMultipartUpload($key, $uploadId, array $parts)
    {
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $part) {
            $number = (int) ($part['part_number'] ?? 0);
            $etag = trim((string) ($part['etag'] ?? ''), "\" \t\r\n");
            if ($number < 1 || $etag === '') {
                return ['status' => false, 'message' => '分片清单无效'];
            }
            $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>&quot;'
                . htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '&quot;</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        return self::signedApiRequest('POST', $key, ['uploadId' => (string) $uploadId], $xml, [
            'Content-Type: application/xml',
        ]);
    }

    public static function abortMultipartUpload($key, $uploadId)
    {
        $response = self::signedApiRequest('DELETE', $key, ['uploadId' => (string) $uploadId]);
        return !empty($response['status']) || (int) ($response['http_code'] ?? 0) === 404;
    }

    public static function headObject($key)
    {
        return self::signedApiRequest('HEAD', $key);
    }

    private static function signedApiRequest($method, $key, array $query = [], $body = '', array $extraHeaders = [])
    {
        $config = static::getConfig();
        if (!self::isCompleteConfig($config)) {
            return ['status' => false, 'message' => '对象存储配置不完整'];
        }

        $method = strtoupper((string) $method);
        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);
        $baseUrl = self::buildUrl($config, $key);
        $canonicalQuery = self::buildCanonicalQuery($query);
        $url = $baseUrl . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        $host = self::buildCanonicalHost($baseUrl);
        $path = self::buildCanonicalPath($config, $key);
        $canonicalHeaders = "host:$host\n" .
            "x-amz-content-sha256:$payloadHash\n" .
            "x-amz-date:$date\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = $method . "\n" .
            $path . "\n" .
            $canonicalQuery . "\n" .
            $canonicalHeaders . "\n" .
            $signedHeaders . "\n" .
            $payloadHash;
        $stringToSign = self::createStringToSign($date, $shortDate, $config['region'], $canonicalRequest);
        $signature = self::calculateSignature($config['secret_key'], $shortDate, $config['region'], $stringToSign);
        $authorization = 'AWS4-HMAC-SHA256 ' .
            'Credential=' . $config['access_key'] . '/' . $shortDate . '/' . $config['region'] . '/s3/aws4_request, ' .
            'SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $headers = array_merge([
            'Host: ' . $host,
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $date,
        ], $extraHeaders);

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $length = strlen($header);
            $pieces = explode(':', $header, 2);
            if (count($pieces) === 2) {
                $responseHeaders[strtolower(trim($pieces[0]))] = trim($pieces[1]);
            }
            return $length;
        });
        if ($body !== '' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $success = $httpCode >= 200 && $httpCode < 300;
        if (!$success) {
            Utils::writeLog('S3 API ' . $method . ' 失败: HTTP ' . $httpCode . ' ' . substr((string) $responseBody, 0, 1000));
        }
        return [
            'status' => $success,
            'http_code' => $httpCode,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'message' => $success ? '' : ($error ?: 'HTTP ' . $httpCode),
        ];
    }

    private static function buildCanonicalQuery(array $query)
    {
        $encoded = [];
        foreach ($query as $name => $value) {
            $encoded[rawurlencode((string) $name)] = rawurlencode((string) $value);
        }
        ksort($encoded, SORT_STRING);
        $pairs = [];
        foreach ($encoded as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return implode('&', $pairs);
    }

    private static function isCompleteConfig(array $config)
    {
        if (empty($config['endpoint']) || empty($config['access_key'])
            || empty($config['secret_key']) || empty($config['bucket'])) {
            return false;
        }

        $endpoint = parse_url((string) $config['endpoint']);
        return is_array($endpoint)
            && !empty($endpoint['host'])
            && !empty($endpoint['scheme'])
            && in_array(strtolower((string) $endpoint['scheme']), ['http', 'https'], true);
    }

    private static function getRequestedRange()
    {
        $range = isset($_SERVER['HTTP_RANGE'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])))
            : '';
        return preg_match('/^bytes=(?:\d+-\d*|-\d+)$/', $range) ? $range : '';
    }

    private static function sanitizeMimeType($mimetype)
    {
        $mimetype = strtolower(trim((string) $mimetype));
        return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mimetype)
            ? $mimetype
            : 'application/octet-stream';
    }

    private static function createCanonicalRequest($method, $path, $host, $body, $rangeHeader = '', $payloadHash = null, $amzDate = '')
    {
        $hashedPayload = $payloadHash ?: hash('sha256', $body);

        $canonicalHeaders = "host:$host\n";
        $signedHeaderList = ['host'];

        if ($rangeHeader) {
            $canonicalHeaders .= "range:$rangeHeader\n";
            $signedHeaderList[] = 'range';
        }

        $canonicalHeaders .= "x-amz-content-sha256:$hashedPayload\n";
        $signedHeaderList[] = 'x-amz-content-sha256';

        if ($amzDate !== '') {
            $canonicalHeaders .= "x-amz-date:$amzDate\n";
            $signedHeaderList[] = 'x-amz-date';
        }

        $signedHeaders = implode(';', $signedHeaderList);

        $canonicalRequest = $method . "\n" .
               $path . "\n" .
               "\n" .
               $canonicalHeaders . "\n" .
               $signedHeaders . "\n" .
               $hashedPayload;

        return $canonicalRequest;
    }

    private static function createStringToSign($date, $shortDate, $region, $canonicalRequest)
    {
        $credentialScope = $shortDate . '/' . $region . '/s3/aws4_request';
        $hashedCanonicalRequest = hash('sha256', $canonicalRequest);

        $stringToSign = "AWS4-HMAC-SHA256\n" .
               $date . "\n" .
               $credentialScope . "\n" .
               $hashedCanonicalRequest;

        return $stringToSign;
    }

    private static function calculateSignature($secretKey, $shortDate, $region, $stringToSign)
    {
        $kSecret = 'AWS4' . $secretKey;
        $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return $signature;
    }

    public static function buildPublicUrl($config, $key)
    {
        return self::buildUrl($config, $key);
    }

    public static function encodeObjectKey($key)
    {
        $segments = explode('/', str_replace('\\', '/', ltrim($key, '/')));
        return implode('/', array_map('rawurlencode', $segments));
    }

    public static function buildCanonicalHost($endpoint)
    {
        $parsed = parse_url($endpoint);
        $host = $parsed['host'] ?? '';
        return $host . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    }

    public static function buildCanonicalPathForConfig($config, $key)
    {
        $parsed = parse_url($config['endpoint']);
        $basePath = !empty($parsed['path']) ? '/' . trim($parsed['path'], '/') : '';
        $encodedKey = self::encodeObjectKey($key);
        $path = $basePath;
        if (!empty($config['path_style'])) {
            $path .= '/' . rawurlencode($config['bucket']);
        }
        if ($encodedKey !== '') {
            $path .= '/' . $encodedKey;
        }
        return $path === '' ? '/' : $path;
    }

    private static function buildUrl($config, $key)
    {
        $endpoint = rtrim($config['endpoint'], '/');
        $encodedKey = self::encodeObjectKey($key);

        if ($config['path_style']) {
            return $endpoint . '/' . rawurlencode($config['bucket']) . ($encodedKey !== '' ? '/' . $encodedKey : '');
        }

        $parsed = parse_url($endpoint);
        $host = $parsed['host'];
        $scheme = $parsed['scheme'] ?? 'https';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $basePath = !empty($parsed['path']) ? '/' . trim($parsed['path'], '/') : '';
        return $scheme . '://' . $config['bucket'] . '.' . $host . $port . $basePath . ($encodedKey !== '' ? '/' . $encodedKey : '/');
    }

    private static function buildCanonicalPath($config, $key)
    {
        return self::buildCanonicalPathForConfig($config, $key);
    }

    private static function getPublicUrl($config, $key)
    {
        if (!empty($config['custom_url'])) {
            return rtrim($config['custom_url'], '/') . '/' . self::encodeObjectKey($key);
        }
        return self::buildUrl($config, $key);
    }
}
