<?php

namespace WPStow;

class SuperbedStorage extends StorageInterface
{
    private const DEFAULT_ENDPOINT = 'https://api.superbed.cc';
    private const MAPPING_PREFIX = 'wpstow_superbed_object_';

    private static function getConfig()
    {
        return [
            'endpoint' => self::normalizeEndpoint(MediaHandler::config('superbed_endpoint')),
            'api_key' => trim((string) MediaHandler::config('superbed_api_key')),
            'folder_id' => trim((string) MediaHandler::config('superbed_folder_id')),
        ];
    }

    private static function normalizeEndpoint($endpoint)
    {
        $endpoint = rtrim(trim((string) $endpoint), '/');
        if ($endpoint === '') {
            return self::DEFAULT_ENDPOINT;
        }
        return preg_replace('#/api/v1$#i', '', $endpoint);
    }

    private static function apiUrl($endpoint, $path)
    {
        return self::normalizeEndpoint($endpoint) . '/' . ltrim($path, '/');
    }

    private static function mappingOptionName($key)
    {
        return self::MAPPING_PREFIX . md5(ltrim((string) $key, '/'));
    }

    private static function getMapping($key)
    {
        $normalizedKey = ltrim((string) $key, '/');
        if ($normalizedKey === '') {
            return null;
        }

        $mapping = get_option(self::mappingOptionName($normalizedKey), null);
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
            'id' => sanitize_text_field((string) ($file['id'] ?? '')),
            'url' => self::absoluteUrl($file['url'] ?? '', $endpoint),
            'mime_type' => sanitize_text_field((string) ($file['mime_type'] ?? '')),
            'endpoint' => self::normalizeEndpoint($endpoint),
            'created_at' => gmdate('c'),
        ];

