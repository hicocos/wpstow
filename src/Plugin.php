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

    public $s3_endpoint;
    public $s3_access_key;
    public $s3_secret_key;
    public $s3_bucket;
    public $s3_region;
    public $s3_path_style;
    public $s3_custom_url;

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
    public $image_compress;       // 图片压缩开关
    public $image_compress_quality; // 压缩质量
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
        $nonImageDefault = $legacyStorageType === 'oneimg' ? 'local' : $legacyStorageType;
        $this->image_storage_type = $setting['image_storage_type'] ?? $legacyStorageType;
        $this->video_storage_type = $setting['video_storage_type'] ?? $nonImageDefault;
        $this->audio_storage_type = $setting['audio_storage_type'] ?? $nonImageDefault;
        $this->other_storage_type = $setting['other_storage_type'] ?? $nonImageDefault;

        $this->oneimg_endpoint = $setting['oneimg_endpoint'] ?? '';
        $this->oneimg_token = $setting['oneimg_token'] ?? '';

        $this->s3_endpoint = $setting['s3_endpoint'] ?? '';
        $this->s3_access_key = $setting['s3_access_key'] ?? '';
        $this->s3_secret_key = $setting['s3_secret_key'] ?? '';
        $this->s3_bucket = $setting['s3_bucket'] ?? '';
        $this->s3_region = $setting['s3_region'] ?? 'us-east-1';
        $this->s3_path_style = $setting['s3_path_style'] ?? 'no';
        $this->s3_custom_url = $setting['s3_custom_url'] ?? '';

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
        $this->image_compress = $setting['image_compress'] ?? 'no';
        $this->image_compress_quality = $setting['image_compress_quality'] ?? 80;
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
        return self::getPluginDir() . 'logs/';
    }
}
