<?php

namespace WPStow;

use WPStow\Plugin;
use WPStow\Utils;
use WPStow\S3Storage;
use WPStow\WebDAVStorage;
use WPStow\FTPStorage;
use WPStow\OneImgStorage;

class MediaHandler extends Plugin
{
    private const STORAGE_CLASSES = [
        'oneimg' => OneImgStorage::class,
        's3' => S3Storage::class,
        'webdav' => WebDAVStorage::class,
        'ftp' => FTPStorage::class,
    ];

    private const MEDIA_CATEGORIES = ['image', 'video', 'audio', 'other'];

    private static $configInstance = null;
    private static $runtimeConfigOverride = null;

    private static function getConfigInstance()
    {
        if (self::$configInstance === null) {
            self::$configInstance = new self();
        }
        return self::$configInstance;
    }

    public static function getRuntimeConfigOverride()
    {
        return self::$runtimeConfigOverride;
    }

    public static function withRuntimeConfig(array $setting, callable $callback)
    {
        $previousOverride = self::$runtimeConfigOverride;
        $previousInstance = self::$configInstance;
        self::$runtimeConfigOverride = $setting;
        self::$configInstance = null;
        try {
            return $callback();
        } finally {
            self::$runtimeConfigOverride = $previousOverride;
            self::$configInstance = $previousInstance;
        }
    }

    public static function reloadConfig()
    {
        self::$configInstance = null;
    }

    public static function rawSetting($key, $default = null)
    {
        $setting = get_option('wpstow_setting');
        if ($setting && !is_array($setting)) {
            $setting = @unserialize($setting, ['allowed_classes' => false]);
        }

        if (!is_array($setting)) {
            return $default;
        }

        return array_key_exists($key, $setting) ? $setting[$key] : $default;
    }

    public static function config($key)
    {
        $instance = self::getConfigInstance();
        switch ($key) {
            case 'switch':
                return $instance->storage_switch;
            case 'storage_type':
                return $instance->storage_type;
            case 'provider_config_type':
                return $instance->provider_config_type;
            case 'image_storage_type':
                return $instance->image_storage_type;
            case 'video_storage_type':
                return $instance->video_storage_type;
            case 'audio_storage_type':
                return $instance->audio_storage_type;
            case 'other_storage_type':
                return $instance->other_storage_type;
            case 'oneimg_endpoint':
                return $instance->oneimg_endpoint;
            case 'oneimg_token':
                return $instance->oneimg_token;
            case 's3_endpoint':
                return $instance->s3_endpoint;
            case 's3_access_key':
                return $instance->s3_access_key;
            case 's3_secret_key':
                return $instance->s3_secret_key;
            case 's3_bucket':
                return $instance->s3_bucket;
            case 's3_region':
                return $instance->s3_region;
            case 's3_path_style':
                return $instance->s3_path_style;
            case 's3_custom_url':
                return $instance->s3_custom_url;
            case 'webdav_endpoint':
                return $instance->webdav_endpoint;
            case 'webdav_username':
                return $instance->webdav_username;
            case 'webdav_password':
                return $instance->webdav_password;
            case 'webdav_path':
                return $instance->webdav_path;
            case 'webdav_custom_url':
                return $instance->webdav_custom_url;
            case 'ftp_host':
                return $instance->ftp_host;
            case 'ftp_port':
                return $instance->ftp_port;
            case 'ftp_username':
                return $instance->ftp_username;
            case 'ftp_password':
                return $instance->ftp_password;
            case 'ftp_path':
                return $instance->ftp_path;
            case 'ftp_passive':
                return $instance->ftp_passive;
            case 'ftp_ssl':
                return $instance->ftp_ssl;
            case 'ftp_custom_url':
                return $instance->ftp_custom_url;
            // 功能开关
            case 'localize_images':
                return $instance->localize_images;
            case 'image_compress':
                return $instance->image_compress;
            case 'image_compress_quality':
                return $instance->image_compress_quality;
            case 'image_watermark':
                return $instance->image_watermark;
            case 'watermark_type':
                return $instance->watermark_type;
            case 'watermark_text':
                return $instance->watermark_text;
            case 'watermark_position':
                return $instance->watermark_position;
            case 'watermark_opacity':
                return $instance->watermark_opacity;
            case 'watermark_image':
                return $instance->watermark_image;
            case 'keep_original':
                return $instance->keep_original;
            case 'keep_local':
                return $instance->keep_local;
            case 'cloud_fallback_local':
                return $instance->cloud_fallback_local;
            case 'media_url_mode':
                return $instance->media_url_mode;
            // 视频处理
            case 'video_compress':
                return $instance->video_compress;
            case 'video_compress_quality':
                return $instance->video_compress_quality;
            case 'video_max_resolution':
                return $instance->video_max_resolution;
            case 'video_watermark':
                return $instance->video_watermark;
            default:
                return null;
        }
    }

    public static function getMediaCategory($mimeType)
    {
        $mimeType = strtolower(trim((string) $mimeType));
        foreach (['image', 'video', 'audio'] as $category) {
            if (strpos($mimeType, $category . '/') === 0) {
                return $category;
            }
        }
        return 'other';
    }

    public static function getStorageTypeForCategory($category)
    {
        $category = in_array($category, self::MEDIA_CATEGORIES, true) ? $category : 'other';
        $storageType = (string) self::config($category . '_storage_type');
        $allowed = $category === 'image'
            ? ['oneimg', 's3', 'webdav', 'ftp', 'local']
            : ['s3', 'webdav', 'ftp', 'local'];

        if (in_array($storageType, $allowed, true)) {
            return $storageType;
        }

        $legacyType = (string) self::config('storage_type');
        if ($category !== 'image' && $legacyType === 'oneimg') {
            return 'local';
        }
        return in_array($legacyType, $allowed, true) ? $legacyType : 'local';
    }

    public static function getStorageTypeForAttachment($postId)
    {
        $mimeType = get_post_mime_type($postId);
        if (!$mimeType) {
            $file = get_attached_file($postId);
            $fileType = $file && function_exists('wp_check_filetype') ? wp_check_filetype($file) : [];
            $mimeType = $fileType['type'] ?? '';
        }
        return self::getStorageTypeForCategory(self::getMediaCategory($mimeType));
    }

