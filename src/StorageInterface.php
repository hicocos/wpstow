<?php

namespace WPStow;

abstract class StorageInterface
{
    abstract public static function upload($filepath, $cloudKey = null);
    abstract public static function delete($key);
    abstract public static function testConnection();
    abstract public static function getCloudUrl($key);
    abstract public static function download($key);

    public static function generateFilename($filepath)
    {
        return FileNaming::generateFilename(basename((string) $filepath));
    }

    public static function getCloudKey($filepath)
    {
        $uploadDir = wp_upload_dir();
        $basedir = wp_normalize_path((string) $uploadDir['basedir']);
        $filepath = wp_normalize_path((string) $filepath);
        $basePrefix = trailingslashit($basedir);

        if ($basedir !== '' && strpos($filepath, $basePrefix) === 0) {
            return ltrim(substr($filepath, strlen($basePrefix)), '/');
        }

        return basename($filepath);
    }

    public static function normalizeObjectKey($key)
    {
        $key = ltrim(str_replace('\\', '/', (string) $key), '/');
        if ($key === '' || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return false;
        }

        $segments = explode('/', $key);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return implode('/', $segments);
    }

    /**
     * Download a public HTTP object to a temporary file without buffering it in PHP memory.
     */
    protected static function downloadHttpUrl($url)
    {
        if (!wp_http_validate_url($url)) {
            return ['status' => false, 'http_code' => 400, 'message' => '对象地址不安全'];
        }

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        $method = strtoupper($requestMethod) === 'HEAD' ? 'HEAD' : 'GET';
        $headers = [];
        $range = isset($_SERVER['HTTP_RANGE'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])))
            : '';
        if ($method === 'GET' && preg_match('/^bytes=(?:\d+-\d*|-\d+)$/', $range)) {
            $headers['Range'] = $range;
        }

        $tempFile = '';
        $args = [
            'method' => $method,
            'timeout' => 120,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
            'headers' => $headers,
        ];
        if ($method === 'GET') {
            $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'wpstow-download';
            $tempFile = function_exists('wp_tempnam') ? wp_tempnam($name) : tempnam(sys_get_temp_dir(), 'wpstow_');
            if (!$tempFile) {
                return ['status' => false, 'http_code' => 0, 'message' => '无法创建下载临时文件'];
            }
            $args['stream'] = true;
            $args['filename'] = $tempFile;
        }

        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            if ($tempFile !== '') {
                @unlink($tempFile);
            }
            return ['status' => false, 'http_code' => 0, 'message' => $response->get_error_message()];
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $responseHeaders = [];
        foreach (['content-type', 'content-length', 'content-range', 'cache-control', 'etag', 'last-modified', 'accept-ranges'] as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            if ($value !== '') {
                $responseHeaders[$name] = (string) $value;
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            if ($tempFile !== '') {
                @unlink($tempFile);
            }
            return ['status' => false, 'http_code' => $httpCode, 'message' => 'HTTP ' . $httpCode];
        }

        return [
            'status' => true,
            'data' => '',
            'temp_file' => $tempFile,
            'http_code' => $httpCode,
            'headers' => $responseHeaders,
        ];
    }

    public static function getMimeType($filepath)
    {
        $mimetype = 'application/octet-stream';

        if (extension_loaded('fileinfo') && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $result = finfo_file($finfo, $filepath);
                finfo_close($finfo);
                if ($result) {
                    return $result;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $result = mime_content_type($filepath);
            if ($result) {
                return $result;
            }
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/x-icon',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            '7z'   => 'application/x-7z-compressed',
            'tar'  => 'application/x-tar',
            'gz'   => 'application/gzip',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'avi'  => 'video/x-msvideo',
            'mov'  => 'video/quicktime',
            'wmv'  => 'video/x-ms-wmv',
            'flv'  => 'video/x-flv',
            'mkv'  => 'video/x-matroska',
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'xml'  => 'application/xml',
        ];

        if (isset($mime_types[$ext])) {
            return $mime_types[$ext];
        }

        return $mimetype;
    }
}
