<?php

namespace WPStow;

use WPStow\StorageInterface;
use WPStow\MediaHandler;

class FTPStorage extends StorageInterface
{
    public static function getConfig()
    {
        return [
            'host' => MediaHandler::config('ftp_host'),
            'port' => MediaHandler::config('ftp_port') ?: 21,
            'username' => MediaHandler::config('ftp_username'),
            'password' => MediaHandler::config('ftp_password'),
            'path' => MediaHandler::config('ftp_path') ?: '/',
            'passive' => MediaHandler::config('ftp_passive') !== 'no',
            'ssl' => MediaHandler::config('ftp_ssl') === 'yes',
            'custom_url' => MediaHandler::config('ftp_custom_url'),
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

        $conn = self::connect($config);
        if (!$conn) {
            return ['status' => false, 'message' => 'FTP连接失败'];
        }

        $remotePath = rtrim($config['path'], '/') . '/' . $cloudKey;

        $remoteDir = dirname($remotePath);
        if (!self::ensureDirectory($conn, $remoteDir)) {
            ftp_close($conn);
            return ['status' => false, 'message' => 'FTP目录不存在且无法创建'];
        }

        if (!ftp_put($conn, $remotePath, $filepath, FTP_BINARY)) {
            ftp_close($conn);
            return ['status' => false, 'message' => 'FTP上传失败'];
        }

        ftp_close($conn);

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

    public static function delete($key)
    {
        $config = self::getConfig();

        $conn = self::connect($config);
        if (!$conn) {
            return false;
        }

        $remotePath = rtrim($config['path'], '/') . '/' . $key;
        $result = @ftp_delete($conn, $remotePath);
        if (!$result) {
            $listing = @ftp_nlist($conn, dirname($remotePath));
            if (is_array($listing)) {
                $names = array_map('basename', $listing);
                $result = !in_array(basename($remotePath), $names, true);
            }
        }
        ftp_close($conn);

        return $result;
    }

    public static function testConnection()
    {
        $config = self::getConfig();

        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            return ['status' => false, 'message' => '请填写完整的FTP配置信息'];
        }

        $conn = self::connect($config);
        if (!$conn) {
            return ['status' => false, 'message' => 'FTP连接失败，请检查配置'];
        }

        $remotePath = $config['path'];
        if (!@ftp_chdir($conn, $remotePath)) {
            ftp_close($conn);
            return ['status' => false, 'message' => 'FTP目录不存在或无权限访问'];
        }

        ftp_close($conn);
        return ['status' => true, 'message' => 'FTP连接成功'];
    }

    public static function getCloudUrl($key)
    {
        $config = self::getConfig();
        return self::getPublicUrl($config, $key);
    }

    public static function download($key)
    {
        $config = self::getConfig();

        $conn = self::connect($config);
        if (!$conn) {
            return ['status' => false, 'message' => 'FTP连接失败'];
        }

        $remotePath = rtrim($config['path'], '/') . '/' . $key;

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        if (strtoupper($requestMethod) === 'HEAD') {
            $size = @ftp_size($conn, $remotePath);
            ftp_close($conn);
            if ($size < 0) {
                return ['status' => false, 'http_code' => 404, 'message' => 'FTP 文件不存在或无法读取大小'];
            }
            return [
                'status' => true,
                'data' => '',
                'temp_file' => '',
                'http_code' => 200,
                'headers' => [
                    'content-type' => self::getMimeTypeFromExtension($key),
                    'content-length' => (string) $size,
                    'accept-ranges' => 'none',
                ],
            ];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'wpstow_');
        if (!$tempFile) {
            ftp_close($conn);
            return ['status' => false, 'http_code' => 0, 'message' => '无法创建 FTP 下载临时文件'];
        }
        if (!@ftp_get($conn, $tempFile, $remotePath, FTP_BINARY)) {
            ftp_close($conn);
            @unlink($tempFile);
            return ['status' => false, 'message' => 'FTP下载失败'];
        }

        ftp_close($conn);

        $mimeType = self::getMimeTypeFromExtension($key);

        return [
            'status' => true,
            'data' => '',
            'temp_file' => $tempFile,
            'http_code' => 200,
            'headers' => [
                'content-type' => $mimeType,
                'content-length' => (string) filesize($tempFile),
                'accept-ranges' => 'none'
            ]
        ];
    }

    private static function getMimeTypeFromExtension($path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg',
            'zip' => 'application/zip', 'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        return $types[$ext] ?? 'application/octet-stream';
    }

    private static function connect($config)
    {
        if (!function_exists('ftp_connect')) {
            return false;
        }
        if ($config['ssl']) {
            if (!function_exists('ftp_ssl_connect')) {
                return false;
            }
            $conn = @ftp_ssl_connect($config['host'], $config['port'], 10);
        } else {
            $conn = @ftp_connect($config['host'], $config['port'], 10);
        }

        if (!$conn) {
            return false;
        }

        if (!@ftp_login($conn, $config['username'], $config['password'])) {
            ftp_close($conn);
            return false;
        }

        if ($config['passive']) {
            ftp_pasv($conn, true);
        }

        return $conn;
    }

    private static function ensureDirectory($conn, $path)
    {
        $parts = explode('/', trim($path, '/'));
        if (!@ftp_chdir($conn, '/')) {
            return false;
        }
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!@ftp_chdir($conn, $part)) {
                $created = @ftp_mkdir($conn, $part);
                if ($created === false || !@ftp_chdir($conn, $part)) {
                    @ftp_chdir($conn, '/');
                    return false;
                }
            }
        }
        @ftp_chdir($conn, '/');
        return true;
    }

    private static function getPublicUrl($config, $key)
    {
        $segments = array_filter(explode('/', ltrim(str_replace('\\', '/', (string) $key), '/')), static function ($segment) {
            return $segment !== '';
        });
        $encodedKey = implode('/', array_map('rawurlencode', $segments));
        if (!empty($config['custom_url'])) {
            return rtrim($config['custom_url'], '/') . '/' . $encodedKey;
        }

        $protocol = $config['ssl'] ? 'ftps' : 'ftp';
        $port = (int) $config['port'];
        $portSuffix = $port > 0 && $port !== 21 ? ':' . $port : '';
        return $protocol . '://' . $config['host'] . $portSuffix . rtrim($config['path'], '/') . '/' . $encodedKey;
    }
}