    public static function getAttachmentStorageType($postId)
    {
        $storedType = sanitize_key((string) get_post_meta($postId, '_wpstow_storage_type', true));
        if (isset(self::STORAGE_CLASSES[$storedType])) {
            return $storedType;
        }

        $manifest = get_post_meta($postId, '_wpstow_storage_manifest', true);
        $manifestType = is_array($manifest) ? sanitize_key((string) ($manifest['storage_type'] ?? '')) : '';
        if (isset(self::STORAGE_CLASSES[$manifestType])) {
            return $manifestType;
        }

        // Attachments created before storage manifests existed used the global backend.
        $legacyType = sanitize_key((string) self::config('storage_type'));
        return isset(self::STORAGE_CLASSES[$legacyType]) ? $legacyType : '';
    }

    public static function isStorageTypeConfigured($storageType)
    {
        if ($storageType === 'oneimg') {
            return !empty(self::config('oneimg_endpoint'))
                && !empty(self::config('oneimg_token'));
        } elseif ($storageType === 's3') {
            return !empty(self::config('s3_endpoint'))
                && !empty(self::config('s3_access_key'))
                && !empty(self::config('s3_secret_key'))
                && !empty(self::config('s3_bucket'));
        } elseif ($storageType === 'webdav') {
            return !empty(self::config('webdav_endpoint'))
                && !empty(self::config('webdav_username'))
                && !empty(self::config('webdav_password'));
        } elseif ($storageType === 'ftp') {
            return !empty(self::config('ftp_host'))
                && !empty(self::config('ftp_username'))
                && !empty(self::config('ftp_password'));
        }

        return false;
    }

    public static function isStorageEnabledAndConfigured($postId = 0)
    {
        if (self::config('switch') !== 'enable') {
            return false;
        }

        if ($postId) {
            return self::isStorageTypeConfigured(self::getStorageTypeForAttachment($postId));
        }

        $hasCloudRoute = false;
        foreach (self::MEDIA_CATEGORIES as $category) {
            $storageType = self::getStorageTypeForCategory($category);
            if ($storageType === 'local') {
                continue;
            }
            $hasCloudRoute = true;
            if (!self::isStorageTypeConfigured($storageType)) {
                return false;
            }
        }
        return $hasCloudRoute;
    }

    public static function getStorageClass($storageType = null)
    {
        $storageType = $storageType ?: self::config('storage_type');
        return self::STORAGE_CLASSES[$storageType] ?? null;
    }

    public static function shouldKeepLocalFiles()
    {
        return self::config('keep_local') !== 'no';
    }

    public static function shouldFallbackToLocal()
    {
        return self::config('cloud_fallback_local') !== 'no';
    }

    public static function shouldUseLocalMediaUrls()
    {
        return self::config('media_url_mode') === 'local';
    }

    public static function getMediaUrlModeLabel()
    {
        return self::shouldUseLocalMediaUrls() ? '本地链接' : '云端链接';
    }

    private static function localMainFileExists($attachment_id)
    {
        $localFile = $attachment_id ? get_attached_file($attachment_id) : '';
        return $localFile && is_file($localFile);
    }

    private static function localObjectFileExists($cloudKey)
    {
        $relativePath = ltrim(str_replace('\\', '/', (string) $cloudKey), '/');
        if ($relativePath === '' || strpos($relativePath, '../') !== false || strpos($relativePath, "\0") !== false) {
            return false;
        }

        $uploadDir = wp_upload_dir();
        if (empty($uploadDir['basedir'])) {
            return false;
        }

        return is_file(trailingslashit($uploadDir['basedir']) . $relativePath);
    }

    private static function shouldRewriteCloudUrls($attachment_id, $cloudKey = '', $mainResource = false)
    {
        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return false;
        }
        if (!self::shouldUseLocalMediaUrls()) {
            return true;
        }

        // 本地链接模式下仅在确有本地文件时停用云端重写；本地副本缺失时继续走云端，避免前台 404。
        if ($mainResource || $cloudKey === '') {
            return !self::localMainFileExists($attachment_id);
        }

