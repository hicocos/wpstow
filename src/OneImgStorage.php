<?php

namespace WPStow;

class OneImgStorage extends StorageInterface
{
    private const MAPPING_PREFIX = 'wpstow_oneimg_object_';
    private const LEGACY_MAPPING_PREFIX = 'vemedia_oneimg_object_';

    private static function getConfig()
    {
        return [
            'endpoint' => self::normalizeEndpoint(MediaHandler::config('oneimg_endpoint')),
            'token' => trim((string) MediaHandler::config('oneimg_token')),
        ];
    }

    private static function normalizeEndpoint($endpoint)
    {
        $endpoint = rtrim(trim((string) $endpoint), '/');
        if (substr($endpoint, -4) === '/api') {
            $endpoint = substr($endpoint, 0, -4);
        }
        return rtrim($endpoint, '/');
    }

    private static function apiUrl($endpoint, $path)
    {
        return self::normalizeEndpoint($endpoint) . '/api/' . ltrim($path, '/');
    }

    private static function mappingOptionName($key)
    {
        return self::MAPPING_PREFIX . md5(ltrim((string) $key, '/'));
    }

    private static function legacyMappingOptionName($key)
    {
        return self::LEGACY_MAPPING_PREFIX . md5(ltrim((string) $key, '/'));
    }

    private static function getMapping($key)
    {
        $normalizedKey = ltrim((string) $key, '/');
        if ($normalizedKey === '') {
            return null;
        }

        $mapping = get_option(self::mappingOptionName($normalizedKey), null);
        if (!is_array($mapping) || ($mapping['key'] ?? '') !== $normalizedKey) {
            $mapping = get_option(self::legacyMappingOptionName($normalizedKey), null);
            if (is_array($mapping) && ($mapping['key'] ?? '') === $normalizedKey) {
                update_option(self::mappingOptionName($normalizedKey), $mapping, false);
            }
        }
        if (!is_array($mapping) || ($mapping['key'] ?? '') !== $normalizedKey) {
            return null;
        }
        return $mapping;
    }

    private static function saveMapping($key, array $file, $endpoint)
    {
        $normalizedKey = ltrim((string) $key, '/');
        $mapping = [
            'version' => 1,
            'key' => $normalizedKey,
            'id' => (int) ($file['id'] ?? 0),
            'url' => self::absoluteUrl($file['url'] ?? '', $endpoint),
            'thumbnail_url' => self::absoluteUrl($file['thumbnail_url'] ?? '', $endpoint),
            'mime_type' => sanitize_text_field((string) ($file['mime_type'] ?? '')),
            'storage' => sanitize_text_field((string) ($file['storage'] ?? '')),
            'endpoint' => self::normalizeEndpoint($endpoint),
            'created_at' => gmdate('c'),
        ];

        if ($mapping['id'] <= 0 || $mapping['url'] === '') {
            return false;
        }

        $optionName = self::mappingOptionName($normalizedKey);
        update_option($optionName, $mapping, false);
        return self::getMapping($normalizedKey) === $mapping;
    }

