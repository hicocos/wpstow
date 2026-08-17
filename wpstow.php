<?php

/**
 * Plugin Name: WPStow
 * Plugin URI: https://github.com/hicocos/wpstow
 * Update URI: https://github.com/hicocos/wpstow
 * Description: 将 WordPress 媒体上传至云端存储（支持聚合图床/OneImg/S3/R2/WebDAV/FTP）
 * Version: 2.0.3
 * Author: 梅零落
 * Requires PHP: 7.4
 * Text Domain: wpstow
 * License: MIT
 * License URI: https://opensource.org/license/mit/
 */

if (!defined('ABSPATH')) exit;

define('WPSTOW_VERSION', '2.0.3');
define('WPSTOW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPSTOW_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Use an existing Codestar Framework when the active theme or another plugin
 * provides it. Otherwise load the bundled copy after the theme has initialized.
 */
function wpstow_load_csf() {
    if (!is_admin() || class_exists('CSF', false)) {
        return;
    }

    $framework_file = WPSTOW_PLUGIN_DIR . 'vendor/codestar-framework/codestar-framework.php';
    if (is_readable($framework_file)) {
        add_filter('csf_welcome_page', '__return_false');
        require_once $framework_file;
    }
}
add_action('after_setup_theme', 'wpstow_load_csf', 100);

require_once plugin_dir_path(__FILE__) . '/autoload.php';

use WPStow\MediaHandler;
use WPStow\MediaProxy;
use WPStow\Update;
use WPStow\Utils;

/**
 * Preserve settings and attachment state when upgrading from the former plugin name.
 */
function wpstow_migrate_legacy_data() {
    if (get_option('wpstow_legacy_migration_version') === '2') {
        return;
    }

    $legacy_setting = get_option('vemedia_setting', null);
    if (get_option('wpstow_setting', null) === null && $legacy_setting !== null) {
        add_option('wpstow_setting', $legacy_setting, '', false);
    }

    $legacy_rewrite_version = get_option('vemedia_rewrite_rules_version', null);
    if (get_option('wpstow_rewrite_rules_version', null) === null && $legacy_rewrite_version !== null) {
        add_option('wpstow_rewrite_rules_version', $legacy_rewrite_version, '', false);
    }

    global $wpdb;
    $meta_keys = [
        '_vemedia_uploaded' => '_wpstow_uploaded',
        '_vemedia_cloud_key' => '_wpstow_cloud_key',
        '_vemedia_storage_type' => '_wpstow_storage_type',
        '_vemedia_storage_manifest' => '_wpstow_storage_manifest',
        '_vemedia_pending' => '_wpstow_pending',
        '_vemedia_pending_storage' => '_wpstow_pending_storage',
        '_vemedia_upload_error' => '_wpstow_upload_error',
    ];

    foreach ($meta_keys as $legacy_key => $new_key) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT legacy.post_id, %s, legacy.meta_value
             FROM {$wpdb->postmeta} AS legacy
             LEFT JOIN {$wpdb->postmeta} AS current
               ON current.post_id = legacy.post_id AND current.meta_key = %s
             WHERE legacy.meta_key = %s AND current.meta_id IS NULL",
            $new_key,
            $new_key,
            $legacy_key
        ));
    }

    // Direct SQL migration bypasses WordPress metadata cache invalidation.
    // Clear affected persistent-cache entries so legacy attachments are not re-uploaded.
    $migrated_post_ids = $wpdb->get_col(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wpstow_uploaded', '_wpstow_cloud_key', '_wpstow_storage_manifest', '_wpstow_storage_type')"
    );
    foreach ($migrated_post_ids as $migrated_post_id) {
        wp_cache_delete((int) $migrated_post_id, 'post_meta');
    }

    update_option('wpstow_legacy_migration_version', '2', false);
}

wpstow_migrate_legacy_data();

/**
 * Split legacy Cloudflare R2 settings and attachment ownership from generic S3.
 */
