<?php

namespace WPStow;

class R2Storage extends S3Storage
{
    private const DEFAULT_PRESIGN_TTL = 900;
    private const MAX_PRESIGN_TTL = 604800;

    public static function getConfig()
    {
        return [
            'endpoint' => MediaHandler::config('r2_endpoint'),
            'access_key' => MediaHandler::config('r2_access_key'),
            'secret_key' => MediaHandler::config('r2_secret_key'),
            'bucket' => MediaHandler::config('r2_bucket'),
            'region' => 'auto',
            'path_style' => true,
            'custom_url' => MediaHandler::config('r2_custom_url'),
        ];
    }

    public static function getPresignTtl()
    {
        $ttl = (int) MediaHandler::config('r2_presign_ttl');
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_PRESIGN_TTL;
        }
        return min(self::MAX_PRESIGN_TTL, max(60, $ttl));
    }

    public static function createPresignedUrl($key, $method = 'GET', $ttl = null)
    {
        $config = static::getConfig();
        if (
            empty($config['endpoint']) || empty($config['access_key']) ||
            empty($config['secret_key']) || empty($config['bucket'])
        ) {
            return '';
        }

        $method = strtoupper((string) $method) === 'HEAD' ? 'HEAD' : 'GET';
        $ttl = $ttl === null ? self::getPresignTtl() : min(self::MAX_PRESIGN_TTL, max(60, (int) $ttl));
        $date = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        $scope = $shortDate . '/auto/s3/aws4_request';
        $url = self::buildPublicUrl($config, $key);
        $host = self::buildCanonicalHost($url);
        $path = self::buildCanonicalPathForConfig($config, $key);

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $config['access_key'] . '/' . $scope,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $ttl,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query, SORT_STRING);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonicalRequest = $method . "\n" .
            $path . "\n" .
            $canonicalQuery . "\n" .
            'host:' . $host . "\n\n" .
            "host\n" .
            'UNSIGNED-PAYLOAD';
        $stringToSign = "AWS4-HMAC-SHA256\n" .
            $date . "\n" .
            $scope . "\n" .
            hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $config['secret_key'], true);
        $kRegion = hash_hmac('sha256', 'auto', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $query['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $kSigning);

        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function testConnection()
    {
        $result = parent::testConnection();
        if (isset($result['message'])) {
            $result['message'] = str_replace('S3', 'R2', $result['message']);
        }
        return $result;
    }
}
