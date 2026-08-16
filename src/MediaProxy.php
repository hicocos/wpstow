<?php

namespace WPStow;

use WPStow\MediaHandler;
use WPStow\Utils;

class MediaProxy
{
    private static $proxySlug = 'wpstow-proxy';

    public static function init()
    {
        add_filter('query_vars', [__CLASS__, 'registerQueryVar']);
        add_action('parse_request', [__CLASS__, 'handleProxyRequest'], 1);
        add_action('init', [__CLASS__, 'registerRewriteRules']);
        add_action('admin_init', [__CLASS__, 'ensureRewriteRules']);
        add_action('admin_init', [__CLASS__, 'ensureHtaccess']);

        add_action('wp_ajax_wpstow_proxy', [__CLASS__, 'ajaxProxy']);
        add_action('wp_ajax_nopriv_wpstow_proxy', [__CLASS__, 'ajaxProxy']);
        // Existing content may contain proxy URLs generated before the rename.
        add_action('wp_ajax_vemedia_proxy', [__CLASS__, 'ajaxProxy']);
        add_action('wp_ajax_nopriv_vemedia_proxy', [__CLASS__, 'ajaxProxy']);
    }

    public static function registerQueryVar($vars)
    {
        $vars[] = 'wpstow_proxy';
        $vars[] = 'vemedia_proxy';
        return $vars;
    }

    public static function registerRewriteRules()
    {
        add_rewrite_rule(
            '^' . self::$proxySlug . '/(.+)$',
            'index.php?wpstow_proxy=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^vemedia-proxy/(.+)$',
            'index.php?wpstow_proxy=$matches[1]',
            'top'
        );
    }

    public static function ensureRewriteRules()
    {
        $rules = get_option('rewrite_rules');
        $ruleKey = '^' . self::$proxySlug . '/(.+)$';

        if (!isset($rules[$ruleKey])) {
            flush_rewrite_rules();
            Utils::writeLog('已刷新 rewrite rules，添加 wpstow-proxy 端点');
        }
    }

    public static function handleProxyRequest($wp)
    {
        $proxyPath = isset($wp->query_vars['wpstow_proxy']) ? $wp->query_vars['wpstow_proxy'] : '';

        if (empty($proxyPath)) {
            if (isset($_GET['wpstow_proxy']) && !empty($_GET['wpstow_proxy'])) {
                $proxyPath = sanitize_text_field(wp_unslash($_GET['wpstow_proxy']));
            } elseif (isset($wp->query_vars['vemedia_proxy']) && !empty($wp->query_vars['vemedia_proxy'])) {
                $proxyPath = $wp->query_vars['vemedia_proxy'];
            } elseif (isset($_GET['vemedia_proxy']) && !empty($_GET['vemedia_proxy'])) {
                $proxyPath = sanitize_text_field(wp_unslash($_GET['vemedia_proxy']));
            }
        }

        if (empty($proxyPath)) {
            return;
        }

        $proxyPath = urldecode($proxyPath);

        if (preg_match('/\.(php|phtml|php\d)$/i', $proxyPath)) {
            status_header(403);
            echo 'Forbidden';
            exit;
        }

        self::doProxy($proxyPath);
    }

    public static function getProxyUrl($relativePath, $attachmentId = 0)
    {
        $storageType = $attachmentId
            ? MediaHandler::getAttachmentStorageType($attachmentId)
            : (string) MediaHandler::config('storage_type');
        $storageMatches = !$attachmentId
            || MediaHandler::attachmentStorageMatchesCurrent($attachmentId, $storageType);
        $customUrl = '';
        switch ($storageType) {
            case 'oneimg':
                $oneImgUrl = OneImgStorage::getCloudUrl($relativePath);
                if ($oneImgUrl !== '') {
                    return $oneImgUrl;
                }
                break;
            case 'superbed':
                $superbedUrl = SuperbedStorage::getCloudUrl($relativePath);
                if ($superbedUrl !== '') {
                    return $superbedUrl;
                }
                break;
            case 's3':
                $customUrl = MediaHandler::config('s3_custom_url');
                break;
            case 'r2':
                $customUrl = MediaHandler::config('r2_custom_url');
                if (!empty($customUrl) && $storageMatches) {
                    return R2Storage::getCloudUrl($relativePath);
                }
                break;
            case 'webdav':
                $customUrl = MediaHandler::config('webdav_custom_url');
                break;
            case 'ftp':
                $customUrl = MediaHandler::config('ftp_custom_url');
                break;
        }
        // 配置了公开 CDN/自定义域名时直接访问；其他后端使用固定插件入口。
        if (!empty($customUrl) && $storageMatches) {
            $storageClass = self::getStorageClass($storageType);
            if ($storageClass) {
                $publicUrl = (string) $storageClass::getCloudUrl($relativePath);
                if ($publicUrl !== '') {
                    return $publicUrl;
                }
            }
        }
        return add_query_arg([
            'action' => 'wpstow_proxy',
            'file' => $relativePath,
            'attachment_id' => (int) $attachmentId,
        ], admin_url('admin-ajax.php'));
    }