function wpstow_migrate_r2_storage() {
    if (get_option('wpstow_r2_migration_version') === '2') {
        return;
    }

    $setting = get_option('wpstow_setting');
    if ($setting && !is_array($setting)) {
        $setting = @unserialize($setting, ['allowed_classes' => false]);
    }
    $setting = is_array($setting) ? $setting : [];
    $endpoint_host = strtolower((string) parse_url((string) ($setting['s3_endpoint'] ?? ''), PHP_URL_HOST));
    $r2_suffix = '.r2.cloudflarestorage.com';
    $is_legacy_r2 = $endpoint_host !== ''
        && strlen($endpoint_host) > strlen($r2_suffix)
        && substr($endpoint_host, -strlen($r2_suffix)) === $r2_suffix;
    $legacy_endpoint = rtrim((string) ($setting['s3_endpoint'] ?? ''), '/');
    $existing_r2_endpoint = rtrim((string) ($setting['r2_endpoint'] ?? ''), '/');
    $has_conflicting_r2 = $existing_r2_endpoint !== '' && $existing_r2_endpoint !== $legacy_endpoint;

    if ($is_legacy_r2 && !$has_conflicting_r2) {
        foreach (['endpoint', 'access_key', 'secret_key', 'bucket', 'custom_url'] as $field) {
            if (empty($setting['r2_' . $field])) {
                $setting['r2_' . $field] = $setting['s3_' . $field] ?? '';
            }
        }
        if (empty($setting['r2_presign_ttl'])) {
            $setting['r2_presign_ttl'] = 900;
        }
        foreach (['storage_type', 'provider_config_type', 'image_storage_type', 'video_storage_type', 'audio_storage_type', 'other_storage_type'] as $route_key) {
            if (($setting[$route_key] ?? '') === 's3') {
                $setting[$route_key] = 'r2';
            }
        }
        update_option('wpstow_setting', $setting, false);

        global $wpdb;
        foreach (['_wpstow_storage_type', '_wpstow_pending_storage'] as $meta_key) {
            $affected_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                $meta_key,
                's3'
            ));
            $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => 'r2'],
                ['meta_key' => $meta_key, 'meta_value' => 's3'],
                ['%s'],
                ['%s', '%s']
            );
            foreach ($affected_ids as $post_id) {
                wp_cache_delete((int) $post_id, 'post_meta');
            }
        }

        $manifest_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            '_wpstow_storage_manifest',
            '%' . $wpdb->esc_like('s:12:"storage_type";s:2:"s3";') . '%'
        ));
        foreach ($manifest_ids as $post_id) {
            $manifest = get_post_meta((int) $post_id, '_wpstow_storage_manifest', true);
            if (is_array($manifest) && ($manifest['storage_type'] ?? '') === 's3') {
                $manifest['storage_type'] = 'r2';
                $manifest['storage_identity'] = [
                    'endpoint' => $setting['r2_endpoint'],
                    'bucket' => $setting['r2_bucket'],
                ];
                update_post_meta((int) $post_id, '_wpstow_storage_manifest', $manifest);
            }
        }

        foreach (['endpoint', 'access_key', 'secret_key', 'bucket', 'custom_url'] as $field) {
            $setting['s3_' . $field] = '';
        }
        $setting['s3_region'] = 'us-east-1';
        $setting['s3_path_style'] = 'no';
        update_option('wpstow_setting', $setting, false);
    }

    update_option('wpstow_r2_migration_version', '2', false);
}
wpstow_migrate_r2_storage();

add_action('admin_init', 'wpstow_redirect_legacy_settings_page', 1);
function wpstow_redirect_legacy_settings_page() {
    if (
        current_user_can('manage_options')
        && isset($_GET['page'])
        && sanitize_key(wp_unslash($_GET['page'])) === 'vemedia_settings'
    ) {
        wp_safe_redirect(admin_url('admin.php?page=wpstow_settings'));
        exit;
    }
}

register_activation_hook(__FILE__, 'wpstow_activate');
function wpstow_activate() {
    wpstow_migrate_legacy_data();
    wpstow_migrate_r2_storage();
    \WPStow\PersistentMediaQueue::install();
    \WPStow\CloudDeletionQueue::install();

    $log_dir = \WPStow\Plugin::getLogDir();
    if (!is_dir($log_dir)) {
        wp_mkdir_p($log_dir);
        file_put_contents($log_dir . '.htaccess', "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        file_put_contents($log_dir . 'index.html', '');
    }

    if (!wpstow_check_requirements()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            'WPStow 插件需要以下 PHP 扩展：<ul>' . wpstow_get_requirements_list() . '</ul>请联系服务器管理员启用这些扩展后重新激活插件。',
            '插件激活失败',
            ['back_link' => true]
        );
    }
}