        if ($mapping['id'] === '' || $mapping['url'] === '') {
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

    private static function payloadMessage($payload, $fallback)
    {
        if (!is_array($payload)) {
            return $fallback;
        }
        $message = $payload['detail'] ?? ($payload['message'] ?? ($payload['msg'] ?? ''));
        if (is_array($message)) {
            $message = wp_json_encode($message, JSON_UNESCAPED_UNICODE);
        }
        return trim((string) $message) !== '' ? (string) $message : $fallback;
    }

    private static function apiRequest($method, $path, $body = null, $endpoint = null)
    {
        $config = self::getConfig();
        $endpoint = self::normalizeEndpoint($endpoint ?: $config['endpoint']);
        if ($config['api_key'] === '') {
            return ['status' => false, 'message' => '请填写聚合图床 API Key'];
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => 30,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'X-API-Key' => $config['api_key'],
            ],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(self::apiUrl($endpoint, $path), $args);
        if (is_wp_error($response)) {
            return ['status' => false, 'message' => $response->get_error_message()];
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        if ($httpCode >= 200 && $httpCode < 300 && trim($responseBody) === '') {
            return [
                'status' => true,
                'http_code' => $httpCode,
                'message' => 'HTTP ' . $httpCode,
                'data' => [],
            ];
        }

        $payload = json_decode($responseBody, true);
        if (!is_array($payload)) {
            return ['status' => false, 'http_code' => $httpCode, 'message' => '聚合图床返回了无效 JSON'];
        }

        return [
            'status' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'message' => self::payloadMessage($payload, 'HTTP ' . $httpCode),
            'data' => $payload,
        ];
    }

    public static function upload($filepath, $cloudKey = null)
    {
        $config = self::getConfig();
        if ($config['api_key'] === '') {
            return ['status' => false, 'message' => '请填写聚合图床 API Key'];
        }
        if (!is_file($filepath) || !is_readable($filepath)) {
            return ['status' => false, 'message' => '文件不存在或不可读'];
        }

        $mimeType = self::getMimeType($filepath);
        if (strpos($mimeType, 'image/') !== 0) {
            return ['status' => false, 'message' => '聚合图床仅支持图片，当前文件类型为 ' . $mimeType];
        }

        $cloudKey = $cloudKey === null ? self::getCloudKey($filepath) : ltrim((string) $cloudKey, '/');
        if ($cloudKey === '') {
            return ['status' => false, 'message' => '无法确定聚合图床对象 Key'];
        }
        if (self::getMapping($cloudKey) && !self::delete($cloudKey)) {
            return ['status' => false, 'message' => '同名聚合图床对象清理失败，已停止重复上传'];
        }

        $postFields = [
            'files' => new \CURLFile($filepath, $mimeType, basename($cloudKey)),
        ];
        if ($config['folder_id'] !== '') {
            $postFields['folder_id'] = $config['folder_id'];
        }

        $ch = curl_init(self::apiUrl($config['endpoint'], 'api/v1/upload/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-Key: ' . $config['api_key'],
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
            return ['status' => false, 'message' => '聚合图床上传请求失败: ' . ($error ?: '未知网络错误')];
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return ['status' => false, 'message' => '聚合图床上传接口返回了无效 JSON'];
        }
        $file = $payload['files'][0] ?? null;
        if ($httpCode < 200 || $httpCode >= 300 || empty($payload['success']) || !is_array($file)) {
            return [
                'status' => false,
                'message' => self::payloadMessage($payload, $error ?: '聚合图床上传失败，HTTP ' . $httpCode),
            ];
        }
        if (empty($file['id']) || empty($file['url'])) {
            return ['status' => false, 'message' => '聚合图床上传成功，但返回数据缺少文件 UUID 或 URL'];
        }

        if (!self::saveMapping($cloudKey, $file, $config['endpoint'])) {
            $fileId = (string) $file['id'];
            self::apiRequest('POST', 'api/v1/files/batch/delete', ['file_ids' => [$fileId]], $config['endpoint']);
            $cleanup = self::apiRequest('DELETE', 'api/v1/trash/' . rawurlencode($fileId), null, $config['endpoint']);
            if (empty($cleanup['status'])) {
                usleep(250000);
                $cleanup = self::apiRequest('DELETE', 'api/v1/trash/' . rawurlencode($fileId), null, $config['endpoint']);
            }
            if (empty($cleanup['status'])) {
                Utils::writeLog('聚合图床映射保存失败后的永久清理失败: id=' . sanitize_text_field($fileId) . ', ' . ($cleanup['message'] ?? '未知错误'));
            }
            return ['status' => false, 'message' => !empty($cleanup['status'])
                ? '聚合图床上传成功，但 WordPress 无法保存对象映射，远端文件已永久清理'
                : '聚合图床上传成功，但 WordPress 无法保存对象映射，远端文件永久清理失败'];
        }

        return [
            'status' => true,
            'data' => [
                'id' => (string) $file['id'],
                'url' => self::absoluteUrl($file['url'], $config['endpoint']),
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
            return true;
        }

        $id = (string) $mapping['id'];
        $endpoint = $mapping['endpoint'] ?? null;
        $wasPending = !empty($mapping['delete_pending_at']);
        $trashResult = ['status' => true, 'message' => '文件已在回收站'];

        if (!$wasPending) {
            $trashResult = self::apiRequest(
                'POST',
                'api/v1/files/batch/delete',
                ['file_ids' => [$id]],
                $endpoint
            );
            $payload = $trashResult['data'] ?? [];
            $movedToTrash = !empty($trashResult['status'])
                && (int) ($payload['trashed_count'] ?? 0) === 1
                && (int) ($payload['failed_count'] ?? 0) === 0;
            if ($movedToTrash) {
                $mapping['delete_pending_at'] = gmdate('c');
                update_option(self::mappingOptionName($key), $mapping, false);
            }
        }

        $permanentResult = self::apiRequest('DELETE', 'api/v1/trash/' . rawurlencode($id), null, $endpoint);
        $missingFromTrash = (int) ($permanentResult['http_code'] ?? 0) === 404;
        $pendingSince = $wasPending ? strtotime((string) $mapping['delete_pending_at']) : 0;
        $confirmedAlreadyGone = $missingFromTrash && $pendingSince > 0 && $pendingSince <= time() - 30;
        if (!empty($permanentResult['status']) || $confirmedAlreadyGone) {
            delete_option(self::mappingOptionName($key));
            return true;
        }

        Utils::writeLog(
            '聚合图床永久删除失败: id=' . sanitize_text_field($id)
            . ', trash=' . ($trashResult['message'] ?? '未知错误')
            . ', permanent=' . ($permanentResult['message'] ?? '未知错误')
        );
        return false;
    }

    public static function testConnection()
    {
        $result = self::apiRequest('GET', 'api/v1/entries/?limit=1');
        if (empty($result['status'])) {
            return ['status' => false, 'message' => '聚合图床连接失败: ' . ($result['message'] ?? '未知错误')];
        }

        $payload = $result['data'] ?? [];
        if (!isset($payload['entries']) || !is_array($payload['entries'])) {
            return ['status' => false, 'message' => '聚合图床鉴权成功，但文件列表响应格式异常'];
        }
        return ['status' => true, 'message' => '聚合图床 API 鉴权成功'];
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
            return ['status' => false, 'http_code' => 404, 'message' => '聚合图床对象映射不存在'];
        }
        return self::downloadHttpUrl($url);
    }
}
