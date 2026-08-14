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
        $config = self::getConfig();

        if (!file_exists($filepath)) {
            return ['status' => false, 'message' => '文件不存在'];
        }

        if ($cloudKey === null) {
            $cloudKey = self::getCloudKey($filepath);
        }

        $mimetype = self::getMimeType($filepath);
        $filedata = file_get_contents($filepath);
        if ($filedata === false) {
            return ['status' => false, 'message' => '无法读取待上传文件'];
        }

        Utils::writeLog('S3上传配置: ' . json_encode([
            'endpoint' => $config['endpoint'],
            'bucket' => $config['bucket'],
            'region' => $config['region'],
            'path_style' => $config['path_style'],
            'cloudKey' => $cloudKey,
            'mimetype' => $mimetype,
            'filesize' => strlen($filedata)
        ]));

        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');

        $url = self::buildUrl($config, $cloudKey);
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPath($config, $cloudKey);

        $canonicalRequest = self::createCanonicalRequest('PUT', $path, $host, $filedata);
        $stringToSign = self::createStringToSign($date, $shortDate, $config['region'], $canonicalRequest);
        $signature = self::calculateSignature($config['secret_key'], $shortDate, $config['region'], $stringToSign);

        $authorization = 'AWS4-HMAC-SHA256 ' .
            'Credential=' . $config['access_key'] . '/' . $shortDate . '/' . $config['region'] . '/s3/aws4_request, ' .
            'SignedHeaders=host;x-amz-content-sha256, ' .
            'Signature=' . $signature;

        $headers = [
            'Host: ' . $host,
            'Date: ' . $date,
            'Content-Type: ' . $mimetype,
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . hash('sha256', $filedata)
        ];

        Utils::writeLog('S3请求URL: ' . $url);
        Utils::writeLog('S3签名路径: ' . $path);
        Utils::writeLog('S3请求已签名，Host=' . $host . ', Content-Type=' . $mimetype);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $filedata);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

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
        $config = self::getConfig();

        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');

        $url = self::buildUrl($config, $key);
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPath($config, $key);

        $canonicalRequest = self::createCanonicalRequest('DELETE', $path, $host, '');
        $stringToSign = self::createStringToSign($date, $shortDate, $config['region'], $canonicalRequest);
        $signature = self::calculateSignature($config['secret_key'], $shortDate, $config['region'], $stringToSign);

        $authorization = 'AWS4-HMAC-SHA256 ' .
            'Credential=' . $config['access_key'] . '/' . $shortDate . '/' . $config['region'] . '/s3/aws4_request, ' .
            'SignedHeaders=host;x-amz-content-sha256, ' .
            'Signature=' . $signature;

        $headers = [
            'Host: ' . $host,
            'Date: ' . $date,
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . hash('sha256', '')
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public static function testConnection()
    {
        $config = self::getConfig();

        if (empty($config['endpoint']) || empty($config['access_key']) ||
            empty($config['secret_key']) || empty($config['bucket'])) {
            return ['status' => false, 'message' => '请填写完整的S3配置信息'];
        }

        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');

        $url = self::buildUrl($config, '');
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPath($config, '');

        $canonicalRequest = self::createCanonicalRequest('GET', $path, $host, '');
        $stringToSign = self::createStringToSign($date, $shortDate, $config['region'], $canonicalRequest);
        $signature = self::calculateSignature($config['secret_key'], $shortDate, $config['region'], $stringToSign);

        $authorization = 'AWS4-HMAC-SHA256 ' .
            'Credential=' . $config['access_key'] . '/' . $shortDate . '/' . $config['region'] . '/s3/aws4_request, ' .
            'SignedHeaders=host;x-amz-content-sha256, ' .
            'Signature=' . $signature;

        $headers = [
            'Host: ' . $host,
            'Date: ' . $date,
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . hash('sha256', '')
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['status' => true, 'message' => 'S3连接成功'];
        }

        return ['status' => false, 'message' => 'S3连接失败: ' . ($error ?: "HTTP $httpCode")];
    }

    public static function getCloudUrl($key)
    {
        $config = self::getConfig();
        return self::getPublicUrl($config, $key);
    }

    public static function download($key)
    {
        $config = self::getConfig();

        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');

        $url = self::buildUrl($config, $key);
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPath($config, $key);

        $rangeHeader = '';
        if (isset($_SERVER['HTTP_RANGE'])) {
            $rangeHeader = $_SERVER['HTTP_RANGE'];
        }

        $canonicalRequest = self::createCanonicalRequest('GET', $path, $host, '', $rangeHeader);
        $stringToSign = self::createStringToSign($date, $shortDate, $config['region'], $canonicalRequest);
        $signature = self::calculateSignature($config['secret_key'], $shortDate, $config['region'], $stringToSign);

        $signedHeaders = 'host;x-amz-content-sha256';
        if ($rangeHeader) {
            $signedHeaders = 'host;range;x-amz-content-sha256';
        }

        $authorization = 'AWS4-HMAC-SHA256 ' .
            'Credential=' . $config['access_key'] . '/' . $shortDate . '/' . $config['region'] . '/s3/aws4_request, ' .
            'SignedHeaders=' . $signedHeaders . ', ' .
            'Signature=' . $signature;

        $headers = [
            'Host: ' . $host,
            'Date: ' . $date,
            'Authorization: ' . $authorization,
            'x-amz-content-sha256: ' . hash('sha256', '')
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

    private static function createCanonicalRequest($method, $path, $host, $body, $rangeHeader = '')
    {
        $hashedPayload = hash('sha256', $body);

        $canonicalHeaders = "host:$host\n";
        $signedHeaderList = ['host'];

        if ($rangeHeader) {
            $canonicalHeaders .= "range:$rangeHeader\n";
            $signedHeaderList[] = 'range';
        }

        $canonicalHeaders .= "x-amz-content-sha256:$hashedPayload\n";
        $signedHeaderList[] = 'x-amz-content-sha256';

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
