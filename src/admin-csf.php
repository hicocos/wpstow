<?php

use WPStow\MediaHandler;
use WPStow\FileNaming;
use WPStow\Utils;

if (!defined('ABSPATH')) {
    exit;
}

function wpstow_csf_choice($value, array $allowed, $default)
{
    $value = sanitize_text_field((string) $value);
    return in_array($value, $allowed, true) ? $value : $default;
}

function wpstow_csf_route_default($legacyType, $category)
{
    $legacyType = wpstow_csf_choice($legacyType, ['oneimg', 'superbed', 's3', 'r2', 'webdav', 'ftp'], 's3');
    return $category !== 'image' && in_array($legacyType, ['oneimg', 'superbed'], true) ? 'local' : $legacyType;
}

function wpstow_csf_url($value)
{
    $value = trim((string) $value);
    return $value === '' ? '' : esc_url_raw($value, ['http', 'https']);
}

function wpstow_csf_text($value)
{
    return sanitize_text_field((string) $value);
}

function wpstow_csf_saved_secret(array $data, array $previous, $inputKey, $storageKey)
{
    $value = isset($data[$inputKey]) ? trim((string) $data[$inputKey]) : '';
    return $value === '' ? (string) ($previous[$storageKey] ?? '') : Utils::sanitizeSecret($value);
}

