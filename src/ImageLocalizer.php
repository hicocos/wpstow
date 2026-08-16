<?php

namespace WPStow;

use WPStow\StorageInterface;
use WPStow\MediaHandler;
use WPStow\Utils;

class ImageLocalizer
{
    private static $processed = false;

    public static function init()
    {
        add_filter('wp_insert_post_data', [__CLASS__, 'processPostContent'], 10, 2);
    }

    public static function processContentSave($content)
    {
        if (self::$processed) {
            return $content;
        }
        self::$processed = true;

        if (empty($content) || !current_user_can('upload_files')) {
            return $content;
        }

        if (MediaHandler::config('switch') !== 'enable') {
            return $content;
        }

        // 检查图片本地化开关
        if (MediaHandler::config('localize_images') !== 'yes') {
            return $content;
        }

        $content = self::processBase64Images($content);
        $content = self::processExternalImages($content);

        return $content;
    }

    public static function processPostContent($data, $postarr)
    {
        if (!isset($data['post_content']) || empty($data['post_content']) || !current_user_can('upload_files')) {
            return $data;
        }

        if (MediaHandler::config('switch') !== 'enable') {
            return $data;
        }

        // 检查图片本地化开关
        if (MediaHandler::config('localize_images') !== 'yes') {
            return $data;
        }

        if (wp_is_post_revision($postarr['ID'] ?? 0)) {
            return $data;
        }

        $data['post_content'] = self::processBase64Images($data['post_content']);
        $data['post_content'] = self::processExternalImages($data['post_content']);

        return $data;
    }

    private static function processBase64Images($content)
    {
        $pattern = '/<img[^>]+src=["\']data:image\/(png|jpeg|jpg|gif|webp|bmp);base64,([^"\']+)["\'][^>]*>/i';

        if (!preg_match($pattern, $content)) {
            return $content;
        }

        Utils::writeLog('发现 base64 编码图片，开始本地化处理');

        $content = preg_replace_callback($pattern, function ($matches) {
            $imageType = strtolower($matches[1]);
            $base64Data = $matches[2];

            $extMap = [
                'png' => 'png',
                'jpeg' => 'jpg',
                'jpg' => 'jpg',
                'gif' => 'gif',
                'webp' => 'webp',
                'bmp' => 'bmp',
            ];

            $ext = $extMap[$imageType] ?? 'png';
            $mimeType = 'image/' . $imageType;

            if (strlen($base64Data) > (int) ceil(10 * MB_IN_BYTES * 4 / 3) + 4) {
                Utils::writeLog('base64 图片超过 10 MiB，已跳过');
                return $matches[0];
            }
            $imageData = base64_decode($base64Data, true);
            if ($imageData === false || $imageData === '' || strlen($imageData) > 10 * MB_IN_BYTES) {
                Utils::writeLog('base64 解码失败');
                return $matches[0];
            }

            $upload = self::saveToMediaLibrary($imageData, $ext, $mimeType);
            if ($upload) {
                Utils::writeLog('base64 图片本地化成功: ' . $upload['url']);
                return self::replaceImgSrc($matches[0], $upload['url']);
            }

            return $matches[0];
        }, $content);

        return $content;
    }

    private static function processExternalImages($content)
    {
        $siteUrl = site_url();
        $siteHost = parse_url($siteUrl, PHP_URL_HOST);

        $pattern = '/<img[^>]+src=["\'](https?:\/\/([^"\']+))["\'][^>]*>/i';

        if (!preg_match($pattern, $content)) {
            return $content;
        }

        Utils::writeLog('发现外链图片，开始本地化处理');

        $content = preg_replace_callback($pattern, function ($matches) use ($siteHost) {
            $fullUrl = $matches[1];
            $urlHost = parse_url($fullUrl, PHP_URL_HOST);

            if ($urlHost === $siteHost) {
                return $matches[0];
            }

            $homeUrl = home_url();
            if (strpos($fullUrl, $homeUrl) === 0) {
                return $matches[0];
            }

            $upload = self::downloadAndSave($fullUrl);
            if ($upload) {
                Utils::writeLog('外链图片本地化成功: ' . $fullUrl . ' -> ' . $upload['url']);
                return self::replaceImgSrc($matches[0], $upload['url']);
            }

            return $matches[0];
        }, $content);

        return $content;
    }

    private static function replaceImgSrc($imgTag, $newUrl)
    {
        return preg_replace('/src=["\'][^"\']+["\']/', 'src="' . esc_url($newUrl) . '"', $imgTag, 1);
    }

