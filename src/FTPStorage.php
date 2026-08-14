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
        $result = ftp_delete($conn, $remotePath);
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

        $tempFile = tempnam(sys_get_temp_dir(), 'wpstow_');
        if (!@ftp_get($conn, $tempFile, $remotePath, FTP_BINARY)) {
            ftp_close($conn);
            @unlink($tempFile);
            return ['status' => false, 'message' => 'FTP下载失败'];
        }

        ftp_close($conn);

        $data = file_get_contents($tempFile);
        @unlink($tempFile);

        $mimeType = self::getMimeTypeFromExtension($key);

        return [
            'status' => true,
            'data' => $data,
            'http_code' => 200,
            'headers' => [
                'content-type' => $mimeType,
                'content-length' => strlen($data),
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
        if ($config['ssl']) {
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
                if (!@ftp_mkdir($conn, $part) || !@ftp_chdir($conn, $part)) {
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
        if (!empty($config['custom_url'])) {
            return rtrim($config['custom_url'], '/') . '/' . $key;
        }

        $protocol = $config['ssl'] ? 'ftps' : 'ftp';
        return $protocol . '://' . $config['host'] . rtrim($config['path'], '/') . '/' . $key;
    }
}