register_deactivation_hook(__FILE__, 'wpstow_deactivate');
function wpstow_deactivate() {
    MediaProxy::removeHtaccess();
    \WPStow\PersistentMediaQueue::deactivate();
    \WPStow\CloudDeletionQueue::deactivate();
}

function wpstow_check_requirements() {
    $requirements = [
        'curl' => extension_loaded('curl'),
        'json' => extension_loaded('json'),
    ];

    return !in_array(false, $requirements, true);
}

function wpstow_get_requirements_list() {
    $list = '';
    $requirements = [
        'curl' => ['name' => 'cURL', 'required' => true, 'loaded' => extension_loaded('curl')],
        'json' => ['name' => 'JSON', 'required' => true, 'loaded' => extension_loaded('json')],
        'fileinfo' => ['name' => 'Fileinfo', 'required' => false, 'loaded' => extension_loaded('fileinfo')],
    ];

    foreach ($requirements as $key => $req) {
        $status = $req['loaded'] ? '✓' : '✗';
        $required = $req['required'] ? '（必需）' : '（可选，推荐）';
        $class = $req['loaded'] ? 'success' : ($req['required'] ? 'error' : 'warning');
        $list .= sprintf(
            '<li class="%s">%s %s %s</li>',
            esc_attr($class),
            esc_html($status),
            esc_html($req['name']),
            esc_html($required)
        );
    }

    return $list;
}

add_action('admin_notices', 'wpstow_admin_notices');
function wpstow_admin_notices() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $screen = get_current_screen();
    if ($screen && in_array($screen->id, ['toplevel_page_wpstow_settings', 'settings_page_wpstow_settings', 'plugins'], true)) {
        $requirements = [
            'curl' => ['name' => 'cURL', 'loaded' => extension_loaded('curl'), 'required' => true],
            'json' => ['name' => 'JSON', 'loaded' => extension_loaded('json'), 'required' => true],
            'fileinfo' => ['name' => 'Fileinfo', 'loaded' => extension_loaded('fileinfo'), 'required' => false],
        ];

        $missing_required = [];
        $missing_optional = [];

        foreach ($requirements as $key => $req) {
            if (!$req['loaded']) {
                if ($req['required']) {
                    $missing_required[] = $req['name'];
                } else {
                    $missing_optional[] = $req['name'];
                }
            }
        }

        if (!empty($missing_required)) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>WPStow 警告：</strong>缺少必需的 PHP 扩展：<strong>' . esc_html(implode(', ', $missing_required)) . '</strong>。';
            echo '插件可能无法正常工作。请联系服务器管理员启用这些扩展。';
            echo '</p></div>';
        }

        if (!empty($missing_optional)) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>WPStow 提示：</strong>可选扩展 <strong>' . esc_html(implode(', ', $missing_optional)) . '</strong> 未加载。';
            echo '插件将使用备用方案识别文件类型，但建议启用该扩展以获得更好的性能和准确性。';
            echo '</p></div>';
        }
    }
}

if (is_admin()) {
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(MediaHandler::class, 'plugin_settings_link'));
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (!in_array($hook, ['toplevel_page_wpstow_settings', 'upload.php', 'media-new.php', 'post.php', 'post-new.php'], true)) {
        return;
    }

    if ($hook === 'toplevel_page_wpstow_settings') {
        $admin_css = WPSTOW_PLUGIN_DIR . 'static/admin.css';
        $admin_css_version = file_exists($admin_css) ? WPSTOW_VERSION . '-' . filemtime($admin_css) : WPSTOW_VERSION;
        // An existing CSF integration may not register a public "csf" style
        // handle. Depending on that handle would prevent this stylesheet from
        // being printed on otherwise valid installations.
        wp_enqueue_style('wpstow-admin', WPSTOW_PLUGIN_URL . 'static/admin.css', [], $admin_css_version);

        $admin_js = WPSTOW_PLUGIN_DIR . 'static/admin.js';
        $admin_js_version = file_exists($admin_js) ? WPSTOW_VERSION . '-' . filemtime($admin_js) : WPSTOW_VERSION;
        wp_enqueue_script('wpstow-admin', WPSTOW_PLUGIN_URL . 'static/admin.js', ['jquery'], $admin_js_version, true);
        wp_localize_script('wpstow-admin', 'wpstowAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpstow_admin'),
        ]);
        wp_enqueue_media();
        return;
    }

    if ($hook === 'upload.php') {
        wp_enqueue_script('wpstow-media-library', WPSTOW_PLUGIN_URL . 'static/media-library.js', ['jquery'], WPSTOW_VERSION, true);
        wp_localize_script('wpstow-media-library', 'wpstowMediaLibrary', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'processing' => __('正在处理…', 'wpstow'),
            'retry' => __('重试处理', 'wpstow'),
            'failed' => __('处理失败，请重试', 'wpstow'),
        ]);
    }

    wp_enqueue_media();
    $direct_upload_js = WPSTOW_PLUGIN_DIR . 'static/direct-upload.js';
    $direct_upload_version = file_exists($direct_upload_js) ? WPSTOW_VERSION . '-' . filemtime($direct_upload_js) : WPSTOW_VERSION;
    wp_enqueue_script(
        'wpstow-direct-upload',
        WPSTOW_PLUGIN_URL . 'static/direct-upload.js',
        ['jquery', 'wp-plupload', 'media-models'],
        $direct_upload_version,
        true
    );
    wp_localize_script('wpstow-direct-upload', 'wpstowDirectUpload', \WPStow\DirectUpload::getClientConfig());
}, 20);

