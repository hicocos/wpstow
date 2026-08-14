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
                $proxyPath = sanitize_text_field($_GET['wpstow_proxy']);
            } elseif (isset($wp->query_vars['vemedia_proxy']) && !empty($wp->query_vars['vemedia_proxy'])) {
                $proxyPath = $wp->query_vars['vemedia_proxy'];
            } elseif (isset($_GET['vemedia_proxy']) && !empty($_GET['vemedia_proxy'])) {
                $proxyPath = sanitize_text_field($_GET['vemedia_proxy']);
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
        $customUrl = '';
        switch ($storageType) {
            case 'oneimg':
                $oneImgUrl = OneImgStorage::getCloudUrl($relativePath);
                if ($oneImgUrl !== '') {
                    return $oneImgUrl;
                }
                break;
            case 's3':
                $customUrl = MediaHandler::config('s3_custom_url');
                break;
            case 'webdav':
                $customUrl = MediaHandler::config('webdav_custom_url');
                break;
            case 'ftp':
                $customUrl = MediaHandler::config('ftp_custom_url');
                break;
        }
        // 配置了公开 CDN/自定义域名时直接访问；私有桶继续使用签名代理。
        if (!empty($customUrl)) {
            return rtrim($customUrl, '/') . '/' . ltrim($relativePath, '/');
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
        $proxyPath = isset($_GET['file']) ? sanitize_text_field($_GET['file']) : '';

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

        $attachmentId = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;
        self::doProxy($proxyPath, $attachmentId);
    }

    private static function doProxy($relativePath, $attachmentId = 0)
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || strpos($relativePath, '../') !== false || strpos($relativePath, "\0") !== false) {
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
        if (!self::isConfigured($storageType)) {
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
        $httpCode = $result['http_code'] ?? 200;
        $data = $result['data'];

        $cacheTime = 86400 * 30;

        if ($httpCode === 206) {
            header('HTTP/1.1 206 Partial Content');
        } else {
            header('HTTP/1.1 200 OK');
        }

        if ($mimeType) {
            header('Content-Type: ' . $mimeType);
        }
        if (isset($cloudHeaders['content-length'])) {
            header('Content-Length: ' . $cloudHeaders['content-length']);
        } else {
            header('Content-Length: ' . strlen($data));
        }
        if (isset($cloudHeaders['content-range'])) {
            header('Content-Range: ' . $cloudHeaders['content-range']);
        }
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=' . $cacheTime);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $cacheTime) . ' GMT');
        if (isset($cloudHeaders['etag'])) {
            header('ETag: ' . $cloudHeaders['etag']);
        }
        if (isset($cloudHeaders['last-modified'])) {
            header('Last-Modified: ' . $cloudHeaders['last-modified']);
        }

        echo $data;
        exit;
    }

    /**
     * Resolve a cloud key only through registered attachment data.
     * Returns false when no safe, readable local counterpart exists.
     */
    public static function getLocalFallbackPath($relativePath, $attachmentId = 0)
    {
        $relativePath = ltrim(str_replace('\\', '/', (string) $relativePath), '/');
        if ($relativePath === '' || strpos($relativePath, '../') !== false || strpos($relativePath, "\0") !== false) {
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
            if ($mainKey === $relativePath && $mainFile && is_file($mainFile)) {
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
                    return is_file($path) ? $path : false;
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
        $size = filesize($path);
        status_header(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=' . (86400 * 7));
        readfile($path);
        exit;
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