    public static function isCloudAttachment($post_id)
    {
        return (bool) get_post_meta($post_id, '_wpstow_uploaded', true);
    }

    public static function ajaxProxy()
    {
        $proxyPath = isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';

        if (empty($proxyPath)) {
            status_header(400);
            echo 'Missing file parameter';
            exit;
        }

        $proxyPath = urldecode($proxyPath);

        if (preg_match('/\.(php|phtml|php\d)$/i', $proxyPath)) {
            status_header(403);
            echo 'Forbidden';
            exit;
        }

        $attachmentId = isset($_GET['attachment_id']) ? absint(wp_unslash($_GET['attachment_id'])) : 0;
        self::doProxy($proxyPath, $attachmentId);
    }

    private static function doProxy($relativePath, $attachmentId = 0)
    {
        $relativePath = StorageInterface::normalizeObjectKey($relativePath);
        if ($relativePath === false) {
            status_header(400);
            echo 'Invalid file parameter';
            exit;
        }
        $attachmentId = self::findAttachmentIdForMediaKey($relativePath, $attachmentId);
        if (!$attachmentId) {
            Utils::writeLog('拒绝未登记的云端对象代理请求: ' . basename($relativePath));
            status_header(404);
            echo 'File not found';
            exit;
        }
        Utils::writeLog('代理请求: ' . $relativePath);

        $storageType = MediaHandler::getAttachmentStorageType($attachmentId);
        if (!MediaHandler::attachmentStorageMatchesCurrent($attachmentId, $storageType)) {
            Utils::writeLog('附件存储目标已变更，拒绝从新目标读取同名对象: attachment_id=' . $attachmentId);
            if (MediaHandler::shouldFallbackToLocal()) {
                $localPath = self::getLocalFallbackPath($relativePath, $attachmentId);
                if ($localPath) {
                    self::serveLocalFile($localPath);
                }
            }
            status_header(503);
            echo 'Storage target changed';
            exit;
        }
        if (!self::isConfigured($storageType)) {
            if (MediaHandler::shouldFallbackToLocal()) {
                $localPath = self::getLocalFallbackPath($relativePath, $attachmentId);
                if ($localPath) {
                    self::serveLocalFile($localPath);
                }
            }
            status_header(503);
            echo 'Storage not configured';
            exit;
        }

        $storageClass = self::getStorageClass($storageType);
        if (!$storageClass) {
            Utils::writeLog('无法获取存储类');
            status_header(500);
            echo 'Storage not available';
            exit;
        }

        if (in_array($storageType, ['s3', 'r2'], true)) {
            self::redirectObjectStorageRequest($storageType, $relativePath, $attachmentId);
        }

        $result = $storageClass::download($relativePath);

        if (empty($result['status'])) {
            Utils::writeLog('代理下载失败: ' . ($result['message'] ?? '未知错误') . ', key=' . $relativePath);
            if (MediaHandler::shouldFallbackToLocal()) {
                $localPath = self::getLocalFallbackPath($relativePath, $attachmentId);
                if ($localPath) {
                    Utils::writeLog('云端读取失败，已回退本地副本: ' . basename($localPath));
                    self::serveLocalFile($localPath);
                }
            }
            status_header(404);
            echo 'File not found';
            exit;
        }

        $cloudHeaders = $result['headers'] ?? [];
        // Image backends may transcode files (for example PNG to WebP), so the
        // remote response header is more authoritative than the object suffix.
        $mimeType = !empty($cloudHeaders['content-type'])
            ? $cloudHeaders['content-type']
            : self::guessMimeType($relativePath);
        $mimeType = self::sanitizeResponseMimeType($mimeType);
        $httpCode = $result['http_code'] ?? 200;
        $data = (string) ($result['data'] ?? '');

        if (!empty($result['temp_file'])) {
            self::serveTemporaryDownload((string) $result['temp_file'], $cloudHeaders, (int) $httpCode, $mimeType);
        }

        $cacheTime = 86400 * 30;

        if ($httpCode === 206) {
            header('HTTP/1.1 206 Partial Content');
        } else {
            header('HTTP/1.1 200 OK');
        }

        if ($mimeType) {
            header('Content-Type: ' . $mimeType);
        }
        header('X-Content-Type-Options: nosniff');
        if (isset($cloudHeaders['content-length']) && preg_match('/^\d+$/', (string) $cloudHeaders['content-length'])) {
            header('Content-Length: ' . (string) (int) $cloudHeaders['content-length']);
        } else {
            header('Content-Length: ' . strlen($data));
        }
        if (isset($cloudHeaders['content-range']) && preg_match('/^bytes \d+-\d+\/(?:\d+|\*)$/', (string) $cloudHeaders['content-range'])) {
            header('Content-Range: ' . $cloudHeaders['content-range']);
        }
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=' . $cacheTime);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheTime) . ' GMT');
        if (isset($cloudHeaders['etag'])) {
            header('ETag: ' . self::sanitizeResponseHeaderValue($cloudHeaders['etag']));
        }
        if (isset($cloudHeaders['last-modified'])) {
            header('Last-Modified: ' . self::sanitizeResponseHeaderValue($cloudHeaders['last-modified']));
        }

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        if (strtoupper($requestMethod) !== 'HEAD') {
            echo $data;
        }
        exit;
    }