    private static function downloadAndSave($url)
    {
        if (!self::isAllowedRemoteUrl($url)) {
            Utils::writeLog('ImageLocalizer: 拒绝不安全的外链图片地址');
            return null;
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => 20,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'limit_response_size' => 10 * MB_IN_BYTES,
            'user-agent' => 'WPStow/' . (defined('WPSTOW_VERSION') ? WPSTOW_VERSION : '1.0'),
        ]);
        if (is_wp_error($response)) {
            Utils::writeLog('ImageLocalizer: 下载失败: ' . $response->get_error_message());
            return null;
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $contentType = wp_remote_retrieve_header($response, 'content-type');
        $imageData = wp_remote_retrieve_body($response);
        if ($httpCode !== 200 || $imageData === '' || strlen($imageData) > 10 * MB_IN_BYTES) {
            Utils::writeLog('ImageLocalizer: 下载响应无效，HTTP ' . $httpCode);
            return null;
        }

        $ext = self::getExtFromUrlOrMime($url, $contentType);
        $mimeType = self::getMimeTypeFromExt($ext);
        return self::saveToMediaLibrary($imageData, $ext, $mimeType);
    }

    private static function isAllowedRemoteUrl($url)
    {
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            return false;
        }

        $records = @dns_get_record($parts['host'], DNS_A | DNS_AAAA);
        if (!$records) {
            $ip = gethostbyname($parts['host']);
            $records = $ip !== $parts['host'] ? [['ip' => $ip]] : [];
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? ($record['ipv6'] ?? '');
            if (!$ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return !empty($records);
    }

    private static function saveToMediaLibrary($imageData, $ext, $mimeType)
    {
        $uploadDir = wp_upload_dir();
        if (!empty($uploadDir['error'])) {
            Utils::writeLog('获取上传目录失败: ' . $uploadDir['error']);
            return null;
        }

        $allowedMimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];
        $ext = strtolower((string) $ext);
        if (!isset($allowedMimes[$ext]) || $allowedMimes[$ext] !== strtolower(trim((string) $mimeType))) {
            Utils::writeLog('拒绝不受支持的本地化图片格式');
            return null;
        }
        if (!is_string($imageData) || $imageData === '' || strlen($imageData) > 10 * MB_IN_BYTES) {
            Utils::writeLog('拒绝空图片或超过 10 MiB 的本地化图片');
            return null;
        }

        $filename = wp_unique_filename($uploadDir['path'], FileNaming::generateFilename('remote.' . $ext));
        $savePath = $uploadDir['path'] . '/' . $filename;

        $saved = file_put_contents($savePath, $imageData, LOCK_EX);
        if ($saved === false || $saved !== strlen($imageData)) {
            Utils::writeLog('保存临时文件失败: ' . $savePath);
            @unlink($savePath);
            return null;
        }

        $imageInfo = @getimagesize($savePath);
        $actualMime = is_array($imageInfo) ? strtolower((string) $imageInfo['mime']) : '';
        if ($actualMime === '' || $actualMime !== $allowedMimes[$ext]) {
            Utils::writeLog('本地化图片内容与声明格式不匹配，已拒绝入库');
            @unlink($savePath);
            return null;
        }
        $pixels = (int) ($imageInfo[0] ?? 0) * (int) ($imageInfo[1] ?? 0);
        if ($pixels < 1 || $pixels > 100000000) {
            Utils::writeLog('本地化图片尺寸无效或超过一亿像素，已拒绝入库');
            @unlink($savePath);
            return null;
        }

        $attachment = [
            'guid' => $uploadDir['url'] . '/' . $filename,
            'post_mime_type' => $mimeType,
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit'
        ];

        $attachId = wp_insert_attachment($attachment, $savePath, 0, true);
        if (is_wp_error($attachId)) {
            Utils::writeLog('创建附件失败: ' . $attachId->get_error_message());
            @unlink($savePath);
            return null;
        }

        if (file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachData = wp_generate_attachment_metadata($attachId, $savePath);
        wp_update_attachment_metadata($attachId, $attachData);

        $url = wp_get_attachment_url($attachId);

        return [
            'id' => $attachId,
            'url' => $url,
            'file' => $filename
        ];
    }

    private static function getExtFromUrlOrMime($url, $contentType)
    {
        $contentType = strtolower(trim((string) strtok((string) $contentType, ';')));
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
        ];
        if (isset($mimeMap[$contentType])) {
            return $mimeMap[$contentType];
        }

        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $validExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
            if (in_array($ext, $validExts)) {
                return $ext;
            }
        }

        return 'jpg';
    }

    private static function getMimeTypeFromExt($ext)
    {
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];

        return $map[$ext] ?? 'image/jpeg';
    }
}