function wpstow_csf_normalize_settings($data)
{
    $data = is_array($data) ? $data : [];
    $previous = get_option('wpstow_setting');
    if ($previous && !is_array($previous)) {
        $previous = @unserialize($previous, ['allowed_classes' => false]);
    }
    $previous = is_array($previous) ? $previous : [];
    $normalized = $previous;

    $normalized['switch'] = wpstow_csf_choice($data['switch'] ?? 'disable', ['enable', 'disable'], 'disable');
    $legacyType = wpstow_csf_choice($previous['storage_type'] ?? 's3', ['oneimg', 'superbed', 's3', 'r2', 'webdav', 'ftp'], 's3');
    $normalized['storage_type'] = $legacyType;
    $normalized['provider_config_type'] = wpstow_csf_choice(
        $data['provider_config_type'] ?? ($previous['provider_config_type'] ?? $legacyType),
        ['oneimg', 'superbed', 's3', 'r2', 'webdav', 'ftp'],
        $legacyType
    );
    $normalized['image_storage_type'] = wpstow_csf_choice(
        $data['image_storage_type'] ?? ($previous['image_storage_type'] ?? wpstow_csf_route_default($legacyType, 'image')),
        ['oneimg', 'superbed', 's3', 'r2', 'webdav', 'ftp', 'local'],
        wpstow_csf_route_default($legacyType, 'image')
    );
    foreach (['video', 'audio', 'other'] as $category) {
        $key = $category . '_storage_type';
        $default = wpstow_csf_route_default($legacyType, $category);
        $normalized[$key] = wpstow_csf_choice($data[$key] ?? ($previous[$key] ?? $default), ['s3', 'r2', 'webdav', 'ftp', 'local'], $default);
    }
    $editingProvider = $normalized['provider_config_type'];
    $providerValue = static function ($provider, $key, $default = '') use ($data, $previous, $editingProvider) {
        if ($editingProvider !== $provider && array_key_exists($key, $previous)) {
            return $previous[$key];
        }
        return $data[$key] ?? ($previous[$key] ?? $default);
    };
    $normalized['keep_local'] = wpstow_csf_choice($data['keep_local'] ?? 'yes', ['yes', 'no'], 'yes');
    $normalized['media_url_mode'] = wpstow_csf_choice($data['media_url_mode'] ?? 'cloud', ['cloud', 'local'], 'cloud');
    $normalized['cloud_fallback_local'] = wpstow_csf_choice($data['cloud_fallback_local'] ?? 'yes', ['yes', 'no'], 'yes');
    $normalized['filename_preset'] = wpstow_csf_choice(
        $data['filename_preset'] ?? ($previous['filename_preset'] ?? FileNaming::DEFAULT_PRESET),
        array_keys(FileNaming::getPresets()),
        FileNaming::DEFAULT_PRESET
    );
    $filenameTemplate = trim((string) ($data['filename_template'] ?? ($previous['filename_template'] ?? FileNaming::DEFAULT_TEMPLATE)));
    $normalized['filename_template'] = FileNaming::validateTemplate($filenameTemplate) === ''
        ? $filenameTemplate
        : (string) ($previous['filename_template'] ?? FileNaming::DEFAULT_TEMPLATE);

    $normalized['oneimg_endpoint'] = wpstow_csf_url($providerValue('oneimg', 'oneimg_endpoint'));
    $normalized['oneimg_token'] = wpstow_csf_saved_secret($editingProvider === 'oneimg' ? $data : [], $previous, 'oneimg_token_input', 'oneimg_token');

    $normalized['superbed_endpoint'] = wpstow_csf_url($providerValue('superbed', 'superbed_endpoint', 'https://api.superbed.cc'));
    $normalized['superbed_api_key'] = wpstow_csf_saved_secret($editingProvider === 'superbed' ? $data : [], $previous, 'superbed_api_key_input', 'superbed_api_key');
    $normalized['superbed_folder_id'] = wpstow_csf_text($providerValue('superbed', 'superbed_folder_id'));

    $normalized['s3_endpoint'] = wpstow_csf_url($providerValue('s3', 's3_endpoint'));
    $normalized['s3_bucket'] = wpstow_csf_text($providerValue('s3', 's3_bucket'));
    $normalized['s3_access_key'] = wpstow_csf_saved_secret($editingProvider === 's3' ? $data : [], $previous, 's3_access_key_input', 's3_access_key');
    $normalized['s3_secret_key'] = wpstow_csf_saved_secret($editingProvider === 's3' ? $data : [], $previous, 's3_secret_key_input', 's3_secret_key');
    $normalized['s3_region'] = wpstow_csf_text($providerValue('s3', 's3_region', 'us-east-1'));
    $normalized['s3_path_style'] = wpstow_csf_choice($providerValue('s3', 's3_path_style', 'no'), ['yes', 'no'], 'no');
    $normalized['s3_custom_url'] = wpstow_csf_url($providerValue('s3', 's3_custom_url'));

    $normalized['r2_endpoint'] = wpstow_csf_url($providerValue('r2', 'r2_endpoint'));
    $normalized['r2_bucket'] = wpstow_csf_text($providerValue('r2', 'r2_bucket'));
    $normalized['r2_access_key'] = wpstow_csf_saved_secret($editingProvider === 'r2' ? $data : [], $previous, 'r2_access_key_input', 'r2_access_key');
    $normalized['r2_secret_key'] = wpstow_csf_saved_secret($editingProvider === 'r2' ? $data : [], $previous, 'r2_secret_key_input', 'r2_secret_key');
    $normalized['r2_custom_url'] = wpstow_csf_url($providerValue('r2', 'r2_custom_url'));
    $normalized['r2_presign_ttl'] = min(604800, max(60, (int) $providerValue('r2', 'r2_presign_ttl', 900)));

    $normalized['webdav_endpoint'] = wpstow_csf_url($providerValue('webdav', 'webdav_endpoint'));
    $normalized['webdav_path'] = wpstow_csf_text($providerValue('webdav', 'webdav_path', '/'));
    $normalized['webdav_username'] = wpstow_csf_text($providerValue('webdav', 'webdav_username'));
    $normalized['webdav_password'] = wpstow_csf_saved_secret($editingProvider === 'webdav' ? $data : [], $previous, 'webdav_password_input', 'webdav_password');
    $normalized['webdav_custom_url'] = wpstow_csf_url($providerValue('webdav', 'webdav_custom_url'));

    $normalized['ftp_host'] = wpstow_csf_text($providerValue('ftp', 'ftp_host'));
    $normalized['ftp_port'] = min(65535, max(1, (int) $providerValue('ftp', 'ftp_port', 21)));
    $normalized['ftp_username'] = wpstow_csf_text($providerValue('ftp', 'ftp_username'));
    $normalized['ftp_password'] = wpstow_csf_saved_secret($editingProvider === 'ftp' ? $data : [], $previous, 'ftp_password_input', 'ftp_password');
    $normalized['ftp_path'] = wpstow_csf_text($providerValue('ftp', 'ftp_path', '/'));
    $normalized['ftp_passive'] = wpstow_csf_choice($providerValue('ftp', 'ftp_passive', 'yes'), ['yes', 'no'], 'yes');
    $normalized['ftp_ssl'] = wpstow_csf_choice($providerValue('ftp', 'ftp_ssl', 'no'), ['yes', 'no'], 'no');
    $normalized['ftp_custom_url'] = wpstow_csf_url($providerValue('ftp', 'ftp_custom_url'));

    $normalized['localize_images'] = wpstow_csf_choice($data['localize_images'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['disable_image_subsizes'] = wpstow_csf_choice($data['disable_image_subsizes'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['image_format_conversion'] = wpstow_csf_choice($data['image_format_conversion'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['image_webp_quality'] = min(100, max(10, (int) ($data['image_webp_quality'] ?? 82)));
    $normalized['image_watermark'] = wpstow_csf_choice($data['image_watermark'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['watermark_type'] = wpstow_csf_choice($data['watermark_type'] ?? 'text', ['text', 'image'], 'text');
    $normalized['watermark_text'] = wpstow_csf_text($data['watermark_text'] ?? '');
    $normalized['watermark_position'] = wpstow_csf_choice($data['watermark_position'] ?? 'bottom-right', [
        'top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right',
    ], 'bottom-right');
    $normalized['watermark_opacity'] = min(100, max(10, (int) ($data['watermark_opacity'] ?? 50)));
    $watermarkImage = $data['watermark_image'] ?? 0;
    $normalized['watermark_image'] = is_array($watermarkImage) ? (int) ($watermarkImage['id'] ?? 0) : (int) $watermarkImage;
    $normalized['keep_original'] = wpstow_csf_choice($data['keep_original'] ?? 'yes', ['yes', 'no'], 'yes');

    $normalized['log_enabled'] = wpstow_csf_choice($data['log_enabled'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['log_debug'] = wpstow_csf_choice($data['log_debug'] ?? 'no', ['yes', 'no'], 'no');

    unset(
        $normalized['oneimg_token_input'],
        $normalized['image_compress'],
        $normalized['image_compress_quality'],
        $normalized['video_compress'],
        $normalized['video_compress_quality'],
        $normalized['video_max_resolution'],
        $normalized['video_watermark'],
        $normalized['superbed_api_key_input'],
        $normalized['s3_access_key_input'],
        $normalized['s3_secret_key_input'],
        $normalized['r2_access_key_input'],
        $normalized['r2_secret_key_input'],
        $normalized['webdav_password_input'],
        $normalized['ftp_password_input']
    );

    return $normalized;
}
add_filter('csf_wpstow_setting_save', 'wpstow_csf_normalize_settings');

function wpstow_csf_after_save()
{
    MediaHandler::reloadConfig();
}
add_action('csf_wpstow_setting_saved', 'wpstow_csf_after_save');

function wpstow_csf_validate_filename_template($value)
{
    return FileNaming::validateTemplate($value);
}

function wpstow_csf_render_filename_guide()
{
    $presetTemplates = FileNaming::getPresetTemplates();
    $preset = (string) MediaHandler::config('filename_preset');
    $template = (string) MediaHandler::config('filename_template');
    $example = FileNaming::generateFilename('Summer Photo.jpg', $preset, $template, strtotime('2026-08-16 12:34:56 UTC'));

    echo '<div class="wpstow-naming-guide">';
    echo '<div class="wpstow-naming-preview"><span>当前规则示例</span><code id="wpstow-naming-preview">2026/08/' . esc_html($example) . '</code><small id="wpstow-naming-validation" class="is-success">语法有效，扩展名会自动保留</small></div>';
    echo '<div class="wpstow-naming-help">';
    echo '<div><strong>可用字段</strong><dl>';
    foreach ([
        '{name}' => '原文件名主体，最长取 80 个字符',
        '{year} / {month} / {day}' => '四位年、两位月、两位日',
        '{hour} / {minute} / {second}' => '两位时、分、秒',
        '{timestamp}' => 'Unix 秒级时间戳',
        '{random:N}' => '随机码，N 允许 8–32；省略 N 时为 8',
    ] as $token => $meaning) {
        echo '<dt><code>' . esc_html($token) . '</code></dt><dd>' . esc_html($meaning) . '</dd>';
    }
    echo '</dl></div>';
    echo '<div><strong>预设示例</strong><dl>';
    foreach ([
        '文件原名' => '示例图片.jpg',
        $presetTemplates['short'] => 'k7m2q9v4.jpg',
        $presetTemplates['date_random'] => '20260816-k7m2q9v4.jpg',
        $presetTemplates['original_random'] => 'Summer-Photo-k7m2q9v4.jpg',
        $presetTemplates['timestamp_random'] => '1786883696-k7m2q9v4.jpg',
    ] as $rule => $result) {
        echo '<dt><code>' . esc_html($rule) . '</code></dt><dd><code>' . esc_html($result) . '</code></dd>';
    }
    echo '</dl></div></div>';
    echo '<p class="wpstow-naming-note">规则仅影响之后上传的文件。选择“文件原名”时会保留中文等安全字符，并清理路径符号、控制字符及 URL 保留字符；同一目录重名时依次追加 <code>（1）</code>、<code>（2）</code>。启用 WordPress 年月目录时仍保留路径，例如 <code>2026/08/</code>，不同目录互不影响。S3、R2、WebDAV 和 FTP 会严格使用该名称；OneImg 与聚合图床若服务端二次改名，以服务端返回结果为准。</p>';
    echo '</div>';
}

function wpstow_csf_backend_configured($storageType)
{
    if ($storageType === 'oneimg') {
        return MediaHandler::rawSetting('oneimg_endpoint', '') !== ''
            && MediaHandler::rawSetting('oneimg_token', '') !== '';
    }
    if ($storageType === 'superbed') {
        return MediaHandler::rawSetting('superbed_endpoint', 'https://api.superbed.cc') !== ''
            && MediaHandler::rawSetting('superbed_api_key', '') !== '';
    }
    if ($storageType === 's3') {
        return MediaHandler::rawSetting('s3_endpoint', '') !== ''
            && MediaHandler::rawSetting('s3_access_key', '') !== ''
            && MediaHandler::rawSetting('s3_secret_key', '') !== ''
            && MediaHandler::rawSetting('s3_bucket', '') !== '';
    }
    if ($storageType === 'r2') {
        return MediaHandler::rawSetting('r2_endpoint', '') !== ''
            && MediaHandler::rawSetting('r2_access_key', '') !== ''
            && MediaHandler::rawSetting('r2_secret_key', '') !== ''
            && MediaHandler::rawSetting('r2_bucket', '') !== '';
    }
    if ($storageType === 'webdav') {
        return MediaHandler::rawSetting('webdav_endpoint', '') !== ''
            && MediaHandler::rawSetting('webdav_username', '') !== ''
            && MediaHandler::rawSetting('webdav_password', '') !== '';
    }
    if ($storageType === 'ftp') {
        return MediaHandler::rawSetting('ftp_host', '') !== ''
            && MediaHandler::rawSetting('ftp_username', '') !== ''
            && MediaHandler::rawSetting('ftp_password', '') !== '';
    }
    return false;
}

function wpstow_csf_render_status()
{
    $storageNames = ['oneimg' => 'OneImg', 'superbed' => '聚合图床', 's3' => 'S3', 'r2' => 'Cloudflare R2', 'webdav' => 'WebDAV', 'ftp' => 'FTP / FTPS', 'local' => '仅本地'];
    $categoryNames = ['image' => '图片', 'video' => '视频', 'audio' => '音频', 'other' => '其他'];
    $storageEnabled = MediaHandler::config('switch') === 'enable';
    $storageReady = MediaHandler::isStorageEnabledAndConfigured();
    $fallbackLocal = MediaHandler::config('cloud_fallback_local') !== 'no';
    $mediaUrlMode = MediaHandler::config('media_url_mode') === 'local' ? 'local' : 'cloud';
    $routes = [];
    $missingBackends = [];
    $activeBackends = [];
    foreach ($categoryNames as $category => $categoryName) {
        $storageType = MediaHandler::getStorageTypeForCategory($category);
        $routes[] = $categoryName . '：' . ($storageNames[$storageType] ?? strtoupper($storageType));
        if ($storageType !== 'local') {
            $activeBackends[$storageType] = $storageNames[$storageType] ?? strtoupper($storageType);
        }
        if ($storageType !== 'local' && !wpstow_csf_backend_configured($storageType)) {
            $missingBackends[$storageType] = $storageNames[$storageType] ?? strtoupper($storageType);
        }
    }

    $connectionLabel = $activeBackends ? ($missingBackends ? '配置不完整' : '全部就绪') : '未启用云端';
    $connectionDesc = !$activeBackends
        ? '四类文件当前都配置为仅本地。'
        : ($missingBackends ? '请补充：' . implode('、', $missingBackends) : '所有启用的云端存储必填字段已填写。');

    $items = [
        ['自动转存', $storageEnabled ? '已启用' : '未启用', $storageEnabled ? '新上传媒体会进入处理流程。' : '保存配置不会自动开启转存。', $storageEnabled],
        ['分类路由', implode(' · ', $routes), '每类文件会使用各自指定的存储源。', true],
        ['存储连接', $connectionLabel, $connectionDesc, $activeBackends && !$missingBackends],
        ['读取策略', $mediaUrlMode === 'local' ? '本地优先' : '云端优先', $fallbackLocal ? '允许本地回退。' : '不进行本地回退。', $fallbackLocal],
    ];

    echo '<div class="wpstow-csf-overview">';
    echo '<div class="wpstow-csf-overview-head"><div><strong>当前配置</strong><span>根据最近一次已保存的设置检查</span></div>';
    echo '<span class="wpstow-csf-pill ' . ($storageReady ? 'is-ready' : 'is-warning') . '">' . ($storageReady ? '转存就绪' : '待配置') . '</span></div>';
    echo '<div class="wpstow-csf-status-grid">';
    foreach ($items as $item) {
        echo '<div class="wpstow-csf-status-item"><span>' . esc_html($item[0]) . '</span><strong>' . esc_html($item[1]) . '</strong><small>' . esc_html($item[2]) . '</small><i class="' . ($item[3] ? 'is-ok' : 'is-muted') . '"></i></div>';
    }
    echo '</div></div>';
}

function wpstow_csf_render_connection_test()
{
    echo '<div class="wpstow-csf-tool">';
    echo '<button type="button" class="button button-primary" id="wpstow-test-connection"><i class="fas fa-plug"></i> 测试当前配置</button>';
    echo '<span class="wpstow-inline-result" id="wpstow-test-result" role="status" aria-live="polite"></span>';
    echo '<p>先测试再启用自动转存。测试不会保存配置；密钥留空时使用已保存的值。</p>';
    echo '</div>';
}

function wpstow_csf_render_diagnostics()
{
    $logContent = Utils::readLogTail('app.log');
    $logStats = Utils::getLogStats('app.log');
    $hasLog = (int) ($logStats['size_bytes'] ?? 0) > 0 && trim((string) $logContent) !== '暂无日志';

    echo '<div class="wpstow-csf-diagnostics">';
    echo '<div class="wpstow-csf-tool-row"><button type="button" class="button button-primary wpstow-debug-upload-trigger"><i class="fas fa-cloud-upload-alt"></i> 执行上传自检</button>';
    echo '<button type="button" class="button wpstow-danger-button" id="wpstow-clear-log"' . ($hasLog ? '' : ' disabled') . '><i class="fas fa-trash-alt"></i> 清除日志</button></div>';
    echo '<p class="wpstow-csf-tool-desc">上传自检使用“存储配置”中当前选定服务的已保存配置，创建临时对象并在写入后立即删除。当前日志 ' . esc_html($logStats['size_label']) . '，最后写入 ' . esc_html($logStats['mtime_label']) . '。</p>';
    echo '<pre id="wpstow-debug-result" class="wpstow-result-box" role="status" aria-live="polite"></pre>';
    if ($hasLog) {
        echo '<div class="wpstow-log-meta">单个日志最多 ' . esc_html($logStats['max_size_label']) . '，保留 ' . (int) $logStats['max_backups'] . ' 个历史文件，下面显示最后 ' . (int) $logStats['tail_lines'] . ' 行。</div>';
        echo '<textarea id="wpstow-log-output" class="wpstow-log" rows="16" readonly aria-label="WPStow 运行日志">' . esc_textarea($logContent) . '</textarea>';
    } else {
        echo '<div id="wpstow-log-output" class="wpstow-empty-log"><strong>暂无运行日志</strong><span>需要排查问题时，先开启上方“运行日志”并保存。</span></div>';
    }
    echo '</div>';
}

function wpstow_csf_render_media_manager()
{
    echo '<div class="wpstow-library-manager" id="wpstow-library-manager">';
    echo '<div class="wpstow-library-toolbar">';
    echo '<label for="wpstow-library-category">文件类型</label>';
    echo '<select id="wpstow-library-category">';
    echo '<option value="all">全部附件</option>';
    echo '<option value="image">图片</option>';
    echo '<option value="video">视频</option>';
    echo '<option value="audio">音频</option>';
    echo '<option value="other">其他文件</option>';
    echo '</select>';
    echo '<button type="button" class="button button-secondary" id="wpstow-library-scan"><i class="fas fa-search"></i> 扫描媒体库</button>';
    echo '<button type="button" class="button button-primary" id="wpstow-library-process" disabled><i class="fas fa-cloud-upload-alt"></i> 接管可处理项</button>';
    echo '<button type="button" class="button" id="wpstow-library-stop" disabled><i class="fas fa-stop"></i> 停止扫描</button>';
    echo '<span class="wpstow-queue-controls" hidden>';
    echo '<button type="button" class="button" id="wpstow-queue-pause"><i class="fas fa-pause"></i> 暂停</button>';
    echo '<button type="button" class="button" id="wpstow-queue-resume"><i class="fas fa-play"></i> 继续</button>';
    echo '<button type="button" class="button wpstow-danger-button" id="wpstow-queue-cancel"><i class="fas fa-times"></i> 取消</button>';
    echo '</span>';
    echo '</div>';
    echo '<p class="wpstow-library-persistence">接管任务保存在服务器数据库中，关闭本页后仍会继续；失败附件最多自动尝试 3 次。</p>';
    echo '<div class="wpstow-library-progress" hidden>';
    echo '<div><strong id="wpstow-library-progress-label">等待扫描</strong><span id="wpstow-library-progress-count"></span></div>';
    echo '<progress id="wpstow-library-progress-bar" value="0" max="1"></progress>';
    echo '</div>';
    echo '<div class="wpstow-library-summary" aria-live="polite">';
    foreach ([
        'managed' => '已接管',
        'ready' => '可接管',
        'failed' => '上次失败',
        'pending' => '正在处理',
        'local' => '仅本地路由',
        'missing' => '源文件缺失',
        'unavailable' => '配置不可用',
    ] as $key => $label) {
        echo '<div><span>' . esc_html($label) . '</span><strong data-wpstow-count="' . esc_attr($key) . '">-</strong></div>';
    }
    echo '</div>';
    echo '<div class="wpstow-library-notice" id="wpstow-library-notice" role="status" aria-live="polite">选择文件类型后扫描。</div>';
    echo '<div class="wpstow-library-table-wrap" hidden>';
    echo '<table class="widefat striped wpstow-library-table">';
    echo '<thead><tr><th>附件</th><th>类型</th><th>状态</th><th>目标存储</th><th>详情</th></tr></thead>';
    echo '<tbody id="wpstow-library-items"></tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
}

function wpstow_csf_render_update_panel()
{
    $pluginSlug = 'wpstow/wpstow.php';
    $transient = get_site_transient('update_plugins');
    $available = is_object($transient) && isset($transient->response[$pluginSlug])
        ? $transient->response[$pluginSlug]
        : null;
    $current = is_object($transient) && isset($transient->no_update[$pluginSlug])
        ? $transient->no_update[$pluginSlug]
        : null;
    $knownUpdate = $available ?: $current;
    $latestVersion = is_object($knownUpdate)
        ? (string) ($knownUpdate->new_version ?? ($knownUpdate->version ?? ''))
        : '';
    $manualStatus = isset($_GET['wpstow_update_check'])
        ? sanitize_key(wp_unslash($_GET['wpstow_update_check']))
        : '';

    if ($manualStatus === 'failed') {
        $statusClass = 'is-error';
        $statusIcon = 'fa fa-exclamation-triangle';
        $statusLabel = '检查失败';
        $statusTitle = '暂时无法检查插件更新';
        $statusDesc = '服务器未能获取 GitHub Release，请检查网络后重试。';
        $resultText = '更新检查失败，请确认服务器可以访问 GitHub 后重新检测。';
    } elseif ($available && $latestVersion !== '') {
        $statusClass = 'is-update';
        $statusIcon = 'fa fa-cloud-download';
        $statusLabel = '可更新';
        $statusTitle = '检测到 WPStow 新版本';
        $statusDesc = 'v' . $latestVersion . ' 已发布，可以直接在线升级。';
        $resultText = '发现新版本 v' . $latestVersion . '，可以使用下方按钮立即更新。';
    } elseif ($latestVersion !== '') {
        $statusClass = 'is-current';
        $statusIcon = 'fa fa-thumbs-o-up';
        $statusLabel = '最新版';
        $statusTitle = '当前插件已经是最新版';
        $statusDesc = '当前安装版本与 GitHub 最新稳定版一致。';
        $resultText = '暂无更新，您当前安装的 v' . WPSTOW_VERSION . ' 已是最新版。';
    } else {
        $statusClass = 'is-unknown';
        $statusIcon = 'fa fa-refresh';
        $statusLabel = '尚未检查';
        $statusTitle = '尚未获取最新版本信息';
        $statusDesc = '点击下方按钮获取 GitHub 最新稳定版本。';
        $resultText = '尚未检测更新，点击下方按钮获取最新版本信息。';
    }

    $upgradeUrl = wp_nonce_url(
        self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($pluginSlug)),
        'upgrade-plugin_' . $pluginSlug
    );

    echo '<div class="wpstow-update-panel ' . esc_attr($statusClass) . '" id="wpstow-update-panel">';
    echo '<div class="wpstow-update-head">';
    echo '<div class="wpstow-update-summary">';
    echo '<span>WPStow 在线更新</span>';
    echo '<strong><i class="' . esc_attr($statusIcon) . '" id="wpstow-update-heading-icon"></i><span id="wpstow-update-heading">' . esc_html($statusTitle) . '</span></strong>';
    echo '<small id="wpstow-update-description">' . esc_html($statusDesc) . '</small>';
    echo '</div>';
    echo '<span class="wpstow-update-state ' . esc_attr($statusClass) . '" id="wpstow-update-state"><i></i><span id="wpstow-update-state-label">' . esc_html($statusLabel) . '</span></span>';
    echo '</div>';
    echo '<div class="wpstow-update-versions">';
    echo '<div><span>当前版本</span><strong>v' . esc_html(WPSTOW_VERSION) . '</strong></div>';
    echo '<div><span>最新版本</span><strong id="wpstow-update-latest">' . ($latestVersion !== '' ? 'v' . esc_html($latestVersion) : '待检查') . '</strong></div>';
    echo '<div><span>更新渠道</span><strong>GitHub Release</strong></div>';
    echo '</div>';
    echo '<div class="wpstow-update-result ' . esc_attr($statusClass) . '" id="wpstow-update-result" role="status" aria-live="polite">';
    echo '<i class="' . esc_attr($statusIcon) . '" id="wpstow-update-result-icon" aria-hidden="true"></i>';
    echo '<span id="wpstow-update-result-text">' . esc_html($resultText) . '</span>';
    echo '</div>';
    echo '<div class="wpstow-update-progress" id="wpstow-update-progress" role="status" aria-live="polite" hidden>';
    echo '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i>';
    echo '<span><strong>正在检查更新</strong><small>正在连接 GitHub，请稍候...</small></span>';
    echo '</div>';
    echo '<div class="wpstow-update-actions" id="wpstow-update-actions">';
    echo '<button type="button" class="button button-primary" id="wpstow-check-updates"><i class="fa fa-refresh" aria-hidden="true"></i><span class="wpstow-update-button-label">检查更新</span></button>';
    if ($available && $latestVersion !== '') {
        echo '<a class="button wpstow-update-now" id="wpstow-update-now" href="' . esc_url($upgradeUrl) . '"><i class="fa fa-arrow-circle-up" aria-hidden="true"></i>立即更新到 v' . esc_html($latestVersion) . '</a>';
    }
    echo '<a class="button" href="https://github.com/hicocos/wpstow/releases" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link" aria-hidden="true"></i>发布记录</a>';
    echo '</div>';
    echo '<p class="wpstow-update-note">升级由 WordPress 原生更新程序执行。开始前建议备份网站文件和数据库，更新过程中不要关闭页面。</p>';
    echo '</div>';
}

function wpstow_csf_button_field($id, $title, array $options, $default, $desc = '', $dependency = null)
{
    $field = [
        'id' => $id,
        'title' => $title,
        'type' => 'button_set',
        'options' => $options,
        'default' => $default,
        'desc' => $desc,
    ];
    if ($dependency !== null) {
        $field['dependency'] = $dependency;
    }
    return $field;
}

function wpstow_register_csf_options()
{
    static $registered = false;

    if ($registered || !is_admin() || !class_exists('CSF')) {
        return;
    }
    $registered = true;

    $key = 'wpstow_setting';
    $hasOneImgToken = MediaHandler::rawSetting('oneimg_token', '') !== '';
    $hasSuperbedApiKey = MediaHandler::rawSetting('superbed_api_key', '') !== '';
    $savedSuperbedFolderId = wpstow_csf_text(MediaHandler::rawSetting('superbed_folder_id', ''));
    $superbedFolderOptions = ['' => '根目录（推荐）'];
    if ($savedSuperbedFolderId !== '') {
        $superbedFolderOptions[$savedSuperbedFolderId] = '已保存的目录';
    }
    $superbedFolderDescription = '<div class="wpstow-superbed-folder-tools">'
        . '<button type="button" class="button" id="wpstow-load-superbed-folders"><i class="fa fa-refresh" aria-hidden="true"></i> 获取目录</button>'
        . '<span id="wpstow-superbed-folder-result" role="status" aria-live="polite"></span>'
        . '</div>'
        . '<p>填写 API Key 后点击“获取目录”，即可按名称选择目录，无需查找 UUID；留在根目录会直接上传到顶层。</p>'
        . '<details class="wpstow-superbed-folder-manual"><summary>高级：手动填写目录 UUID</summary>'
        . '<input type="text" id="wpstow-superbed-folder-manual-input" value="' . esc_attr($savedSuperbedFolderId) . '" autocomplete="off" placeholder="目录 UUID">'
        . '</details>';
    $hasS3AccessKey = MediaHandler::rawSetting('s3_access_key', '') !== '';
    $hasS3Secret = MediaHandler::rawSetting('s3_secret_key', '') !== '';
    $hasR2AccessKey = MediaHandler::rawSetting('r2_access_key', '') !== '';
    $hasR2Secret = MediaHandler::rawSetting('r2_secret_key', '') !== '';
    $hasWebdavPassword = MediaHandler::rawSetting('webdav_password', '') !== '';
    $hasFtpPassword = MediaHandler::rawSetting('ftp_password', '') !== '';
    $legacyType = wpstow_csf_choice(MediaHandler::rawSetting('storage_type', 's3'), ['oneimg', 'superbed', 's3', 'r2', 'webdav', 'ftp'], 's3');
    $routeDefaults = [];
    foreach (['image', 'video', 'audio', 'other'] as $category) {
        $routeDefaults[$category] = wpstow_csf_route_default($legacyType, $category);
    }

    CSF::createOptions($key, [
        'menu_title' => 'WPStow 设置',
        'menu_slug' => 'wpstow_settings',
        'menu_icon' => 'dashicons-cloud-upload',
        'menu_position' => 100,
        'framework_title' => 'WPStow <small>v' . WPSTOW_VERSION . '</small>',
        'show_in_customizer' => false,
        'show_bar_menu' => true,
        'show_sub_menu' => false,
        'show_search' => true,
        'show_reset_all' => false,
        'show_reset_section' => false,
        'show_all_options' => false,
        'ajax_save' => true,
        'sticky_header' => true,
        'save_defaults' => false,
        'output_css' => false,
        'enqueue_webfont' => false,
        'footer_text' => 'WPStow ' . WPSTOW_VERSION . ' · 媒体存储与处理',
        'footer_credit' => '<i class="fas fa-cloud"></i>',
        'theme' => 'light',
        'class' => 'wpstow-csf',
    ]);

    CSF::createSection($key, [
        'title' => '存储配置',
        'icon' => 'fa fa-cloud',
        'fields' => [
            ['type' => 'subheading', 'content' => '<span class="wpstow-step-title"><b>1</b> 按文件类型分配存储</span><small>选择“仅本地”时，该类型不会进入云端上传流程。OneImg 和聚合图床仅支持图片。</small>', 'class' => 'wpstow-step-heading'],
            [
                'id' => 'image_storage_type',
                'title' => '图片',
                'type' => 'select',
                'options' => [
                    'oneimg' => 'OneImg 图床',
                    'superbed' => '聚合图床',
                    's3' => 'S3 兼容对象存储',
                    'r2' => 'Cloudflare R2',
                    'webdav' => 'WebDAV',
                    'ftp' => 'FTP / FTPS',
                    'local' => '仅本地',
                ],
                'default' => $routeDefaults['image'],
                'class' => 'wpstow-storage-picker',
            ],
            [
                'id' => 'video_storage_type',
                'title' => '视频',
                'type' => 'select',
                'options' => [
                    's3' => 'S3 兼容对象存储',
                    'r2' => 'Cloudflare R2',
                    'webdav' => 'WebDAV',
                    'ftp' => 'FTP / FTPS',
                    'local' => '仅本地',
                ],
                'default' => $routeDefaults['video'],
                'class' => 'wpstow-storage-picker',
            ],
            [
                'id' => 'audio_storage_type',
                'title' => '音频',
                'type' => 'select',
                'options' => [
                    's3' => 'S3 兼容对象存储',
                    'r2' => 'Cloudflare R2',
                    'webdav' => 'WebDAV',
                    'ftp' => 'FTP / FTPS',
                    'local' => '仅本地',
                ],
                'default' => $routeDefaults['audio'],
                'class' => 'wpstow-storage-picker',
            ],
            [
                'id' => 'other_storage_type',
                'title' => '其他文件',
                'type' => 'select',
                'options' => [
                    's3' => 'S3 兼容对象存储',
                    'r2' => 'Cloudflare R2',
                    'webdav' => 'WebDAV',
                    'ftp' => 'FTP / FTPS',
                    'local' => '仅本地',
                ],
                'default' => $routeDefaults['other'],
                'desc' => '包括 PDF、压缩包、文档以及无法归入图片、视频或音频的附件。',
                'class' => 'wpstow-storage-picker',
            ],
            ['type' => 'subheading', 'content' => '<span class="wpstow-step-title"><b>2</b> 配置存储服务</span><small>选择要编辑和测试的服务。切换服务不会清除其他服务已经保存的凭据。</small>', 'class' => 'wpstow-step-heading'],
            [
                'id' => 'provider_config_type',
                'title' => '当前配置服务',
                'type' => 'select',
                'options' => [
                    'oneimg' => 'OneImg 图床（仅图片）',
                    'superbed' => '聚合图床（仅图片）',
                    's3' => 'S3 兼容对象存储',
                    'r2' => 'Cloudflare R2',
                    'webdav' => 'WebDAV',
                    'ftp' => 'FTP / FTPS',
                ],
                'default' => $legacyType,
                'class' => 'wpstow-storage-picker',
            ],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接 OneImg</span><small>开源图床程序，WPStow 通过 API 上传图片。<a href="https://github.com/onexru/oneimg" target="_blank" rel="noopener noreferrer">查看项目与部署说明</a></small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 'oneimg']],
            ['id' => 'oneimg_endpoint', 'title' => '图床地址', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://img.example.com', 'desc' => '填写 OneImg 站点根地址，不要附加 <code>/api</code>。', 'dependency' => ['provider_config_type', '==', 'oneimg']],
            ['id' => 'oneimg_token_input', 'title' => 'API Token', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasOneImgToken ? '已保存，留空不变' : '请输入 OneImg API Token', 'desc' => '在 OneImg 后台生成。为安全起见，已保存的 Token 不会回显。', 'dependency' => ['provider_config_type', '==', 'oneimg']],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接聚合图床</span><small>使用 Superbed 官方 API 上传图片、获取直链并同步移入回收站。<a href="https://www.superbed.cn/help" target="_blank" rel="noopener noreferrer">查看 API 文档</a> · <a href="https://www.superbed.cn/" target="_blank" rel="noopener noreferrer">官方网站</a></small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 'superbed']],
            ['id' => 'superbed_endpoint', 'title' => 'API 地址', 'type' => 'text', 'attributes' => ['type' => 'url'], 'default' => 'https://api.superbed.cc', 'placeholder' => 'https://api.superbed.cc', 'desc' => '默认使用官方 API 地址；不要附加 <code>/api/v1</code>。', 'dependency' => ['provider_config_type', '==', 'superbed']],
            ['id' => 'superbed_api_key_input', 'title' => 'API Key', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasSuperbedApiKey ? '已保存，留空不变' : '请输入聚合图床 API Key', 'desc' => '在聚合图床后台生成。为安全起见，已保存的 API Key 不会回显。', 'dependency' => ['provider_config_type', '==', 'superbed']],
            ['id' => 'superbed_folder_id', 'title' => '上传目录', 'type' => 'select', 'options' => $superbedFolderOptions, 'default' => '', 'desc' => $superbedFolderDescription, 'class' => 'wpstow-superbed-folder-field', 'dependency' => ['provider_config_type', '==', 'superbed']],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接 S3</span><small>适用于 Amazon S3、MinIO 及其他 S3 兼容服务。</small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_endpoint', 'title' => 'Endpoint', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://s3.amazonaws.com', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_bucket', 'title' => 'Bucket', 'type' => 'text', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_access_key_input', 'title' => 'Access Key ID', 'type' => 'text', 'attributes' => ['autocomplete' => 'off'], 'placeholder' => $hasS3AccessKey ? '已保存，留空不变' : '请输入 Access Key ID', 'desc' => '不会回显已保存值；输入新值才会替换。', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_secret_key_input', 'title' => 'Secret Key', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasS3Secret ? '已保存，留空不变' : '请输入 Secret Key', 'desc' => '留空保存表示保留原值。', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_region', 'title' => 'Region', 'type' => 'text', 'default' => 'us-east-1', 'placeholder' => 'us-east-1', 'dependency' => ['provider_config_type', '==', 's3']],
            wpstow_csf_button_field('s3_path_style', '路径样式', ['no' => '虚拟主机样式', 'yes' => '路径样式'], 'no', '', ['provider_config_type', '==', 's3']),
            ['id' => 's3_custom_url', 'title' => '自定义访问 URL', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://cdn.example.com', 'desc' => '公开 CDN 域名填这里；私有桶继续使用代理。', 'dependency' => ['provider_config_type', '==', 's3']],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接 Cloudflare R2</span><small>私有桶无需开启公开访问；WPStow 会以固定媒体 URL 跳转到短期预签名地址，文件流量不经过本站 PHP。</small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 'r2']],
            ['id' => 'r2_endpoint', 'title' => 'S3 API Endpoint', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://ACCOUNT_ID.r2.cloudflarestorage.com', 'desc' => '填写 R2 的 S3 API Endpoint，不要包含 Bucket 名。', 'dependency' => ['provider_config_type', '==', 'r2']],
            ['id' => 'r2_bucket', 'title' => 'Bucket', 'type' => 'text', 'dependency' => ['provider_config_type', '==', 'r2']],
            ['id' => 'r2_access_key_input', 'title' => 'Access Key ID', 'type' => 'text', 'attributes' => ['autocomplete' => 'off'], 'placeholder' => $hasR2AccessKey ? '已保存，留空不变' : '请输入 R2 Access Key ID', 'desc' => '不会回显已保存值；输入新值才会替换。', 'dependency' => ['provider_config_type', '==', 'r2']],
            ['id' => 'r2_secret_key_input', 'title' => 'Secret Access Key', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasR2Secret ? '已保存，留空不变' : '请输入 R2 Secret Access Key', 'desc' => '留空保存表示保留原值。', 'dependency' => ['provider_config_type', '==', 'r2']],
            ['id' => 'r2_presign_ttl', 'title' => '临时签名有效期', 'type' => 'number', 'default' => 900, 'unit' => '秒', 'attributes' => ['min' => 60, 'max' => 604800, 'step' => 60], 'desc' => '固定媒体 URL 每次访问时签发临时 R2 地址；建议 300–900 秒，最长 7 天。', 'dependency' => ['provider_config_type', '==', 'r2']],
            ['id' => 'r2_custom_url', 'title' => '公开访问 URL（可选）', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://img.example.com', 'desc' => '私有桶请留空。仅当桶已公开并绑定自定义域名时填写，填写后将绕过预签名跳转直接访问。', 'dependency' => ['provider_config_type', '==', 'r2']],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接 WebDAV</span><small>填写 WebDAV 服务地址和具有读写权限的账号。</small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 'webdav']],
            ['id' => 'webdav_endpoint', 'title' => 'Endpoint', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://dav.example.com', 'dependency' => ['provider_config_type', '==', 'webdav']],
            ['id' => 'webdav_path', 'title' => '存储路径', 'type' => 'text', 'default' => '/', 'placeholder' => '/images', 'dependency' => ['provider_config_type', '==', 'webdav']],
            ['id' => 'webdav_username', 'title' => '用户名', 'type' => 'text', 'attributes' => ['autocomplete' => 'off'], 'dependency' => ['provider_config_type', '==', 'webdav']],
            ['id' => 'webdav_password_input', 'title' => '密码', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasWebdavPassword ? '已保存，留空不变' : '请输入密码', 'desc' => '留空保存表示保留原值。', 'dependency' => ['provider_config_type', '==', 'webdav']],
            ['id' => 'webdav_custom_url', 'title' => '自定义访问 URL', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://cdn.example.com', 'dependency' => ['provider_config_type', '==', 'webdav']],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接 FTP / FTPS</span><small>建议优先使用加密的 FTPS，并确认账号对目标目录具有读写权限。</small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 'ftp']],
            ['id' => 'ftp_host', 'title' => '主机地址', 'type' => 'text', 'placeholder' => 'ftp.example.com', 'dependency' => ['provider_config_type', '==', 'ftp']],
            ['id' => 'ftp_port', 'title' => '端口', 'type' => 'number', 'default' => 21, 'attributes' => ['min' => 1, 'max' => 65535], 'dependency' => ['provider_config_type', '==', 'ftp']],
            ['id' => 'ftp_username', 'title' => '用户名', 'type' => 'text', 'attributes' => ['autocomplete' => 'off'], 'dependency' => ['provider_config_type', '==', 'ftp']],
            ['id' => 'ftp_password_input', 'title' => '密码', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasFtpPassword ? '已保存，留空不变' : '请输入密码', 'desc' => '留空保存表示保留原值。', 'dependency' => ['provider_config_type', '==', 'ftp']],
            ['id' => 'ftp_path', 'title' => '存储路径', 'type' => 'text', 'default' => '/', 'placeholder' => '/public_html/images', 'dependency' => ['provider_config_type', '==', 'ftp']],
            wpstow_csf_button_field('ftp_passive', '被动模式', ['yes' => '启用', 'no' => '禁用'], 'yes', '', ['provider_config_type', '==', 'ftp']),
            wpstow_csf_button_field('ftp_ssl', 'SSL / TLS', ['no' => '普通 FTP', 'yes' => 'FTPS'], 'no', '', ['provider_config_type', '==', 'ftp']),
            ['id' => 'ftp_custom_url', 'title' => '自定义访问 URL', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://cdn.example.com', 'dependency' => ['provider_config_type', '==', 'ftp']],
            ['type' => 'callback', 'title' => '验证连接', 'function' => 'wpstow_csf_render_connection_test', 'class' => 'wpstow-connection-test'],
            ['type' => 'subheading', 'content' => '<span class="wpstow-step-title"><b>3</b> 启用与访问方式</span><small>建议首次使用保留本地副本，确认运行稳定后再按需调整。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('switch', '自动转存新媒体', ['enable' => '开启', 'disable' => '关闭'], 'disable', '开启后，新上传媒体会按照上方分类路由发送到对应存储。'),
            wpstow_csf_button_field('keep_local', '本地副本', ['yes' => '保留（推荐）', 'no' => '上传后删除'], 'yes', '仅控制之后成功转存的文件；关闭不会删除已有本地副本。'),
            wpstow_csf_button_field('media_url_mode', '媒体访问地址', ['cloud' => '云端优先', 'local' => '本站优先'], 'cloud', '对每个附件分别判断：本站优先时，有本地文件则使用本站地址，没有则继续使用云端地址。不会迁移或删除文件。'),
            wpstow_csf_button_field('cloud_fallback_local', '云端读取失败时', ['yes' => '使用本地副本', 'no' => '不回退'], 'yes', '仅在云端优先且通过代理读取时生效；有本地副本才会回退。浏览器直连公开 CDN 时无法自动感知。', ['media_url_mode', '==', 'cloud']),
        ],
    ]);

    CSF::createSection($key, [
        'title' => '文件命名',
        'icon' => 'fa fa-tag',
        'fields' => [
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">新上传文件的名称</span><small>统一用于浏览器直传和服务器上传；已有媒体与云端对象不会被改名。</small>', 'class' => 'wpstow-step-heading'],
            [
                'id' => 'filename_preset',
                'title' => '命名方案',
                'type' => 'select',
                'options' => FileNaming::getPresets(),
                'default' => FileNaming::DEFAULT_PRESET,
                'desc' => '默认保留兼容处理后的文件原名；同一目录出现重名时自动追加 <code>（1）</code>、<code>（2）</code>。所有方案都会保留原扩展名。',
                'class' => 'wpstow-storage-picker',
            ],
            [
                'id' => 'filename_template',
                'title' => '自定义模板',
                'type' => 'text',
                'default' => FileNaming::DEFAULT_TEMPLATE,
                'attributes' => ['maxlength' => 120, 'autocomplete' => 'off'],
                'placeholder' => '{year}{month}{day}-{random:10}',
                'desc' => '必须包含 <code>{random:N}</code>，固定文字仅允许字母、数字、点、短横线和下划线；不能填写目录或扩展名。',
                'validate' => 'wpstow_csf_validate_filename_template',
                'dependency' => ['filename_preset', '==', 'custom'],
            ],
            ['type' => 'callback', 'function' => 'wpstow_csf_render_filename_guide', 'class' => 'wpstow-naming-field'],
        ],
    ]);

    CSF::createSection($key, [
        'title' => '图片处理',
        'icon' => 'fa fa-image',
        'fields' => [
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">外部图片入库</span><small>保存文章时，将正文里的外链或 Base64 图片纳入 WordPress 媒体库。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('localize_images', '自动本地化', ['no' => '关闭', 'yes' => '开启'], 'no', '开启后会由服务器请求外部图片；请确认站点允许访问的网络范围。'),
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">文件生成策略</span><small>控制 WordPress、主题和其他插件是否为新图片生成派生文件。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('disable_image_subsizes', '原图单文件模式', ['no' => '生成响应式尺寸', 'yes' => '只保留上传文件'], 'no', '启用后，新上传图片不生成 thumbnail、medium、large、主题尺寸、<code>-scaled</code> 或 <code>-rotated</code> 文件。不会阻止下方明确启用的 WebP 转换；仅影响之后上传的图片。'),
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">格式转换</span><small>在转存前统一处理新上传的栅格图片；转换不可用时保留原文件。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('image_format_conversion', '统一转为 WebP', ['no' => '关闭', 'yes' => '启用'], 'no', 'JPEG、PNG、BMP、AVIF、HEIC 等服务器可解码的栅格图会转为 <code>.webp</code>。GIF 为避免丢失动画、SVG 为保留矢量特性，始终保持原格式；转换失败也会使用原文件继续上传。'),
            ['id' => 'image_webp_quality', 'title' => 'WebP 转换质量', 'type' => 'slider', 'min' => 10, 'max' => 100, 'step' => 1, 'unit' => '%', 'default' => 82, 'desc' => '建议 75–85；数值越高画质越好、文件通常越大。', 'dependency' => ['image_format_conversion', '==', 'yes']],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">图片水印</span><small>水印会先写入图片，再按上方设置转换为 WebP。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('image_watermark', '图片水印', ['no' => '关闭', 'yes' => '启用'], 'no'),
            wpstow_csf_button_field('watermark_type', '水印类型', ['text' => '文字水印', 'image' => '图片水印'], 'text', '', ['image_watermark', '==', 'yes']),
            ['id' => 'watermark_text', 'title' => '水印文字', 'type' => 'text', 'placeholder' => '请输入水印文字', 'dependency' => ['image_watermark|watermark_type', '==|==', 'yes|text']],
            ['id' => 'watermark_image', 'title' => '水印图片', 'type' => 'media', 'url' => false, 'library' => ['image'], 'button_title' => '选择水印图片', 'desc' => '建议使用透明背景 PNG。', 'dependency' => ['image_watermark|watermark_type', '==|==', 'yes|image']],
            wpstow_csf_button_field('watermark_position', '水印位置', ['top-left' => '左上', 'top-center' => '顶部居中', 'top-right' => '右上', 'center-left' => '左侧居中', 'center' => '居中', 'center-right' => '右侧居中', 'bottom-left' => '左下', 'bottom-center' => '底部居中', 'bottom-right' => '右下'], 'bottom-right', '', ['image_watermark', '==', 'yes']),
            ['id' => 'watermark_opacity', 'title' => '水印透明度', 'type' => 'slider', 'min' => 10, 'max' => 100, 'step' => 1, 'unit' => '%', 'default' => 50, 'dependency' => ['image_watermark', '==', 'yes']],
            wpstow_csf_button_field('keep_original', '处理前原图', ['yes' => '保留原图（推荐）', 'no' => '仅保留处理结果'], 'yes', '仅在启用格式转换或水印后生效；保留原图便于恢复。'),
        ],
    ]);

    CSF::createSection($key, [
        'title' => '媒体接管',
        'icon' => 'fa fa-tasks',
        'fields' => [
            ['type' => 'callback', 'function' => 'wpstow_csf_render_media_manager'],
        ],
    ]);

    CSF::createSection($key, [
        'title' => '运行状态',
        'icon' => 'fa fa-heartbeat',
        'fields' => [
            ['type' => 'callback', 'function' => 'wpstow_csf_render_status'],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">故障排查</span><small>正常使用无需开启日志或执行自检。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('log_enabled', '运行日志', ['no' => '关闭', 'yes' => '开启'], 'no', '默认关闭，需要排查问题时再开启。'),
            wpstow_csf_button_field('log_debug', '详细日志', ['no' => '关闭', 'yes' => '开启'], 'no', '排查完成后建议关闭。', ['log_enabled', '==', 'yes']),
            ['type' => 'callback', 'function' => 'wpstow_csf_render_diagnostics'],
        ],
    ]);

    CSF::createSection($key, [
        'title' => '插件更新',
        'icon' => 'fa fa-cloud-download',
        'fields' => [
            ['type' => 'callback', 'function' => 'wpstow_csf_render_update_panel', 'class' => 'wpstow-update-field'],
        ],
    ]);
}
add_action('after_setup_theme', 'wpstow_register_csf_options', 110);