    private static function redirectObjectStorageRequest($storageType, $relativePath, $attachmentId)
    {
        $isR2 = $storageType === 'r2';
        $publicUrl = (string) MediaHandler::config($isR2 ? 'r2_custom_url' : 's3_custom_url');
        if ($publicUrl !== '') {
            $targetUrl = $isR2
                ? R2Storage::getCloudUrl($relativePath)
                : S3Storage::getCloudUrl($relativePath);
            $cacheTime = 86400;
        } else {
            if (MediaHandler::shouldFallbackToLocal()) {
                $localPath = self::getLocalFallbackPath($relativePath, $attachmentId);
                if ($localPath) {
                    $storageClass = $isR2 ? R2Storage::class : S3Storage::class;
                    $head = $storageClass::headObject($relativePath);
                    if (empty($head['status'])) {
                        Utils::writeLog(strtoupper($storageType) . ' 对象不可读，已回退本地副本: key=' . $relativePath);
                        self::serveLocalFile($localPath);
                    }
                }
            }

            $requestMethod = isset($_SERVER['REQUEST_METHOD'])
                ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])))
                : 'GET';
            $method = $requestMethod === 'HEAD' ? 'HEAD' : 'GET';
            $targetUrl = $isR2
                ? R2Storage::createPresignedUrl($relativePath, $method)
                : S3Storage::createPresignedRequestUrl($method, $relativePath, [], 900);
            $cacheTime = $isR2 ? max(0, min(300, R2Storage::getPresignTtl() - 60)) : 300;
        }

        if ($targetUrl === '') {
            Utils::writeLog(strtoupper($storageType) . ' 临时访问地址生成失败: key=' . $relativePath);
            if (MediaHandler::shouldFallbackToLocal()) {
                $localPath = self::getLocalFallbackPath($relativePath, $attachmentId);
                if ($localPath) {
                    self::serveLocalFile($localPath);
                }
            }
            status_header(502);
            echo 'Unable to create object storage access URL';
            exit;
        }

        Utils::writeLog(strtoupper($storageType) . ($publicUrl !== '' ? ' 公开地址跳转: ' : ' 私有桶预签名跳转: ') . $relativePath);
        header('Cache-Control: public, max-age=' . $cacheTime);
        header('Location: ' . $targetUrl, true, 302);
        exit;
    }

    /**
     * Resolve a cloud key only through registered attachment data.
     * Returns false when no safe, readable local counterpart exists.
     */
    public static function getLocalFallbackPath($relativePath, $attachmentId = 0)
    {
        $relativePath = StorageInterface::normalizeObjectKey($relativePath);
        if ($relativePath === false) {
            return false;
        }

        global $wpdb;
        if (!$attachmentId) {
            $attachmentId = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpstow_cloud_key' AND meta_value = %s LIMIT 1",
                $relativePath
            ));
        }
        if ($attachmentId && get_post_meta($attachmentId, '_wpstow_uploaded', true)) {
            $mainFile = get_attached_file($attachmentId);
            $mainKey = get_post_meta($attachmentId, '_wpstow_cloud_key', true);
            if ($mainKey === $relativePath && self::isSafeUploadFile($mainFile)) {
                return $mainFile;
            }
        }

        $filename = basename($relativePath);
        $candidateIds = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->postmeta} vm ON vm.post_id = pm.post_id AND vm.meta_key = '_wpstow_uploaded' AND vm.meta_value = '1' WHERE pm.meta_key = '_wp_attachment_metadata' AND pm.meta_value LIKE %s LIMIT 20",
            '%' . $wpdb->esc_like($filename) . '%'
        ));
        $uploads = wp_upload_dir();
        foreach ($candidateIds as $candidateId) {
            $meta = wp_get_attachment_metadata($candidateId);
            if (empty($meta['file']) || empty($meta['sizes'])) {
                continue;
            }
            $dir = dirname($meta['file']);
            foreach ($meta['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }
                $key = ltrim(($dir === '.' ? '' : $dir . '/') . $size['file'], '/');
                if ($key === $relativePath) {
                    $path = trailingslashit($uploads['basedir']) . $key;
                    return self::isSafeUploadFile($path) ? $path : false;
                }
            }
        }
        return false;
    }

    private static function serveLocalFile($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            status_header(404);
            echo 'File not found';
            exit;
        }

        $mime = function_exists('wp_check_filetype') ? wp_check_filetype($path)['type'] : null;
        if (!$mime) {
            $mime = self::guessMimeType($path) ?: 'application/octet-stream';
        }
        $size = (int) filesize($path);
        $rangeHeader = isset($_SERVER['HTTP_RANGE'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])))
            : '';
        $range = self::parseLocalRange($rangeHeader, $size);
        if ($rangeHeader !== '' && $range === false) {
            status_header(416);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            exit;
        }

        $start = is_array($range) ? $range[0] : 0;
        $end = is_array($range) ? $range[1] : max(0, $size - 1);
        $length = $size > 0 ? $end - $start + 1 : 0;

        status_header(is_array($range) ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        if (is_array($range)) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Cache-Control: public, max-age=' . (86400 * 7));

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        if (strtoupper($requestMethod) === 'HEAD' || $length === 0) {
            exit;
        }

        $handle = @fopen($path, 'rb');
        if (!$handle || @fseek($handle, $start) !== 0) {
            if ($handle) {
                @fclose($handle);
            }
            status_header(500);
            exit;
        }

        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    private static function serveTemporaryDownload($path, array $cloudHeaders, $httpCode, $mimeType)
    {
        if (!is_file($path) || !is_readable($path)) {
            @unlink($path);
            status_header(502);
            echo 'Unable to read temporary download';
            exit;
        }

        register_shutdown_function(static function () use ($path) {
            @unlink($path);
        });

        $size = (int) filesize($path);
        $start = 0;
        $end = max(0, $size - 1);
        $isPartial = (int) $httpCode === 206;
        $contentRange = $isPartial ? (string) ($cloudHeaders['content-range'] ?? '') : '';

        if (!$isPartial) {
            $rangeHeader = isset($_SERVER['HTTP_RANGE'])
                ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])))
                : '';
            $range = self::parseLocalRange($rangeHeader, $size);
            if ($rangeHeader !== '' && $range === false) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                header('Content-Length: 0');
                exit;
            }
            if (is_array($range)) {
                $isPartial = true;
                $start = $range[0];
                $end = $range[1];
                $contentRange = 'bytes ' . $start . '-' . $end . '/' . $size;
            }
        }

        $length = $size > 0 ? $end - $start + 1 : 0;
        status_header($isPartial ? 206 : 200);
        header('Content-Type: ' . self::sanitizeResponseMimeType($mimeType));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        if ($isPartial && preg_match('/^bytes \d+-\d+\/(?:\d+|\*)$/', $contentRange)) {
            header('Content-Range: ' . $contentRange);
        }
        header('Cache-Control: public, max-age=' . (86400 * 30));
        if (isset($cloudHeaders['etag'])) {
            header('ETag: ' . self::sanitizeResponseHeaderValue($cloudHeaders['etag']));
        }
        if (isset($cloudHeaders['last-modified'])) {
            header('Last-Modified: ' . self::sanitizeResponseHeaderValue($cloudHeaders['last-modified']));
        }

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        if (strtoupper($requestMethod) === 'HEAD' || $length === 0) {
            exit;
        }

        $handle = @fopen($path, 'rb');
        if (!$handle || @fseek($handle, $start) !== 0) {
            if ($handle) {
                @fclose($handle);
            }
            status_header(500);
            exit;
        }

        $remaining = $length;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    private static function isSafeUploadFile($path)
    {
        if (!$path || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $uploads = wp_upload_dir();
        $base = realpath((string) $uploads['basedir']);
        $resolved = realpath((string) $path);
        if ($base === false || $resolved === false) {
            return false;
        }

        $base = trailingslashit(wp_normalize_path($base));
        $resolved = wp_normalize_path($resolved);
        return strpos($resolved, $base) === 0;
    }

    private static function parseLocalRange($header, $size)
    {
        $size = max(0, (int) $size);
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if ($size < 1 || !preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) {
            return false;
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength < 1) {
                return false;
            }
            return [max(0, $size - $suffixLength), $size - 1];
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];
        if ($start >= $size || $end < $start) {
            return false;
        }
        return [$start, min($end, $size - 1)];
    }

    private static function attachmentHasMediaKey($attachmentId, $relativePath)
    {
        if (!$attachmentId || !get_post_meta($attachmentId, '_wpstow_uploaded', true)) {
            return false;
        }
        if ((string) get_post_meta($attachmentId, '_wpstow_cloud_key', true) === $relativePath) {
            return true;
        }

        $meta = wp_get_attachment_metadata($attachmentId);
        if (empty($meta['file']) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return false;
        }
        $dir = dirname($meta['file']);
        foreach ($meta['sizes'] as $size) {
            if (!empty($size['file']) && ltrim(($dir === '.' ? '' : $dir . '/') . $size['file'], '/') === $relativePath) {
                return true;
            }
        }
        return false;
    }

    private static function findAttachmentIdForMediaKey($relativePath, $attachmentId = 0)
    {
        global $wpdb;

        $attachmentId = (int) $attachmentId;
        if ($attachmentId && self::attachmentHasMediaKey($attachmentId, $relativePath)) {
            return $attachmentId;
        }

        $mainId = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpstow_cloud_key' AND meta_value = %s LIMIT 1",
            $relativePath
        ));
        if ($mainId && get_post_meta($mainId, '_wpstow_uploaded', true)) {
            return (int) $mainId;
        }

        // 缩略图 key 不单独存 meta；限定到已迁移附件的 metadata，并做严格结果复核。
        $filename = basename($relativePath);
        $candidateIds = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->postmeta} vm ON vm.post_id = pm.post_id AND vm.meta_key = '_wpstow_uploaded' AND vm.meta_value = '1' WHERE pm.meta_key = '_wp_attachment_metadata' AND pm.meta_value LIKE %s LIMIT 20",
            '%' . $wpdb->esc_like($filename) . '%'
        ));
        foreach ($candidateIds as $attachmentId) {
            $meta = wp_get_attachment_metadata($attachmentId);
            if (empty($meta['file']) || empty($meta['sizes'])) {
                continue;
            }
            $dir = dirname($meta['file']);
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file']) && ltrim($dir . '/' . $size['file'], './') === $relativePath) {
                    return (int) $attachmentId;
                }
            }
        }
        return 0;
    }

    private static function isConfigured($storageType)
    {
        return MediaHandler::isStorageTypeConfigured($storageType);
    }

    private static function getStorageClass($storageType)
    {
        return MediaHandler::getStorageClass($storageType);
    }

    private static function guessMimeType($path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/x-icon',
            'pdf'  => 'application/pdf',
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'mp4'  => 'video/mp4',
            'avi'  => 'video/x-msvideo',
            'mov'  => 'video/quicktime',
            'wmv'  => 'video/x-ms-wmv',
            'flv'  => 'video/x-flv',
            'mkv'  => 'video/x-matroska',
            'webm' => 'video/webm',
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            '7z'   => 'application/x-7z-compressed',
            'tar'  => 'application/x-tar',
            'gz'   => 'application/gzip',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'exe'  => 'application/x-msdownload',
            'apk'  => 'application/vnd.android.package-archive',
        ];

        return $types[$ext] ?? null;
    }

    private static function sanitizeResponseMimeType($mimeType)
    {
        $mimeType = strtolower(trim((string) strtok((string) $mimeType, ';')));
        return preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $mimeType)
            ? $mimeType
            : 'application/octet-stream';
    }

    private static function sanitizeResponseHeaderValue($value)
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $value));
    }

    public static function ensureHtaccess()
    {
        if (MediaHandler::config('switch') !== 'enable') {
            return;
        }

        $uploadDir = wp_upload_dir();
        $basedir = $uploadDir['basedir'];

        if (empty($basedir) || !is_dir($basedir)) {
            return;
        }

        $htaccessPath = $basedir . '/.htaccess';
        $marker = '# WPSTOW_PROXY_START';
        $markerEnd = '# WPSTOW_PROXY_END';

        $siteUrl = site_url('/', 'relative');
        $siteUrl = rtrim($siteUrl, '/') . '/';

        $rules = <<<APACHE
$marker
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ {$siteUrl}index.php?wpstow_proxy=$1 [L,QSA]
</IfModule>
$markerEnd
APACHE;

        if (file_exists($htaccessPath)) {
            $content = file_get_contents($htaccessPath);
            $content = self::removeLegacyHtaccessBlock($content);
            if (strpos($content, $marker) !== false) {
                return;
            }
            $content .= "\n" . $rules;
            @file_put_contents($htaccessPath, $content);
        } else {
            @file_put_contents($htaccessPath, $rules);
        }
    }

    public static function removeHtaccess()
    {
        $uploadDir = wp_upload_dir();
        $basedir = $uploadDir['basedir'];

        if (empty($basedir)) {
            return;
        }

        $htaccessPath = $basedir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            return;
        }

        $originalContent = file_get_contents($htaccessPath);
        $content = self::removeLegacyHtaccessBlock($originalContent);
        $marker = '# WPSTOW_PROXY_START';
        $markerEnd = '# WPSTOW_PROXY_END';

        if (strpos($content, $marker) === false) {
            if ($content !== $originalContent) {
                $content = trim($content);
                if ($content === '') {
                    @unlink($htaccessPath);
                } else {
                    @file_put_contents($htaccessPath, $content);
                }
            }
            return;
        }

        $pattern = '/' . preg_quote($marker, '/') . '.*?' . preg_quote($markerEnd, '/') . '/s';
        $content = preg_replace($pattern, '', $content);
        $content = trim($content);

        if (empty($content)) {
            @unlink($htaccessPath);
        } else {
            @file_put_contents($htaccessPath, $content);
        }
    }

    private static function removeLegacyHtaccessBlock($content)
    {
        if (!is_string($content) || strpos($content, '# VEMEDIA_PROXY_START') === false) {
            return $content;
        }

        $pattern = '/' . preg_quote('# VEMEDIA_PROXY_START', '/') . '.*?' . preg_quote('# VEMEDIA_PROXY_END', '/') . '/s';
        return preg_replace($pattern, '', $content);
    }
}