        return !self::localObjectFileExists($cloudKey);
    }

    /**
     * Return one explicit state for media-library badges and actions.
     */
    public static function getProcessingStatus($post_id)
    {
        $post_id = (int) $post_id;
        $localFile = $post_id ? get_attached_file($post_id) : '';
        $localExists = $localFile && is_file($localFile);
        $uploaded = (bool) get_post_meta($post_id, '_wpstow_uploaded', true);
        $pending = (bool) get_post_meta($post_id, '_wpstow_pending', true);
        $error = (string) get_post_meta($post_id, '_wpstow_upload_error', true);
        $category = self::getMediaCategory(get_post_mime_type($post_id));
        $routeStorageType = self::getStorageTypeForCategory($category);

        if ($uploaded) {
            $code = 'processed';
            $effectiveUrlMode = self::shouldUseLocalMediaUrls() && $localExists ? 'local' : 'cloud';
            $deliveryMode = $effectiveUrlMode === 'local' ? '本地链接' : '云端链接';
            $label = $localExists ? '已处理 · 云端+本地' : '已处理 · 仅云端';
            if (self::shouldUseLocalMediaUrls()) {
                $message = $localExists
                    ? '当前媒体 URL 策略：本地优先；云端副本仍保留，可在设置中一键切回云端优先。'
                    : '当前媒体 URL 策略：本地优先，但本地文件不存在；该附件会继续使用云端链接。';
            } else {
                $message = $localExists
                    ? '当前媒体 URL 策略：云端优先；本地副本已保留，可随时切换。'
                    : '当前媒体 URL 策略：云端优先；本地没有可回退副本。';
            }
        } elseif ($routeStorageType === 'local') {
            $code = 'local';
            $label = '仅本地';
            $message = '该文件类型配置为仅保存在 WordPress 本地。';
        } elseif ($pending) {
            $code = 'pending';
            $label = '等待处理';
            $message = '附件正在等待 WPStow 上传处理。';
        } elseif ($error !== '') {
            $code = 'error';
            $label = '处理失败';
            $message = $error;
        } else {
            $code = 'unprocessed';
            $label = '未处理';
            $message = '该文件尚未经过 WPStow 云端处理。';
        }

        $effectiveUrlMode = $effectiveUrlMode ?? '';
        $deliveryMode = $deliveryMode ?? '';

        return [
            'code' => $code,
            'label' => $label,
            'message' => $message,
            'uploaded' => $uploaded,
            'local_exists' => (bool) $localExists,
            'url_mode' => $uploaded ? $effectiveUrlMode : '',
            'url_mode_label' => $uploaded ? $deliveryMode : '',
            'storage_type' => $uploaded ? self::getAttachmentStorageType($post_id) : $routeStorageType,
            'can_process' => !$uploaded && $routeStorageType !== 'local' && $localExists && self::isStorageEnabledAndConfigured($post_id),
        ];
    }

    public static function addMediaColumn($columns)
    {
        $columns['wpstow_status'] = __('WPStow 处理状态', 'wpstow');
        return $columns;
    }

    public static function renderMediaColumn($column_name, $post_id)
    {
        if ($column_name !== 'wpstow_status') {
            return;
        }

        $status = self::getProcessingStatus($post_id);
        $canEdit = current_user_can('upload_files') && current_user_can('edit_post', $post_id);
        $classes = 'wpstow-media-status wpstow-media-status--' . sanitize_html_class($status['code']);

        echo '<div class="' . esc_attr($classes) . '" data-wpstow-status="' . esc_attr($status['code']) . '">';
        echo '<strong class="wpstow-media-status__badge">' . esc_html($status['label']) . '</strong>';
        if (!empty($status['url_mode_label'])) {
            echo '<span class="wpstow-media-status__mode">当前链接：' . esc_html($status['url_mode_label']) . '</span>';
        }
        echo '<span class="wpstow-media-status__message" role="status" aria-live="polite">' . esc_html($status['message']) . '</span>';

        if (!$status['uploaded'] && $status['code'] !== 'local') {
            $disabled = (!$canEdit || !$status['can_process']) ? ' disabled' : '';
            $title = !$status['local_exists'] ? '本地源文件不存在，无法处理' : (!self::isStorageEnabledAndConfigured($post_id) ? '请先启用并完成该文件类型的存储配置' : '立即处理并上传云端');
            echo '<button type="button" class="button button-primary wpstow-process-button" data-attachment-id="' . (int) $post_id . '" data-nonce="' . esc_attr(wp_create_nonce('wpstow_admin')) . '" title="' . esc_attr($title) . '"' . $disabled . '>';
            echo $status['code'] === 'error' ? esc_html__('重试处理', 'wpstow') : esc_html__('立即处理', 'wpstow');
            echo '</button>';
        }
        echo '</div>';
    }

    private static function buildCloudKey($dir, $filename)
    {
        if ($dir === '.' || $dir === '') {
            return $filename;
        }
        return $dir . '/' . $filename;
    }

    private static function buildThumbFilepath($basedir, $dir, $filename)
    {
        if ($dir === '.' || $dir === '') {
            return $basedir . '/' . $filename;
        }
        return $basedir . '/' . $dir . '/' . $filename;
    }

    private static function buildStorageManifest($mainKey, $meta, $storageType)
    {
        $keys = [];
        if ($mainKey) {
            $keys[] = ltrim((string) $mainKey, '/');
        }
        if (!empty($meta['file']) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($meta['file']);
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $keys[] = self::buildCloudKey($dir, $size['file']);
                }
            }
        }
        $storageIdentity = [];
        if ($storageType === 'oneimg') {
            $storageIdentity = ['endpoint' => self::config('oneimg_endpoint')];
        } elseif ($storageType === 's3') {
            $storageIdentity = ['endpoint' => self::config('s3_endpoint'), 'bucket' => self::config('s3_bucket')];
        } elseif ($storageType === 'webdav') {
            $storageIdentity = ['endpoint' => self::config('webdav_endpoint'), 'path' => self::config('webdav_path')];
        } elseif ($storageType === 'ftp') {
            $storageIdentity = ['host' => self::config('ftp_host'), 'port' => self::config('ftp_port'), 'path' => self::config('ftp_path')];
        }
        return [
            'version' => 2,
            'storage_type' => $storageType,
            'storage_identity' => $storageIdentity,
            'main_key' => ltrim((string) $mainKey, '/'),
            'keys' => array_values(array_unique(array_filter($keys))),
            'created_at' => gmdate('c'),
        ];
    }

    public static function plugin_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=wpstow_settings') . '">设置</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public static function add_attachment($post_id)
    {
        Utils::writeLog('add_attachment 被调用, post_id=' . $post_id);

        if (self::config('switch') !== 'enable') {
            Utils::writeLog('自动转存未启用，跳过');
            return;
        }

        $storageType = self::getStorageTypeForAttachment($post_id);
        if ($storageType === 'local') {
            Utils::writeLog('该文件类型配置为仅本地，跳过附件 ID=' . (int) $post_id);
            return;
        }
        if (!self::isStorageTypeConfigured($storageType)) {
            update_post_meta($post_id, '_wpstow_upload_error', '该文件类型的存储源配置不完整');
            Utils::writeLog('存储源 ' . $storageType . ' 配置不完整，跳过附件 ID=' . (int) $post_id);
            return;
        }

        update_post_meta($post_id, '_wpstow_pending', '1');
        update_post_meta($post_id, '_wpstow_pending_storage', $storageType);
        Utils::writeLog('已标记为待上传，等待 generate_attachment_metadata 处理');
    }

    public static function generate_attachment_metadata($meta, $post_id, $context)
    {
        Utils::writeLog('generate_attachment_metadata 被调用, context=' . $context);

        if ($context === 'update') {
            return $meta;
        }

        if (!get_post_meta($post_id, '_wpstow_pending', true)) {
            return $meta;
        }

        $storageType = sanitize_key((string) get_post_meta($post_id, '_wpstow_pending_storage', true));
        if (!$storageType) {
            $storageType = self::getStorageTypeForAttachment($post_id);
        }
        if ($storageType === 'local' || !self::isStorageTypeConfigured($storageType)) {
            delete_post_meta($post_id, '_wpstow_pending');
            delete_post_meta($post_id, '_wpstow_pending_storage');
            update_post_meta($post_id, '_wpstow_upload_error', '该文件类型的存储源配置不完整');
            return $meta;
        }

        $uploadDir = wp_upload_dir();
        $uploadSucceeded = true;
        $mainUploaded = false;
        $uploadedKeys = [];
        $storageClass = self::getStorageClass($storageType);
        if (!$storageClass) {
            delete_post_meta($post_id, '_wpstow_pending');
            delete_post_meta($post_id, '_wpstow_pending_storage');
            update_post_meta($post_id, '_wpstow_upload_error', '无法获取存储驱动');
            return $meta;
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($meta['file']);

            foreach ($meta['sizes'] as $size => $value) {
                if (empty($value['file'])) {
                    continue;
                }

                $filepath = self::buildThumbFilepath($uploadDir['basedir'], $dir, $value['file']);
                if (!file_exists($filepath)) {
                    Utils::writeLog('缩略图文件不存在: ' . $filepath);
                    $uploadSucceeded = false;
                    continue;
                }

                $cloudKey = self::buildCloudKey($dir, $value['file']);
                Utils::writeLog('缩略图上传到云, cloudKey=' . $cloudKey);

                try {
                    $result = $storageClass::upload($filepath, $cloudKey);
                    if (!empty($result['status'])) {
                        Utils::writeLog('缩略图上传成功: ' . $cloudKey);
                        $uploadedKeys[] = $cloudKey;
                    } else {
                        $uploadSucceeded = false;
                        Utils::writeLog('缩略图上传失败: ' . json_encode($result));
                    }
                } catch (\Throwable $e) {
                    $uploadSucceeded = false;
                    Utils::writeLog('缩略图上传异常: ' . $e->getMessage());
                }
            }
        }

        Utils::writeLog('检查主文件, meta[file]=' . ($meta['file'] ?? 'null'));

        // 判断是否需要检查保留原文件（仅在图片或视频水印/压缩启用时）
        $imageCompressEnabled = self::config('image_compress') === 'yes';
        $imageWatermarkEnabled = self::config('image_watermark') === 'yes';
        $videoCompressEnabled = self::config('video_compress') === 'yes';
        $videoWatermarkEnabled = self::config('video_watermark') === 'yes';
        $needCheckKeepOriginal = $imageCompressEnabled || $imageWatermarkEnabled || $videoCompressEnabled || $videoWatermarkEnabled;
        $keepOriginal = !$needCheckKeepOriginal || self::config('keep_original') !== 'no';

        if (!empty($meta['file'])) {
            $mainFile = $uploadDir['basedir'] . '/' . $meta['file'];
            Utils::writeLog('主文件路径: ' . $mainFile . ', 存在=' . (file_exists($mainFile) ? 'yes' : 'no') . ', keepOriginal=' . ($keepOriginal ? 'yes' : 'no'));

            if (file_exists($mainFile)) {
                if ($keepOriginal) {
                    // 上传原图
                    $mainCloudKey = $meta['file'];
                    Utils::writeLog('上传主文件(元数据中的file): ' . $mainCloudKey);

                    try {
                        $result = $storageClass::upload($mainFile, $mainCloudKey);
                        if (!empty($result['status'])) {
                            Utils::writeLog('主文件上传成功: ' . $mainCloudKey);
                            update_post_meta($post_id, '_wpstow_cloud_key', $mainCloudKey);
                            $uploadedKeys[] = $mainCloudKey;
                            $mainUploaded = true;
                        } else {
                            $uploadSucceeded = false;
                            Utils::writeLog('主文件上传失败: ' . json_encode($result));
                        }
                    } catch (\Throwable $e) {
                        $uploadSucceeded = false;
                        Utils::writeLog('主文件上传异常: ' . $e->getMessage());
                    }
                } else {
                    // 不保留原图，使用最大尺寸的缩略图作为主图
                    Utils::writeLog('不保留原图模式，跳过主文件上传');
                    // 找到最大的缩略图作为 cloud_key
                    $maxSize = 0;
                    $maxSizeKey = '';
                    if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                        foreach ($meta['sizes'] as $size => $value) {
                            $area = ($value['width'] ?? 0) * ($value['height'] ?? 0);
                            if ($area > $maxSize) {
                                $maxSize = $area;
                                $maxSizeKey = $value['file'] ?? '';
                            }
                        }
                    }
                    if ($maxSizeKey) {
                        $dir = dirname($meta['file']);
                        $cloudKey = self::buildCloudKey($dir, $maxSizeKey);
                        update_post_meta($post_id, '_wpstow_cloud_key', $cloudKey);
                        $mainUploaded = true;
                        Utils::writeLog('使用最大缩略图作为主图: ' . $cloudKey);
                    }
                }
            } else {
                $uploadSucceeded = false;
                Utils::writeLog('主文件不存在，跳过上传');
            }
        } else {
            // PDF、音视频等附件的元数据可能没有 file，回退到附件主文件路径。
            $mainFile = get_attached_file($post_id);
            if ($mainFile && file_exists($mainFile)) {
                $mainCloudKey = StorageInterface::getCloudKey($mainFile);
                try {
                    $result = $storageClass::upload($mainFile, $mainCloudKey);
                    if (!empty($result['status'])) {
                        update_post_meta($post_id, '_wpstow_cloud_key', $mainCloudKey);
                        $uploadedKeys[] = $mainCloudKey;
                        $mainUploaded = true;
                    } else {
                        $uploadSucceeded = false;
                        Utils::writeLog('附件主文件上传失败: ' . json_encode($result));
                    }
                } catch (\Throwable $e) {
                    $uploadSucceeded = false;
                    Utils::writeLog('附件主文件上传异常: ' . $e->getMessage());
                }
            } else {
                $uploadSucceeded = false;
                Utils::writeLog('附件主文件不存在，跳过上传');
            }
        }

        if ($mainUploaded && $uploadSucceeded) {
            update_post_meta($post_id, '_wpstow_storage_manifest', self::buildStorageManifest(get_post_meta($post_id, '_wpstow_cloud_key', true), $meta, $storageType));
            update_post_meta($post_id, '_wpstow_storage_type', $storageType);
            update_post_meta($post_id, '_wpstow_uploaded', '1');
            delete_post_meta($post_id, '_wpstow_pending');
            delete_post_meta($post_id, '_wpstow_pending_storage');
            delete_post_meta($post_id, '_wpstow_upload_error');
            if (!self::shouldKeepLocalFiles()) {
                self::deleteLocalFiles($post_id, $meta);
            } else {
                Utils::writeLog('双副本模式：云端上传成功，本地文件已保留，附件 ID=' . $post_id);
            }
        } else {
            foreach (array_unique($uploadedKeys) as $uploadedKey) {
                $rolledBack = $storageClass::delete($uploadedKey);
                Utils::writeLog(($rolledBack ? '已回滚' : '回滚失败') . '本轮云端对象: ' . $uploadedKey);
            }
            delete_post_meta($post_id, '_wpstow_cloud_key');
            delete_post_meta($post_id, '_wpstow_pending');
            delete_post_meta($post_id, '_wpstow_pending_storage');
            update_post_meta($post_id, '_wpstow_upload_error', '云端上传未完整成功，本地文件已安全保留');
            Utils::writeLog('上传未完整成功，本地文件已保留，附件 ID=' . $post_id);
        }

        return $meta;
    }

    private static function deleteLocalFiles($post_id, $meta)
    {
        $uploadDir = wp_upload_dir();

        $originalFile = get_attached_file($post_id);
        if ($originalFile && file_exists($originalFile)) {
            if (@unlink($originalFile)) {
                Utils::writeLog('已删除本地原始文件: ' . $originalFile);
            } else {
                Utils::writeLog('删除本地原始文件失败，文件仍保留: ' . $originalFile);
            }
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($meta['file']);
            foreach ($meta['sizes'] as $size => $value) {
                if (empty($value['file'])) {
                    continue;
                }
                $filepath = self::buildThumbFilepath($uploadDir['basedir'], $dir, $value['file']);
                if (file_exists($filepath)) {
                    if (@unlink($filepath)) {
                        Utils::writeLog('已删除本地缩略图: ' . $filepath);
                    } else {
                        Utils::writeLog('删除本地缩略图失败，文件仍保留: ' . $filepath);
                    }
                }
            }
        }
    }

    public static function media_del_handle($post_id, $post)
    {
        if (!get_post_meta($post_id, '_wpstow_uploaded', true)) {
            return;
        }

        $meta = wp_get_attachment_metadata($post_id);
        $manifest = get_post_meta($post_id, '_wpstow_storage_manifest', true);
        $manifestType = is_array($manifest) ? ($manifest['storage_type'] ?? '') : '';
        $storedType = sanitize_key((string) get_post_meta($post_id, '_wpstow_storage_type', true));
        $storageClass = self::getStorageClass($manifestType ?: $storedType);
        if (!$storageClass) {
            return;
        }

        $manifestKeys = is_array($manifest) && !empty($manifest['keys']) && is_array($manifest['keys']) ? $manifest['keys'] : [];
        if ($manifestKeys) {
            foreach (array_unique($manifestKeys) as $cloudKey) {
                $deleted = $storageClass::delete($cloudKey);
                Utils::writeLog(($deleted ? '已删除' : '删除失败') . '云端清单对象: ' . $cloudKey);
            }
            delete_post_meta($post_id, '_wpstow_storage_manifest');
            delete_post_meta($post_id, '_wpstow_uploaded');
            delete_post_meta($post_id, '_wpstow_cloud_key');
            delete_post_meta($post_id, '_wpstow_storage_type');
            return;
        }

        $mainKey = get_post_meta($post_id, '_wpstow_cloud_key', true);
        if (!empty($mainKey)) {
            $deleted = $storageClass::delete($mainKey);
            Utils::writeLog(($deleted ? '已删除' : '删除失败') . '云端主文件: ' . $mainKey);
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes']) && !empty($meta['file'])) {
            $dir = dirname($meta['file']);
            foreach ($meta['sizes'] as $value) {
                if (empty($value['file'])) {
                    continue;
                }
                $cloudKey = self::buildCloudKey($dir, $value['file']);
                if ($cloudKey === $mainKey) {
                    continue;
                }
                $deleted = $storageClass::delete($cloudKey);
                Utils::writeLog(($deleted ? '已删除' : '删除失败') . '云端缩略图: ' . $cloudKey);
            }
        }

        delete_post_meta($post_id, '_wpstow_uploaded');
        delete_post_meta($post_id, '_wpstow_cloud_key');
        delete_post_meta($post_id, '_wpstow_storage_manifest');
        delete_post_meta($post_id, '_wpstow_storage_type');
    }

    public static function attachment_editor($form_fields, $post)
    {
        $post_id = $post->ID;
        $status = self::getProcessingStatus($post_id);
        $uploaded = $status['uploaded'];
        $label = $uploaded ? $status['label'] : ($status['code'] === 'error' ? esc_html__('重试处理', 'wpstow') : esc_html__('立即处理', 'wpstow'));
        $disabled = ($uploaded || !$status['can_process']) ? 'disabled' : '';
        $modeHtml = !empty($status['url_mode_label']) ? '<span class="wpstow-media-status__mode">当前链接：' . esc_html($status['url_mode_label']) . '</span>' : '';
        $actionHtml = '';
        if ($status['code'] !== 'local') {
            $actionHtml = '<script>var wpstow_js_flag="page";var wpstow_ajax_url="' . esc_url(admin_url('admin-ajax.php')) . '";var wpstow_nonce="' . esc_js(wp_create_nonce('wpstow_admin')) . '";var post_id="' . intval($post_id) . '";</script>'
                . "<button type='button' class='button button-secondary' id='wpstow-upload-one' $disabled>" . esc_html($label) . '</button>'
                . '<script src="' . esc_url(plugins_url('../static/post.js', __FILE__)) . '"></script>';
        }
        $form_fields["upload-to-wpstow"] = [
            "label" => esc_html__("云端存储", "wpstow"),
            "input" => "html",
            "html" => '<div class="wpstow-media-status wpstow-media-status--' . esc_attr($status['code']) . '"><strong class="wpstow-media-status__badge">' . esc_html($status['label']) . '</strong>' . $modeHtml . '<span class="wpstow-media-status__message">' . esc_html($status['message']) . '</span>' . $actionHtml . '</div>',
            "helps" => esc_html__("手动执行 WPStow 云端处理。启用双副本时不会删除本地文件。", "wpstow")
        ];
        return $form_fields;
    }

    public static function update_to_cloud($post_id)
    {
        $storageType = self::getStorageTypeForAttachment($post_id);
        if ($storageType === 'local') {
            update_post_meta($post_id, '_wpstow_upload_error', '该文件类型配置为仅本地，不需要云端处理');
            return false;
        }
        if (!self::isStorageEnabledAndConfigured($post_id)) {
            update_post_meta($post_id, '_wpstow_upload_error', '自动转存未启用或该文件类型的存储源配置不完整');
            return false;
        }

        $meta = wp_get_attachment_metadata($post_id);
        if (!is_array($meta)) {
            $meta = [];
        }

        if (get_post_meta($post_id, '_wpstow_uploaded', true)) {
            Utils::writeLog('update_to_cloud: 文件已上传至云端');
            return true;
        }

        $storageClass = self::getStorageClass($storageType);
        if (!$storageClass) {
            update_post_meta($post_id, '_wpstow_upload_error', '无法获取存储驱动');
            return false;
        }

        $uploadDir = wp_upload_dir();
        $allUploaded = true;
        $mainUploaded = false;
        $uploadedKeys = [];

        $originalFile = get_attached_file($post_id);
        if ($originalFile && file_exists($originalFile)) {
            $cloudKey = StorageInterface::getCloudKey($originalFile);
            Utils::writeLog('一键替换: 上传原始文件, cloudKey=' . $cloudKey);
            try {
                $result = $storageClass::upload($originalFile, $cloudKey);
            } catch (\Throwable $e) {
                $result = ['status' => false, 'message' => $e->getMessage()];
            }
            if (!empty($result['status'])) {
                $mainUploaded = true;
                $uploadedKeys[] = $cloudKey;
                update_post_meta($post_id, '_wpstow_cloud_key', $cloudKey);
            } else {
                $allUploaded = false;
                Utils::writeLog('一键替换: 原始文件上传失败: ' . json_encode($result));
            }
        } else {
            $allUploaded = false;
        }

        if (!empty($meta['sizes']) && is_array($meta['sizes']) && !empty($meta['file'])) {
            $dir = dirname($meta['file']);
            foreach ($meta['sizes'] as $value) {
                if (empty($value['file'])) {
                    continue;
                }
                $filepath = self::buildThumbFilepath($uploadDir['basedir'], $dir, $value['file']);
                if (!file_exists($filepath)) {
                    $allUploaded = false;
                    continue;
                }
                $thumbKey = self::buildCloudKey($dir, $value['file']);
                try {
                    $result = $storageClass::upload($filepath, $thumbKey);
                } catch (\Throwable $e) {
                    $result = ['status' => false, 'message' => $e->getMessage()];
                }
                if (empty($result['status'])) {
                    $allUploaded = false;
                    Utils::writeLog('一键替换: 缩略图上传失败: ' . $thumbKey);
                } else {
                    $uploadedKeys[] = $thumbKey;
                }
            }
        }

        if (!$mainUploaded || !$allUploaded) {
            foreach (array_unique($uploadedKeys) as $uploadedKey) {
                $rolledBack = $storageClass::delete($uploadedKey);
                Utils::writeLog(($rolledBack ? '已回滚' : '回滚失败') . '一键处理对象: ' . $uploadedKey);
            }
            delete_post_meta($post_id, '_wpstow_cloud_key');
            update_post_meta($post_id, '_wpstow_upload_error', '云端上传未完整成功，本地文件已安全保留');
            return false;
        }

        update_post_meta($post_id, '_wpstow_uploaded', '1');
        update_post_meta($post_id, '_wpstow_storage_manifest', self::buildStorageManifest(get_post_meta($post_id, '_wpstow_cloud_key', true), $meta, $storageType));
        update_post_meta($post_id, '_wpstow_storage_type', $storageType);
        delete_post_meta($post_id, '_wpstow_upload_error');
        if (!self::shouldKeepLocalFiles()) {
            self::deleteLocalFiles($post_id, $meta);
        } else {
            Utils::writeLog('一键处理: 云端上传完成，本地文件已保留');
        }
        return true;
    }

    public static function replaced_one()
    {
        check_ajax_referer('wpstow_admin', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => '权限不足']);
        }
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => '缺少附件 ID']);
        }
        if (get_post_type($post_id) !== 'attachment' || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => '无权操作该附件'], 403);
        }
        self::update_to_cloud($post_id);
        if (get_post_meta($post_id, '_wpstow_uploaded', true)) {
            wp_send_json_success([
                'message' => self::shouldKeepLocalFiles() ? '处理完成：云端与本地副本均已保留' : '处理完成：已上传云端',
                'status' => self::getProcessingStatus($post_id),
            ]);
        }
        wp_send_json_error(['message' => get_post_meta($post_id, '_wpstow_upload_error', true) ?: '上传失败，本地文件已保留']);
    }

    public static function test_storage_connection()
    {
        check_ajax_referer('wpstow_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
        }

        $storage_type = sanitize_text_field(wp_unslash($_POST['storage_type'] ?? ''));
        $stored = get_option('wpstow_setting');
        $candidate = is_array($stored) ? $stored : @unserialize($stored, ['allowed_classes' => false]);
        if (!is_array($candidate)) {
            $candidate = [];
        }
        $candidate['storage_type'] = in_array($storage_type, ['oneimg', 's3', 'webdav', 'ftp'], true) ? $storage_type : 's3';
        $candidate['switch'] = 'enable';

        if ($candidate['storage_type'] === 'oneimg') {
            $candidate['oneimg_endpoint'] = esc_url_raw(wp_unslash($_POST['oneimg_endpoint'] ?? ''));
            $candidate['oneimg_token'] = trim((string) wp_unslash($_POST['oneimg_token'] ?? '')) !== ''
                ? sanitize_text_field(wp_unslash($_POST['oneimg_token']))
                : ($candidate['oneimg_token'] ?? '');
            $result = self::withRuntimeConfig($candidate, function () {
                return OneImgStorage::testConnection();
            });
        } elseif ($candidate['storage_type'] === 's3') {
            $candidate['s3_endpoint'] = esc_url_raw(wp_unslash($_POST['s3_endpoint'] ?? ''));
            $candidate['s3_access_key'] = trim((string) wp_unslash($_POST['s3_access_key'] ?? '')) !== ''
                ? sanitize_text_field(wp_unslash($_POST['s3_access_key']))
                : ($candidate['s3_access_key'] ?? '');
            $candidate['s3_secret_key'] = trim((string) wp_unslash($_POST['s3_secret_key'] ?? '')) !== ''
                ? sanitize_text_field(wp_unslash($_POST['s3_secret_key']))
                : ($candidate['s3_secret_key'] ?? '');
            $candidate['s3_bucket'] = sanitize_text_field(wp_unslash($_POST['s3_bucket'] ?? ''));
            $candidate['s3_region'] = sanitize_text_field(wp_unslash($_POST['s3_region'] ?? ''));
            $candidate['s3_path_style'] = (isset($_POST['s3_path_style']) && wp_unslash($_POST['s3_path_style']) === 'yes') ? 'yes' : 'no';
            $result = self::withRuntimeConfig($candidate, function () {
                return S3Storage::testConnection();
            });
        } elseif ($candidate['storage_type'] === 'webdav') {
            $candidate['webdav_endpoint'] = esc_url_raw(wp_unslash($_POST['webdav_endpoint'] ?? ''));
            $candidate['webdav_username'] = sanitize_text_field(wp_unslash($_POST['webdav_username'] ?? ''));
            $candidate['webdav_password'] = trim((string) wp_unslash($_POST['webdav_password'] ?? '')) !== ''
                ? sanitize_text_field(wp_unslash($_POST['webdav_password']))
                : ($candidate['webdav_password'] ?? '');
            $candidate['webdav_path'] = sanitize_text_field(wp_unslash($_POST['webdav_path'] ?? '/'));
            $result = self::withRuntimeConfig($candidate, function () {
                return WebDAVStorage::testConnection();
            });
        } elseif ($candidate['storage_type'] === 'ftp') {
            $candidate['ftp_host'] = sanitize_text_field(wp_unslash($_POST['ftp_host'] ?? ''));
            $candidate['ftp_port'] = min(65535, max(1, intval($_POST['ftp_port'] ?? 21)));
            $candidate['ftp_username'] = sanitize_text_field(wp_unslash($_POST['ftp_username'] ?? ''));
            $candidate['ftp_password'] = trim((string) wp_unslash($_POST['ftp_password'] ?? '')) !== ''
                ? sanitize_text_field(wp_unslash($_POST['ftp_password']))
                : ($candidate['ftp_password'] ?? '');
            $candidate['ftp_path'] = sanitize_text_field(wp_unslash($_POST['ftp_path'] ?? '/'));
            $candidate['ftp_passive'] = (isset($_POST['ftp_passive']) && wp_unslash($_POST['ftp_passive']) === 'no') ? 'no' : 'yes';
            $candidate['ftp_ssl'] = (isset($_POST['ftp_ssl']) && wp_unslash($_POST['ftp_ssl']) === 'yes') ? 'yes' : 'no';
            $result = self::withRuntimeConfig($candidate, function () {
                return FTPStorage::testConnection();
            });
        } else {
            $result = ['status' => false, 'message' => '未知存储类型'];
        }

        wp_send_json($result);
    }

    public static function filterAttachmentUrl($url, $post_id)
    {
        if (!MediaProxy::isCloudAttachment($post_id)) {
            return $url;
        }

        $cloudKey = get_post_meta($post_id, '_wpstow_cloud_key', true);
        if (empty($cloudKey)) {
            $filepath = get_attached_file($post_id);
            if ($filepath && file_exists($filepath)) {
                $cloudKey = StorageInterface::getCloudKey($filepath);
            } else {
                $meta = wp_get_attachment_metadata($post_id);
                $cloudKey = $meta['file'] ?? '';
            }
        }

        if (empty($cloudKey)) {
            return $url;
        }

        if (!self::shouldRewriteCloudUrls($post_id, $cloudKey, true)) {
            return $url;
        }

        return MediaProxy::getProxyUrl($cloudKey, $post_id);
    }

    public static function filterAttachmentImageSrc($image, $attachment_id, $size, $icon)
    {
        if (!$image) {
            return $image;
        }

        $isCloud = MediaProxy::isCloudAttachment($attachment_id);
        Utils::debugLog("filterAttachmentImageSrc called: post_id=$attachment_id, isCloud=$isCloud, size=" . (is_string($size) ? $size : 'unknown'));

        if (!$isCloud) {
            return $image;
        }

        $cloudKey = self::getCloudKeyForImageSize($attachment_id, $size);

        Utils::debugLog("filterAttachmentImageSrc: cloudKey=$cloudKey");

        if ($cloudKey && self::shouldRewriteCloudUrls($attachment_id, $cloudKey, $size === 'full')) {
            $image[0] = MediaProxy::getProxyUrl($cloudKey, $attachment_id);
            Utils::debugLog("filterAttachmentImageSrc: replaced with " . $image[0]);
        }

        return $image;
    }

    public static function getCloudKeyForImageSize($attachment_id, $size)
    {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta['file'])) {
            return '';
        }
        if ($size === 'full') {
            return get_post_meta($attachment_id, '_wpstow_cloud_key', true) ?: $meta['file'];
        }
        $dir = dirname($meta['file']);
        if (is_string($size) && !empty($meta['sizes'][$size]['file'])) {
            return self::buildCloudKey($dir, $meta['sizes'][$size]['file']);
        }
        if (is_array($size) && !empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $candidate) {
                if (!empty($candidate['width']) && !empty($candidate['height'])
                    && (int) $candidate['width'] === (int) $size[0]
                    && (int) $candidate['height'] === (int) $size[1]
                    && !empty($candidate['file'])) {
                    return self::buildCloudKey($dir, $candidate['file']);
                }
            }
        }
        return get_post_meta($attachment_id, '_wpstow_cloud_key', true) ?: $meta['file'];
    }

    public static function filterAttachmentMetadata($data, $post_id)
    {
        if (!$data || !MediaProxy::isCloudAttachment($post_id)) {
            return $data;
        }

        return $data;
    }

    public static function filterAttachmentLink($link, $post, $size)
    {
        $attachment_id = is_object($post) ? (int) $post->ID : (int) $post;
        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return $link;
        }

        $cloudKey = get_post_meta($attachment_id, '_wpstow_cloud_key', true);
        if (empty($cloudKey)) {
            return $link;
        }

        if (!self::shouldRewriteCloudUrls($attachment_id, $cloudKey, true)) {
            return $link;
        }

        $proxyUrl = esc_url(MediaProxy::getProxyUrl($cloudKey, $attachment_id));
        return preg_replace('/href=(["\'])[^"\']*\1/i', 'href="$proxyUrl"', $link, 1);
    }

    public static function filterImageSrcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return $sources;
        }

        if (!$sources || empty($image_meta['sizes'])) {
            return $sources;
        }

        $dir = dirname($image_meta['file']);
        foreach ($sources as $width => $source) {
            if (isset($image_meta['sizes'])) {
                foreach ($image_meta['sizes'] as $size_name => $size_data) {
                    $sourcePath = isset($source['url']) ? parse_url($source['url'], PHP_URL_PATH) : '';
                    $sourceUrl = $sourcePath ? wp_basename($sourcePath) : '';
                    if (!empty($size_data['file']) && ($sourceUrl === $size_data['file'] || ($sourceUrl === '' && isset($size_data['width']) && $size_data['width'] == $width))) {
                        $thumbKey = self::buildCloudKey($dir, $size_data['file']);
                        if (self::shouldRewriteCloudUrls($attachment_id, $thumbKey, false)) {
                            $sources[$width]['url'] = MediaProxy::getProxyUrl($thumbKey, $attachment_id);
                        }
                        break;
                    }
                }
            }
        }

        return $sources;
    }

    public static function filterAttachmentsUrl($url, $attachment_id) {
        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return $url;
        }
        $cloudKey = get_post_meta($attachment_id, '_wpstow_cloud_key', true);
        if (!$cloudKey) {
            return $url;
        }
        if (!self::shouldRewriteCloudUrls($attachment_id, $cloudKey, true)) {
            return $url;
        }
        return MediaProxy::getProxyUrl($cloudKey, $attachment_id);
    }

    public static function filterMediaSendToEditor($html, $attachment_id, $data) {
        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return $html;
        }
        $cloudKey = get_post_meta($attachment_id, '_wpstow_cloud_key', true);
        if (!$cloudKey) {
            return $html;
        }
        if (!self::shouldRewriteCloudUrls($attachment_id, $cloudKey, true)) {
            return $html;
        }
        $proxyUrl = MediaProxy::getProxyUrl($cloudKey, $attachment_id);
        $html = preg_replace('/src="[^"]+"/', 'src="' . esc_url($proxyUrl) . '"', $html);
        $html = preg_replace('/href="[^"]+"/', 'href="' . esc_url($proxyUrl) . '"', $html);
        return $html;
    }

    /**
     * 处理 REST API 返回的附件数据 (媒体库网格视图)
     */
    public static function filterRestAttachment($response, $post) {
        $attachment_id = $post->ID;

        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return $response;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || empty($meta['file'])) {
            return $response;
        }

        Utils::debugLog("filterRestAttachment: 处理附件 ID=$attachment_id");

        $dir = dirname($meta['file']);
        $mainCloudKey = get_post_meta($attachment_id, '_wpstow_cloud_key', true) ?: $meta['file'];

        // 处理 source_url (原始图片URL)
        if (isset($response->data['source_url']) && self::shouldRewriteCloudUrls($attachment_id, $mainCloudKey, true)) {
            $response->data['source_url'] = MediaProxy::getProxyUrl($mainCloudKey, $attachment_id);
        }

        // 处理 media_details 中的 sizes (缩略图URL)
        if (isset($response->data['media_details']['sizes']) && is_array($response->data['media_details']['sizes'])) {
            foreach ($response->data['media_details']['sizes'] as $size_name => &$size_data) {
                if (!empty($size_data['source_url'])) {
                    $cloudKey = self::buildCloudKey($dir, $size_data['file'] ?? $meta['file']);
                    if (self::shouldRewriteCloudUrls($attachment_id, $cloudKey, false)) {
                        $size_data['source_url'] = MediaProxy::getProxyUrl($cloudKey, $attachment_id);
                    }
                }
            }
        }

        // 处理标题图片 URL (媒体库缩略图)
        if (isset($response->data['title']['rendered'])) {
            // 保持标题不变
        }

        // 处理媒体库列表中显示的图标/缩略图
        if (isset($response->data['link']) && self::shouldRewriteCloudUrls($attachment_id, $mainCloudKey, true)) {
            $response->data['link'] = MediaProxy::getProxyUrl($mainCloudKey, $attachment_id);
        }

        return $response;
    }

    /**
     * 处理媒体库网格视图 AJAX 请求返回的附件数据
     * 这是处理媒体库缩略图显示的关键方法
     */
    public static function filterPrepareAttachmentForJs($attachment, $post) {
        $attachment_id = $post->ID;

        // 无论是否已处理，都向媒体网格暴露状态和手动操作信息。
        $status = self::getProcessingStatus($attachment_id);
        $status['nonce'] = wp_create_nonce('wpstow_admin');
        $status['attachment_id'] = (int) $attachment_id;
        $attachment['wpstow'] = $status;

        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return $attachment;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || empty($meta['file'])) {
            return $attachment;
        }

        Utils::debugLog("filterPrepareAttachmentForJs: 处理附件 ID=$attachment_id, file=" . $meta['file']);

        $dir = dirname($meta['file']);
        $uploadDir = wp_upload_dir();
        $baseUrl = $uploadDir['baseurl'];
        $mainCloudKey = get_post_meta($attachment_id, '_wpstow_cloud_key', true) ?: $meta['file'];

        // 处理主文件 URL
        if (!empty($attachment['url']) && self::shouldRewriteCloudUrls($attachment_id, $mainCloudKey, true)) {
            $attachment['url'] = MediaProxy::getProxyUrl($mainCloudKey, $attachment_id);
        }

        // 处理缩略图尺寸
        if (!empty($attachment['sizes']) && is_array($attachment['sizes'])) {
            foreach ($attachment['sizes'] as $size_name => &$size_data) {
                if (!empty($size_data['url'])) {
                    // 从 sizes 中获取正确的文件名
                    $sizeFile = $size_data['file'] ?? ($meta['sizes'][$size_name]['file'] ?? '');
                    if ($sizeFile) {
                        $cloudKey = self::buildCloudKey($dir, $sizeFile);
                        if (self::shouldRewriteCloudUrls($attachment_id, $cloudKey, false)) {
                            $size_data['url'] = MediaProxy::getProxyUrl($cloudKey, $attachment_id);
                            Utils::debugLog("filterPrepareAttachmentForJs: 尺寸 $size_name URL -> " . $size_data['url']);
                        }
                    }
                }
            }
        }

        // 处理图标 (非图片文件的缩略图)
        if (!empty($attachment['icon']) && strpos($attachment['icon'], $baseUrl) !== false) {
            // 如果是默认图标，保持不变
        }

        return $attachment;
    }

    /**
     * 获取缩略图的代理URL
     */
    public static function getThumbProxyUrl($attachment_id, $size = 'thumbnail') {
        if (!MediaProxy::isCloudAttachment($attachment_id)) {
            return false;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta || empty($meta['file'])) {
            return false;
        }

        $dir = dirname($meta['file']);

        if ($size === 'full') {
            $mainCloudKey = get_post_meta($attachment_id, '_wpstow_cloud_key', true) ?: $meta['file'];
            if (!self::shouldRewriteCloudUrls($attachment_id, $mainCloudKey, true)) {
                return false;
            }
            return MediaProxy::getProxyUrl($mainCloudKey, $attachment_id);
        }

        if (!empty($meta['sizes'][$size]['file'])) {
            $cloudKey = self::buildCloudKey($dir, $meta['sizes'][$size]['file']);
            if (!self::shouldRewriteCloudUrls($attachment_id, $cloudKey, false)) {
                return false;
            }
            return MediaProxy::getProxyUrl($cloudKey, $attachment_id);
        }

        // 如果没有指定尺寸，返回原图
        $mainCloudKey = get_post_meta($attachment_id, '_wpstow_cloud_key', true) ?: $meta['file'];
        if (!self::shouldRewriteCloudUrls($attachment_id, $mainCloudKey, true)) {
            return false;
        }
        return MediaProxy::getProxyUrl($mainCloudKey, $attachment_id);
    }

    /**
     * AJAX 获取附件URL
     */
    public static function getAttachmentUrlAjax()
    {
        check_ajax_referer('wpstow_admin', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => '权限不足']);
        }
        $attachment_id = intval($_POST['attachment_id']);
        if (!$attachment_id) {
            wp_send_json_error(['message' => '缺少附件ID']);
        }
        if (get_post_type($attachment_id) !== 'attachment' || !current_user_can('edit_post', $attachment_id)) {
            wp_send_json_error(['message' => '无权读取该附件'], 403);
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            wp_send_json_error(['message' => '无法获取附件URL']);
        }

        wp_send_json_success(['url' => $url]);
    }
}
