<?php

namespace WPStow;

use WPStow\StorageInterface;
use WPStow\MediaHandler;

class WebDAVStorage extends StorageInterface
{
    public static function getConfig()
    {
        return [
            'endpoint' => MediaHandler::config('webdav_endpoint'),
            'username' => MediaHandler::config('webdav_username'),
            'password' => MediaHandler::config('webdav_password'),
            'path' => MediaHandler::config('webdav_path') ?: '/',
            'custom_url' => MediaHandler::config('webdav_custom_url'),
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

        $remotePath = rtrim($config['path'], '/') . '/' . $cloudKey;
        $directoryResult = self::ensureDirectory($config, dirname($remotePath));
        if (empty($directoryResult['status'])) {
            return $directoryResult;
        }
        $url = self::buildRemoteUrl($config['endpoint'], $remotePath);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $filedata);
        curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . $mimetype,
            'Content-Length: ' . strlen($filedata)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $publicUrl = self::getPublicUrl($config, $cloudKey);
            return [
                'status' => true,
                'data' => [
                    'url' => $publicUrl,
                    'key' => $cloudKey,
                    'name' => basename($cloudKey),
                    'mimetype' => $mimetype,
                    'pathname' => $remotePath
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

        $remotePath = rtrim($config['path'], '/') . '/' . $key;
        $url = self::buildRemoteUrl($config['endpoint'], $remotePath);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
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

        if (empty($config['endpoint']) || empty($config['username']) || empty($config['password'])) {
            return ['status' => false, 'message' => '请填写完整的WebDAV配置信息'];
        }

        $url = self::buildRemoteUrl($config['endpoint'], $config['path']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Depth: 0']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['status' => true, 'message' => 'WebDAV连接成功'];
        }

        return ['status' => false, 'message' => 'WebDAV连接失败: ' . ($error ?: "HTTP $httpCode")];
    }

    public static function getCloudUrl($key)
    {
        $config = self::getConfig();
        return self::getPublicUrl($config, $key);
    }

    public static function download($key)
    {
        $config = self::getConfig();

        $remotePath = rtrim($config['path'], '/') . '/' . $key;
        $url = self::buildRemoteUrl($config['endpoint'], $remotePath);

        $headers = [];
        if (isset($_SERVER['HTTP_RANGE'])) {
            $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
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

    private static function getPublicUrl($config, $key)
    {
        if (!empty($config['custom_url'])) {
            return self::buildRemoteUrl($config['custom_url'], $key);
        }

        $remotePath = rtrim($config['path'], '/') . '/' . $key;
        return self::buildRemoteUrl($config['endpoint'], $remotePath);
    }

    private static function buildRemoteUrl($endpoint, $path)
    {
        $segments = array_filter(explode('/', trim((string) $path, '/')), 'strlen');
        $encodedPath = implode('/', array_map(static function ($segment) {
            return rawurlencode(rawurldecode($segment));
        }, $segments));
        return rtrim((string) $endpoint, '/') . ($encodedPath === '' ? '/' : '/' . $encodedPath);
    }

    private static function ensureDirectory($config, $directory)
    {
        $segments = array_values(array_filter(explode('/', trim((string) $directory, '/')), 'strlen'));
        $current = '';
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $ch = curl_init(self::buildRemoteUrl($config['endpoint'], $current));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'MKCOL',
                CURLOPT_USERPWD => $config['username'] . ':' . $config['password'],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // 405 means the collection already exists on common WebDAV servers.
            if (($httpCode < 200 || $httpCode >= 300) && $httpCode !== 405) {
                return [
                    'status' => false,
                    'message' => '创建 WebDAV 目录失败: ' . ($error ?: "HTTP $httpCode"),
                    'response' => $response,
                ];
            }
        }
        return ['status' => true];
    }
}