require 'src/admin-csf.php';
require 'src/hooks.php';

// Register outside is_admin() so scheduled WordPress update checks can see it.
new Update(__FILE__);

add_action('wp_ajax_wpstow_debug_upload', 'wpstow_debug_upload');
function wpstow_debug_upload() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '权限不足']);
    }
    check_ajax_referer('wpstow_admin', 'nonce');

    $debugStorageType = MediaHandler::config('provider_config_type') ?: MediaHandler::config('storage_type');
    $isImageOnlyStorage = in_array($debugStorageType, ['oneimg', 'superbed'], true);
    $testName = $isImageOnlyStorage ? 'wpstow-debug-upload.png' : 'wpstow-debug-upload.txt';
    $testContent = $isImageOnlyStorage
        ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
        : 'test content ' . gmdate('Y-m-d H:i:s');
    $test_file = function_exists('wp_tempnam') ? wp_tempnam($testName) : tempnam(sys_get_temp_dir(), 'wpstow_');
    if (!$test_file || $testContent === false || file_put_contents($test_file, $testContent) === false) {
        wp_send_json_error(['message' => '无法创建本地临时测试文件']);
    }

    $storageClass = MediaHandler::getStorageClass($debugStorageType);
    if (!$storageClass) {
        @unlink($test_file);
        wp_send_json_error(['message' => '未配置存储类型']);
    }

    $extension = $isImageOnlyStorage ? 'png' : 'txt';
    $cloudKey = 'wpstow-test/test_' . gmdate('YmdHis') . '_' . wp_generate_password(6, false, false) . '.' . $extension;
    $response = ['success' => false, 'data' => ['message' => '上传自检失败']];

    try {
        $result = $storageClass::upload($test_file, $cloudKey);
        if (!empty($result['status'])) {
            $deleted = \WPStow\CloudDeletionQueue::deleteObject($debugStorageType, $cloudKey, '后台上传自检清理');
            if ($deleted) {
                $response = ['success' => true, 'data' => ['message' => '上传和临时对象清理均正常', 'key' => $cloudKey]];
            } else {
                $response = ['success' => false, 'data' => ['message' => '上传成功，但临时对象立即删除失败，已加入后台自动重试', 'key' => $cloudKey]];
            }
        } else {
            $response = ['success' => false, 'data' => ['message' => $result['message'] ?? '上传自检失败']];
        }
    } catch (\Throwable $e) {
        try {
            \WPStow\CloudDeletionQueue::deleteObject($debugStorageType, $cloudKey, '后台上传自检异常清理');
        } catch (\Throwable $cleanupError) {
            // Preserve the original self-check error; the random key is returned for manual cleanup if needed.
        }
        $response = ['success' => false, 'data' => ['message' => '上传自检异常：' . $e->getMessage(), 'key' => $cloudKey]];
    } finally {
        @unlink($test_file);
    }

    if ($response['success']) {
        wp_send_json_success($response['data']);
    }
    wp_send_json_error($response['data']);
}

add_action('wp_ajax_wpstow_clear_log', 'wpstow_clear_log');
function wpstow_clear_log() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '权限不足']);
    }
    check_ajax_referer('wpstow_admin', 'nonce');

    $deleted = Utils::clearLogs('app.log');

    wp_send_json_success(['message' => '日志已清除', 'deleted' => $deleted]);
}
