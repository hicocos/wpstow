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
        $filesize = filesize($filepath);
        $stream = @fopen($filepath, 'rb');
        if ($filesize === false || !$stream) {
            return ['status' => false, 'message' => '无法读取待上传文件'];
        }

        $remotePath = rtrim($config['path'], '/') . '/' . $cloudKey;
        $directoryResult = self::ensureDirectory($config, dirname($remotePath));
        if (empty($directoryResult['status'])) {
            fclose($stream);
            return $directoryResult;
        }
        $url = self::buildRemoteUrl($config['endpoint'], $remotePath);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $stream);
        curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
        curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . $mimetype,
            'Content-Length: ' . $filesize
        ]);
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

        return ($httpCode >= 200 && $httpCode < 300) || $httpCode === 404;
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

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        $method = strtoupper($requestMethod) === 'HEAD' ? 'HEAD' : 'GET';
        $headers = [];
        $range = isset($_SERVER['HTTP_RANGE'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])))
            : '';
        if ($method === 'GET' && preg_match('/^bytes=(?:\d+-\d*|-\d+)$/', $range)) {
            $headers[] = 'Range: ' . $range;
        }

        $tempFile = $method === 'GET'
            ? (function_exists('wp_tempnam') ? wp_tempnam(basename($key)) : tempnam(sys_get_temp_dir(), 'wpstow_'))
            : '';
        $stream = $method === 'GET' && $tempFile ? @fopen($tempFile, 'wb') : null;
        if ($method === 'GET' && (!$tempFile || !$stream)) {
            if ($tempFile) {
                @unlink($tempFile);
            }
            return ['status' => false, 'http_code' => 0, 'message' => '无法创建 WebDAV 下载临时文件'];
        }

        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $method === 'HEAD');
        curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . $config['password']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } else {
            curl_setopt($ch, CURLOPT_FILE, $stream);
        }
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

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($stream) {
            fclose($stream);
        }

        if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
            return [
                'status' => true,
                'data' => '',
                'temp_file' => $method === 'GET' ? $tempFile : '',
                'http_code' => $httpCode,
                'headers' => $responseHeaders
            ];
        }

        if ($tempFile) {
            @unlink($tempFile);
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
        $segments = array_filter(explode('/', trim((string) $path, '/')), static function ($segment) {
            return $segment !== '';
        });
        $encodedPath = implode('/', array_map(static function ($segment) {
            // Keys are stored decoded. Decoding again could turn a literal
            // "%2e%2e" segment into traversal syntax on some WebDAV servers.
            return rawurlencode($segment);
        }, $segments));
        return rtrim((string) $endpoint, '/') . ($encodedPath === '' ? '/' : '/' . $encodedPath);
    }

    private static function ensureDirectory($config, $directory)
    {
        $segments = array_values(array_filter(explode('/', trim((string) $directory, '/')), static function ($segment) {
            return $segment !== '';
        }));
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