    private static function absoluteUrl($url, $endpoint)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return self::normalizeEndpoint($endpoint) . '/' . ltrim($url, '/');
    }

    private static function apiRequest($method, $path, $endpoint = null)
    {
        $config = self::getConfig();
        $endpoint = self::normalizeEndpoint($endpoint ?: $config['endpoint']);
        if ($endpoint === '' || $config['token'] === '') {
            return ['status' => false, 'message' => '请填写完整的 OneImg Endpoint 和 API Token'];
        }

        $response = wp_remote_request(self::apiUrl($endpoint, $path), [
            'method' => strtoupper($method),
            'timeout' => 30,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'oneimg_token=' . $config['token'],
            ],
        ]);

        if (is_wp_error($response)) {
            return ['status' => false, 'message' => $response->get_error_message()];
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return ['status' => false, 'http_code' => $httpCode, 'message' => 'OneImg 返回了无效 JSON'];
        }

        $apiCode = (int) ($payload['code'] ?? 0);
        return [
            'status' => $httpCode >= 200 && $httpCode < 300 && $apiCode === 200,
            'http_code' => $httpCode,
            'api_code' => $apiCode,
            'message' => (string) ($payload['message'] ?? ''),
            'data' => $payload['data'] ?? null,
        ];
    }

    public static function upload($filepath, $cloudKey = null)
    {
        $config = self::getConfig();
        if ($config['endpoint'] === '' || $config['token'] === '') {
            return ['status' => false, 'message' => '请填写完整的 OneImg Endpoint 和 API Token'];
        }
        if (!is_file($filepath) || !is_readable($filepath)) {
            return ['status' => false, 'message' => '文件不存在或不可读'];
        }

        $mimeType = self::getMimeType($filepath);
        if (strpos($mimeType, 'image/') !== 0) {
            return ['status' => false, 'message' => 'OneImg 仅支持图片，当前文件类型为 ' . $mimeType];
        }

        $cloudKey = $cloudKey === null ? self::getCloudKey($filepath) : ltrim((string) $cloudKey, '/');
        if ($cloudKey === '') {
            return ['status' => false, 'message' => '无法确定 OneImg 对象 Key'];
        }

        if (self::getMapping($cloudKey) && !self::delete($cloudKey)) {
            return ['status' => false, 'message' => '同名 OneImg 对象清理失败，已停止重复上传'];
        }

        $ch = curl_init(self::apiUrl($config['endpoint'], 'upload'));
        $postFields = [
            'images[]' => new \CURLFile($filepath, $mimeType, basename($cloudKey)),
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: oneimg_token=' . $config['token'],
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => false, 'message' => 'OneImg 上传请求失败: ' . ($error ?: '未知网络错误')];
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return ['status' => false, 'message' => 'OneImg 上传接口返回了无效 JSON'];
        }
        if ($httpCode < 200 || $httpCode >= 300 || (int) ($payload['code'] ?? 0) !== 200) {
            return [
                'status' => false,
                'message' => (string) ($payload['message'] ?? ($error ?: 'OneImg 上传失败，HTTP ' . $httpCode)),
            ];
        }

        $file = $payload['data']['files'][0] ?? ($payload['data']['file'] ?? null);
        if (!is_array($file) || empty($file['id']) || empty($file['url'])) {
            return ['status' => false, 'message' => 'OneImg 上传成功，但返回数据缺少图片 ID 或 URL'];
        }

        if (!self::saveMapping($cloudKey, $file, $config['endpoint'])) {
            self::apiRequest('DELETE', 'images/' . (int) $file['id'], $config['endpoint']);
            return ['status' => false, 'message' => 'OneImg 上传成功，但 WordPress 无法保存对象映射，已尝试清理远端图片'];
        }

        return [
            'status' => true,
            'data' => [
                'id' => (int) $file['id'],
                'url' => self::absoluteUrl($file['url'], $config['endpoint']),
                'thumbnail_url' => self::absoluteUrl($file['thumbnail_url'] ?? '', $config['endpoint']),
                'key' => $cloudKey,
                'name' => (string) ($file['filename'] ?? basename($cloudKey)),
                'mimetype' => (string) ($file['mime_type'] ?? $mimeType),
                'pathname' => $cloudKey,
            ],
        ];
    }

    public static function delete($key)
    {
        $mapping = self::getMapping($key);
        if (!$mapping || empty($mapping['id'])) {
            return false;
        }

        $result = self::apiRequest('DELETE', 'images/' . (int) $mapping['id'], $mapping['endpoint'] ?? null);
        $missing = (int) ($result['http_code'] ?? 0) === 404 || (int) ($result['api_code'] ?? 0) === 404;
        if (!empty($result['status']) || $missing) {
            delete_option(self::mappingOptionName($key));
            delete_option(self::legacyMappingOptionName($key));
            return true;
        }

        Utils::writeLog('OneImg 删除失败: id=' . (int) $mapping['id'] . ', ' . ($result['message'] ?? '未知错误'));
        return false;
    }

    public static function testConnection()
    {
        $result = self::apiRequest('GET', 'uploadConfig');
        if (empty($result['status'])) {
            return ['status' => false, 'message' => 'OneImg 连接失败: ' . ($result['message'] ?? '未知错误')];
        }

        $buckets = [];
        foreach ((array) ($result['data']['buckets'] ?? []) as $bucket) {
            if (is_array($bucket) && !empty($bucket['name'])) {
                $buckets[] = $bucket['name'] . (!empty($bucket['type']) ? ' (' . $bucket['type'] . ')' : '');
            }
        }
        $message = 'OneImg API 鉴权成功';
        if ($buckets) {
            $message .= '；可用存储：' . implode('、', $buckets);
        }
        return ['status' => true, 'message' => $message];
    }

    public static function getCloudUrl($key)
    {
        $mapping = self::getMapping($key);
        return $mapping['url'] ?? '';
    }

    public static function download($key)
    {
        $url = self::getCloudUrl($key);
        if ($url === '') {
            return ['status' => false, 'http_code' => 404, 'message' => 'OneImg 对象映射不存在'];
        }

        $headers = [];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            $headers[] = 'Range: ' . sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE']));
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    if (in_array($name, ['content-type', 'content-length', 'content-range', 'cache-control', 'etag', 'last-modified', 'accept-ranges'], true)) {
                        $responseHeaders[$name] = trim($parts[1]);
                    }
                }
                return $length;
            },
        ]);

        $data = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($data !== false && $httpCode >= 200 && $httpCode < 300) {
            return ['status' => true, 'data' => $data, 'http_code' => $httpCode, 'headers' => $responseHeaders];
        }
        return ['status' => false, 'http_code' => $httpCode, 'message' => $error ?: 'HTTP ' . $httpCode];
    }
}
