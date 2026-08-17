<?php

namespace WPStow;
use WPStow\MediaHandler;
use WPStow\MediaProxy;
use WPStow\ImageLocalizer;
use WPStow\ImageProcessor;

add_action('wp_ajax_wpstow_test_storage_connection', [MediaHandler::class, 'test_storage_connection']);
add_action('wp_ajax_test_storage_connection', [MediaHandler::class, 'test_storage_connection']);
add_action('wp_ajax_wpstow_get_superbed_folders', [MediaHandler::class, 'get_superbed_folders']);
add_action('wp_ajax_wpstow_upload_one', [MediaHandler::class, 'replaced_one']);
add_action('wp_ajax_wpstow_scan_media_library', [MediaLibraryManager::class, 'ajaxScan']);
add_action('wp_ajax_wpstow_queue_start', [PersistentMediaQueue::class, 'ajaxStart']);
add_action('wp_ajax_wpstow_queue_status', [PersistentMediaQueue::class, 'ajaxStatus']);
add_action('wp_ajax_wpstow_queue_control', [PersistentMediaQueue::class, 'ajaxControl']);
add_action('wpstow_run_media_queue', [PersistentMediaQueue::class, 'runJob']);
add_action('wpstow_media_queue_watchdog', [PersistentMediaQueue::class, 'watchdog']);
add_action('wpstow_cloud_delete_watchdog', [CloudDeletionQueue::class, 'watchdog']);
add_filter('cron_schedules', [PersistentMediaQueue::class, 'addCronSchedule']);
add_action('plugins_loaded', [PersistentMediaQueue::class, 'maybeInstall'], 5);
add_action('plugins_loaded', [CloudDeletionQueue::class, 'maybeInstall'], 6);

// 获取附件URL的AJAX处理
add_action('wp_ajax_wpstow_get_attachment_url', [MediaHandler::class, 'getAttachmentUrlAjax']);

MediaProxy::init();
DirectUpload::init();

// 已迁移附件的读取和删除生命周期必须始终有效；总开关只控制新上传。
add_action('delete_attachment', [MediaHandler::class, 'media_del_handle'], 10, 2);
add_filter('wp_get_attachment_url', [MediaHandler::class, 'filterAttachmentUrl'], 10, 2);
add_filter('wp_get_attachment_image_src', [MediaHandler::class, 'filterAttachmentImageSrc'], 10, 4);
add_filter('wp_calculate_image_srcset', [MediaHandler::class, 'filterImageSrcset'], 10, 5);
add_filter('rest_prepare_attachment', [MediaHandler::class, 'filterRestAttachment'], 10, 2);
add_filter('wp_prepare_attachment_for_js', [MediaHandler::class, 'filterPrepareAttachmentForJs'], 10, 2);

// 媒体库列表：醒目展示处理状态并提供单文件处理入口。
add_filter('manage_media_columns', [MediaHandler::class, 'addMediaColumn']);
add_action('manage_media_custom_column', [MediaHandler::class, 'renderMediaColumn'], 10, 2);
add_filter('attachment_fields_to_edit', [MediaHandler::class, 'attachment_editor'], 10, 2);

// 原图单文件模式独立于云端转存开关，关闭自动转存时也应继续生效。
if (MediaHandler::config('disable_image_subsizes') === 'yes') {
    add_filter('big_image_size_threshold', [ImageProcessor::class, 'disableBigImageScaling'], PHP_INT_MAX, 4);
    add_filter('intermediate_image_sizes_advanced', [ImageProcessor::class, 'disableImageSubsizes'], PHP_INT_MAX, 3);
    add_filter('image_editor_output_format', [ImageProcessor::class, 'preserveUploadedImageFormat'], PHP_INT_MAX, 3);
    add_filter('wp_image_maybe_exif_rotate', [ImageProcessor::class, 'disableUploadedImageRotation'], PHP_INT_MAX, 2);
}

if (MediaHandler::config('switch') == 'enable') {
    // Run last so theme-level renamers cannot prepend another long filename afterwards.
    add_filter('wp_handle_upload_prefilter', [FileNaming::class, 'filterUploadFilename'], PHP_INT_MAX, 1);
    add_filter('wp_handle_sideload_prefilter', [FileNaming::class, 'filterUploadFilename'], PHP_INT_MAX, 1);
    add_action('add_attachment', [MediaHandler::class, 'add_attachment']);
    add_filter('wp_generate_attachment_metadata', [MediaHandler::class, 'generate_attachment_metadata'], 10, 3);
    add_filter('wp_attachments_s3_url', [MediaHandler::class, 'filterAttachmentsUrl'], 10, 2);

    // 图片格式转换和水印处理（在生成缩略图之前）
    add_filter('wp_handle_upload_prefilter', [ImageProcessor::class, 'handleUploadPrefilter'], 10, 1);
    add_filter('wp_handle_sideload_prefilter', [ImageProcessor::class, 'handleUploadPrefilter'], 10, 1);

    // 图片本地化（根据开关决定是否启用）
    if (MediaHandler::config('localize_images') === 'yes') {
        ImageLocalizer::init();
    }
}
