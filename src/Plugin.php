<?php

namespace WPStow;

abstract class Plugin
{
    public $storage_switch;
    public $storage_type;
    public $provider_config_type;
    public $image_storage_type;
    public $video_storage_type;
    public $audio_storage_type;
    public $other_storage_type;

    public $oneimg_endpoint;
    public $oneimg_token;

    public $superbed_endpoint;
    public $superbed_api_key;
    public $superbed_folder_id;

    public $s3_endpoint;
    public $s3_access_key;
    public $s3_secret_key;
    public $s3_bucket;
    public $s3_region;
    public $s3_path_style;
    public $s3_custom_url;

    public $r2_endpoint;
    public $r2_access_key;
    public $r2_secret_key;
    public $r2_bucket;
    public $r2_custom_url;
    public $r2_presign_ttl;

    public $webdav_endpoint;
    public $webdav_username;
    public $webdav_password;
    public $webdav_path;
    public $webdav_custom_url;

    public $ftp_host;
    public $ftp_port;
    public $ftp_username;
    public $ftp_password;
    public $ftp_path;
    public $ftp_passive;
    public $ftp_ssl;
    public $ftp_custom_url;

    // 功能开关
    public $localize_images;      // 图片本地化开关
    public $disable_image_subsizes; // 禁止生成缩略图、缩放图等派生文件
    public $image_format_conversion; // WebP 格式转换开关
    public $image_webp_quality;   // WebP 转换质量
    public $image_watermark;      // 水印开关
    public $watermark_type;       // 水印类型: text/image
    public $watermark_text;       // 水印文字
    public $watermark_position;   // 水印位置
    public $watermark_opacity;    // 水印透明度
    public $watermark_image;      // 水印图片ID
    public $keep_original;        // 保留原图开关
    public $keep_local;           // 云端上传成功后保留本地副本
    public $cloud_fallback_local; // 云端读取失败时回退本地副本
    public $media_url_mode;       // 已处理媒体链接模式: cloud/local
    public $filename_preset;      // 新上传文件命名预设
    public $filename_template;    // 自定义文件名模板

    // 视频处理
    public $video_compress;       // 视频压缩开关
    public $video_compress_quality; // 视频压缩质量: low/medium/high
    public $video_max_resolution; // 最大分辨率
    public $video_watermark;      // 视频水印开关

    protected static $instance = null;

    public function __construct()
    {
        $runtimeConfigOverride = class_exists(MediaHandler::class) ? MediaHandler::getRuntimeConfigOverride() : null;
        if (is_array($runtimeConfigOverride)) {
            $setting = $runtimeConfigOverride;
        } else {
            $setting = get_option('wpstow_setting');
            if ($setting && !is_array($setting)) {
                $setting = @unserialize($setting, ['allowed_classes' => false]);
            }
        }

        if (!is_array($setting)) {
            $setting = [];
        }

        $this->storage_switch = $setting['switch'] ?? 'disable';
        $this->storage_type = $setting['storage_type'] ?? 's3';
        $this->provider_config_type = $setting['provider_config_type'] ?? $this->storage_type;
        $legacyStorageType = $this->storage_type;
        $nonImageDefault = in_array($legacyStorageType, ['oneimg', 'superbed'], true) ? 'local' : $legacyStorageType;
        $this->image_storage_type = $setting['image_storage_type'] ?? $legacyStorageType;
        $this->video_storage_type = $setting['video_storage_type'] ?? $nonImageDefault;
        $this->audio_storage_type = $setting['audio_storage_type'] ?? $nonImageDefault;
        $this->other_storage_type = $setting['other_storage_type'] ?? $nonImageDefault;

        $this->oneimg_endpoint = $setting['oneimg_endpoint'] ?? '';
        $this->oneimg_token = $setting['oneimg_token'] ?? '';

        $this->superbed_endpoint = $setting['superbed_endpoint'] ?? 'https://api.superbed.cc';
        $this->superbed_api_key = $setting['superbed_api_key'] ?? '';
        $this->superbed_folder_id = $setting['superbed_folder_id'] ?? '';

        $this->s3_endpoint = $setting['s3_endpoint'] ?? '';
        $this->s3_access_key = $setting['s3_access_key'] ?? '';
        $this->s3_secret_key = $setting['s3_secret_key'] ?? '';
        $this->s3_bucket = $setting['s3_bucket'] ?? '';
        $this->s3_region = $setting['s3_region'] ?? 'us-east-1';
        $this->s3_path_style = $setting['s3_path_style'] ?? 'no';
        $this->s3_custom_url = $setting['s3_custom_url'] ?? '';

        $this->r2_endpoint = $setting['r2_endpoint'] ?? '';
        $this->r2_access_key = $setting['r2_access_key'] ?? '';
        $this->r2_secret_key = $setting['r2_secret_key'] ?? '';
        $this->r2_bucket = $setting['r2_bucket'] ?? '';
        $this->r2_custom_url = $setting['r2_custom_url'] ?? '';
        $this->r2_presign_ttl = $setting['r2_presign_ttl'] ?? 900;

        $this->webdav_endpoint = $setting['webdav_endpoint'] ?? '';
        $this->webdav_username = $setting['webdav_username'] ?? '';
        $this->webdav_password = $setting['webdav_password'] ?? '';
        $this->webdav_path = $setting['webdav_path'] ?? '/';
        $this->webdav_custom_url = $setting['webdav_custom_url'] ?? '';

        $this->ftp_host = $setting['ftp_host'] ?? '';
        $this->ftp_port = $setting['ftp_port'] ?? 21;
        $this->ftp_username = $setting['ftp_username'] ?? '';
        $this->ftp_password = $setting['ftp_password'] ?? '';
        $this->ftp_path = $setting['ftp_path'] ?? '/';
        $this->ftp_passive = $setting['ftp_passive'] ?? 'yes';
        $this->ftp_ssl = $setting['ftp_ssl'] ?? 'no';
        $this->ftp_custom_url = $setting['ftp_custom_url'] ?? '';

        // 功能开关初始化
        $this->localize_images = $setting['localize_images'] ?? 'no';
        $this->disable_image_subsizes = $setting['disable_image_subsizes'] ?? 'no';
        $this->image_format_conversion = $setting['image_format_conversion'] ?? 'no';
        $this->image_webp_quality = $setting['image_webp_quality'] ?? 82;
        $this->image_watermark = $setting['image_watermark'] ?? 'no';
        $this->watermark_type = $setting['watermark_type'] ?? 'text';
        $this->watermark_text = $setting['watermark_text'] ?? '';
        $this->watermark_position = $setting['watermark_position'] ?? 'bottom-right';
        $this->watermark_opacity = $setting['watermark_opacity'] ?? 50;
        $this->watermark_image = $setting['watermark_image'] ?? 0;
        $this->keep_original = $setting['keep_original'] ?? 'yes';
        // 数据安全优先：旧版本没有这两个选项时默认启用双副本和本地回退。
        $this->keep_local = $setting['keep_local'] ?? 'yes';
        $this->cloud_fallback_local = $setting['cloud_fallback_local'] ?? 'yes';
        // 兼容旧版本：默认继续使用云端链接；管理员可一键切换到本地链接。
        $this->media_url_mode = (($setting['media_url_mode'] ?? 'cloud') === 'local') ? 'local' : 'cloud';
        $this->filename_preset = $setting['filename_preset'] ?? FileNaming::DEFAULT_PRESET;
        $this->filename_template = $setting['filename_template'] ?? FileNaming::DEFAULT_TEMPLATE;

        // 视频处理
        $this->video_compress = $setting['video_compress'] ?? 'no';
        $this->video_compress_quality = $setting['video_compress_quality'] ?? 'medium';
        $this->video_max_resolution = $setting['video_max_resolution'] ?? '1080p';
        $this->video_watermark = $setting['video_watermark'] ?? 'no';
    }

    public static function getPluginDir()
    {
        return defined('WPSTOW_PLUGIN_DIR') ? WPSTOW_PLUGIN_DIR : WP_PLUGIN_DIR . '/wpstow/';
    }

    public static function getLogDir()
    {
        if (defined('WPSTOW_LOG_DIR') && WPSTOW_LOG_DIR) {
            return trailingslashit((string) WPSTOW_LOG_DIR);
        }

        $tempDir = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $siteKey = substr(hash('sha256', defined('ABSPATH') ? ABSPATH : self::getPluginDir()), 0, 12);
        return trailingslashit($tempDir) . 'wpstow-' . $siteKey . '/';
    }
}
