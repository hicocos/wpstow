<?php

use WPStow\MediaHandler;
use WPStow\Utils;
use WPStow\VideoProcessor;

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
    $legacyType = wpstow_csf_choice($legacyType, ['oneimg', 's3', 'webdav', 'ftp'], 's3');
    return $category !== 'image' && $legacyType === 'oneimg' ? 'local' : $legacyType;
}

function wpstow_csf_url($value)
{
    $value = trim((string) $value);
    return $value === '' ? '' : esc_url_raw($value);
}

function wpstow_csf_text($value)
{
    return sanitize_text_field((string) $value);
}

function wpstow_csf_saved_secret(array $data, array $previous, $inputKey, $storageKey)
{
    $value = isset($data[$inputKey]) ? trim((string) $data[$inputKey]) : '';
    return $value === '' ? (string) ($previous[$storageKey] ?? '') : sanitize_text_field($value);
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
    $legacyType = wpstow_csf_choice($previous['storage_type'] ?? 's3', ['oneimg', 's3', 'webdav', 'ftp'], 's3');
    $normalized['storage_type'] = $legacyType;
    $normalized['provider_config_type'] = wpstow_csf_choice(
        $data['provider_config_type'] ?? ($previous['provider_config_type'] ?? $legacyType),
        ['oneimg', 's3', 'webdav', 'ftp'],
        $legacyType
    );
    $normalized['image_storage_type'] = wpstow_csf_choice(
        $data['image_storage_type'] ?? ($previous['image_storage_type'] ?? wpstow_csf_route_default($legacyType, 'image')),
        ['oneimg', 's3', 'webdav', 'ftp', 'local'],
        wpstow_csf_route_default($legacyType, 'image')
    );
    foreach (['video', 'audio', 'other'] as $category) {
        $key = $category . '_storage_type';
        $default = wpstow_csf_route_default($legacyType, $category);
        $normalized[$key] = wpstow_csf_choice($data[$key] ?? ($previous[$key] ?? $default), ['s3', 'webdav', 'ftp', 'local'], $default);
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

    $normalized['oneimg_endpoint'] = wpstow_csf_url($providerValue('oneimg', 'oneimg_endpoint'));
    $normalized['oneimg_token'] = wpstow_csf_saved_secret($editingProvider === 'oneimg' ? $data : [], $previous, 'oneimg_token_input', 'oneimg_token');

    $normalized['s3_endpoint'] = wpstow_csf_url($providerValue('s3', 's3_endpoint'));
    $normalized['s3_bucket'] = wpstow_csf_text($providerValue('s3', 's3_bucket'));
    $normalized['s3_access_key'] = wpstow_csf_saved_secret($editingProvider === 's3' ? $data : [], $previous, 's3_access_key_input', 's3_access_key');
    $normalized['s3_secret_key'] = wpstow_csf_saved_secret($editingProvider === 's3' ? $data : [], $previous, 's3_secret_key_input', 's3_secret_key');
    $normalized['s3_region'] = wpstow_csf_text($providerValue('s3', 's3_region', 'us-east-1'));
    $normalized['s3_path_style'] = wpstow_csf_choice($providerValue('s3', 's3_path_style', 'no'), ['yes', 'no'], 'no');
    $normalized['s3_custom_url'] = wpstow_csf_url($providerValue('s3', 's3_custom_url'));

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
    $normalized['image_compress'] = wpstow_csf_choice($data['image_compress'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['image_compress_quality'] = min(100, max(10, (int) ($data['image_compress_quality'] ?? 80)));
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

    $normalized['video_compress'] = wpstow_csf_choice($data['video_compress'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['video_compress_quality'] = wpstow_csf_choice($data['video_compress_quality'] ?? 'medium', ['low', 'medium', 'high'], 'medium');
    $normalized['video_max_resolution'] = wpstow_csf_choice($data['video_max_resolution'] ?? '1080p', ['480p', '720p', '1080p', '2160p', 'original'], '1080p');
    $normalized['video_watermark'] = wpstow_csf_choice($data['video_watermark'] ?? 'no', ['yes', 'no'], 'no');

    $normalized['log_enabled'] = wpstow_csf_choice($data['log_enabled'] ?? 'no', ['yes', 'no'], 'no');
    $normalized['log_debug'] = wpstow_csf_choice($data['log_debug'] ?? 'no', ['yes', 'no'], 'no');

    unset(
        $normalized['oneimg_token_input'],
        $normalized['s3_access_key_input'],
        $normalized['s3_secret_key_input'],
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

function wpstow_csf_validate_video_feature($value)
{
    if ($value === 'yes' && !VideoProcessor::checkFFmpeg()) {
        return '未检测到可用 FFmpeg，无法启用此功能。';
    }
    return '';
}

function wpstow_csf_backend_configured($storageType)
{
    if ($storageType === 'oneimg') {
        return MediaHandler::rawSetting('oneimg_endpoint', '') !== ''
            && MediaHandler::rawSetting('oneimg_token', '') !== '';
    }
    if ($storageType === 's3') {
        return MediaHandler::rawSetting('s3_endpoint', '') !== ''
            && MediaHandler::rawSetting('s3_access_key', '') !== ''
            && MediaHandler::rawSetting('s3_secret_key', '') !== ''
            && MediaHandler::rawSetting('s3_bucket', '') !== '';
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
    $storageNames = ['oneimg' => 'OneImg', 's3' => 'S3 / R2', 'webdav' => 'WebDAV', 'ftp' => 'FTP / FTPS', 'local' => '仅本地'];
    $categoryNames = ['image' => '图片', 'video' => '视频', 'audio' => '音频', 'other' => '其他'];
    $storageEnabled = MediaHandler::config('switch') === 'enable';
    $storageReady = MediaHandler::isStorageEnabledAndConfigured();
    $fallbackLocal = MediaHandler::config('cloud_fallback_local') !== 'no';
    $mediaUrlMode = MediaHandler::config('media_url_mode') === 'local' ? 'local' : 'cloud';
    $ffmpegAvailable = VideoProcessor::checkFFmpeg();

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
        ['视频处理', $ffmpegAvailable ? 'FFmpeg 可用' : 'FFmpeg 不可用', $ffmpegAvailable ? '可以启用视频处理。' : '视频处理开关会被拒绝保存。', $ffmpegAvailable],
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
    $hasS3AccessKey = MediaHandler::rawSetting('s3_access_key', '') !== '';
    $hasS3Secret = MediaHandler::rawSetting('s3_secret_key', '') !== '';
    $hasWebdavPassword = MediaHandler::rawSetting('webdav_password', '') !== '';
    $hasFtpPassword = MediaHandler::rawSetting('ftp_password', '') !== '';
    $ffmpegAvailable = VideoProcessor::checkFFmpeg();
    $legacyType = wpstow_csf_choice(MediaHandler::rawSetting('storage_type', 's3'), ['oneimg', 's3', 'webdav', 'ftp'], 's3');
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
        'icon' => 'fas fa-cloud',
        'fields' => [
            ['type' => 'subheading', 'content' => '<span class="wpstow-step-title"><b>1</b> 按文件类型分配存储</span><small>选择“仅本地”时，该类型不会进入云端上传流程。OneImg 仅支持图片。</small>', 'class' => 'wpstow-step-heading'],
            [
                'id' => 'image_storage_type',
                'title' => '图片',
                'type' => 'select',
                'options' => [
                    'oneimg' => 'OneImg 图床',
                    's3' => 'S3 / R2 对象存储',
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
                    's3' => 'S3 / R2 对象存储',
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
                    's3' => 'S3 / R2 对象存储',
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
                    's3' => 'S3 / R2 对象存储',
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
                    's3' => 'S3 / R2 对象存储',
                    'webdav' => 'WebDAV',
                    'ftp' => 'FTP / FTPS',
                ],
                'default' => $legacyType,
                'class' => 'wpstow-storage-picker',
            ],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接 OneImg</span><small>开源图床程序，WPStow 通过 API 上传图片。<a href="https://github.com/onexru/oneimg" target="_blank" rel="noopener noreferrer">查看项目与部署说明</a></small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 'oneimg']],
            ['id' => 'oneimg_endpoint', 'title' => '图床地址', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://img.example.com', 'desc' => '填写 OneImg 站点根地址，不要附加 <code>/api</code>。', 'dependency' => ['provider_config_type', '==', 'oneimg']],
            ['id' => 'oneimg_token_input', 'title' => 'API Token', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasOneImgToken ? '已保存，留空不变' : '请输入 OneImg API Token', 'desc' => '在 OneImg 后台生成。为安全起见，已保存的 Token 不会回显。', 'dependency' => ['provider_config_type', '==', 'oneimg']],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">连接 S3 / R2</span><small>适用于 Amazon S3、Cloudflare R2、MinIO 等 S3 兼容服务。</small>', 'class' => 'wpstow-step-heading wpstow-provider-heading', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_endpoint', 'title' => 'Endpoint', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://s3.amazonaws.com', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_bucket', 'title' => 'Bucket', 'type' => 'text', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_access_key_input', 'title' => 'Access Key ID', 'type' => 'text', 'attributes' => ['autocomplete' => 'off'], 'placeholder' => $hasS3AccessKey ? '已保存，留空不变' : '请输入 Access Key ID', 'desc' => '不会回显已保存值；输入新值才会替换。', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_secret_key_input', 'title' => 'Secret Key', 'type' => 'text', 'attributes' => ['type' => 'password', 'autocomplete' => 'new-password'], 'placeholder' => $hasS3Secret ? '已保存，留空不变' : '请输入 Secret Key', 'desc' => '留空保存表示保留原值。', 'dependency' => ['provider_config_type', '==', 's3']],
            ['id' => 's3_region', 'title' => 'Region', 'type' => 'text', 'default' => 'us-east-1', 'placeholder' => 'us-east-1 / auto', 'desc' => 'Cloudflare R2 常用 auto，MinIO 通常可用 us-east-1。', 'dependency' => ['provider_config_type', '==', 's3']],
            wpstow_csf_button_field('s3_path_style', '路径样式', ['no' => '虚拟主机样式', 'yes' => '路径样式'], 'no', '', ['provider_config_type', '==', 's3']),
            ['id' => 's3_custom_url', 'title' => '自定义访问 URL', 'type' => 'text', 'attributes' => ['type' => 'url'], 'placeholder' => 'https://cdn.example.com', 'desc' => '公开 CDN 域名填这里；私有桶继续使用代理。', 'dependency' => ['provider_config_type', '==', 's3']],
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
            wpstow_csf_button_field('keep_local', '本地副本', ['yes' => '保留（推荐）', 'no' => '上传后删除'], 'yes', '保留副本便于故障回退、迁移和重新处理；关闭前请确认远端存储可靠。'),
            wpstow_csf_button_field('media_url_mode', '媒体访问地址', ['cloud' => '云端优先', 'local' => '本站优先'], 'cloud', '决定页面优先使用哪个地址，不会迁移或删除已有文件。', ['keep_local', '==', 'yes']),
            wpstow_csf_button_field('cloud_fallback_local', '云端读取失败时', ['yes' => '使用本地副本', 'no' => '不回退'], 'yes', '仅代理读取可自动判断故障；浏览器直连公开 CDN 时无法自动感知。', ['keep_local|media_url_mode', '==|==', 'yes|cloud']),
        ],
    ]);

    CSF::createSection($key, [
        'title' => '图片处理',
        'icon' => 'fas fa-image',
        'fields' => [
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">外部图片入库</span><small>保存文章时，将正文里的外链或 Base64 图片纳入 WordPress 媒体库。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('localize_images', '自动本地化', ['no' => '关闭', 'yes' => '开启'], 'no', '开启后会由服务器请求外部图片；请确认站点允许访问的网络范围。'),
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">上传前处理</span><small>以下处理会在文件转存前执行，未开启的功能不会改变原图。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('image_compress', '图片压缩', ['no' => '关闭', 'yes' => '启用'], 'no'),
            ['id' => 'image_compress_quality', 'title' => '压缩质量', 'type' => 'slider', 'min' => 10, 'max' => 100, 'step' => 1, 'unit' => '%', 'default' => 80, 'desc' => '建议 70–85，数值越小压缩越强。', 'dependency' => ['image_compress', '==', 'yes']],
            wpstow_csf_button_field('image_watermark', '图片水印', ['no' => '关闭', 'yes' => '启用'], 'no'),
            wpstow_csf_button_field('watermark_type', '水印类型', ['text' => '文字水印', 'image' => '图片水印'], 'text', '', ['image_watermark', '==', 'yes']),
            ['id' => 'watermark_text', 'title' => '水印文字', 'type' => 'text', 'placeholder' => '请输入水印文字', 'dependency' => ['image_watermark|watermark_type', '==|==', 'yes|text']],
            ['id' => 'watermark_image', 'title' => '水印图片', 'type' => 'media', 'url' => false, 'library' => ['image'], 'button_title' => '选择水印图片', 'desc' => '建议使用透明背景 PNG。', 'dependency' => ['image_watermark|watermark_type', '==|==', 'yes|image']],
            wpstow_csf_button_field('watermark_position', '水印位置', ['top-left' => '左上', 'top-center' => '顶部居中', 'top-right' => '右上', 'center-left' => '左侧居中', 'center' => '居中', 'center-right' => '右侧居中', 'bottom-left' => '左下', 'bottom-center' => '底部居中', 'bottom-right' => '右下'], 'bottom-right', '', ['image_watermark', '==', 'yes']),
            ['id' => 'watermark_opacity', 'title' => '水印透明度', 'type' => 'slider', 'min' => 10, 'max' => 100, 'step' => 1, 'unit' => '%', 'default' => 50, 'dependency' => ['image_watermark', '==', 'yes']],
            wpstow_csf_button_field('keep_original', '处理前原图', ['yes' => '保留原图（推荐）', 'no' => '仅保留处理结果'], 'yes', '仅在启用压缩或水印后生效；保留原图便于恢复。'),
        ],
    ]);

    $videoFields = [];
    if (!$ffmpegAvailable) {
        $videoFields[] = ['type' => 'notice', 'style' => 'warning', 'content' => '未检测到可用 FFmpeg。可能尚未安装，或 PHP-FPM 禁用了 exec；尝试启用视频处理时会拒绝保存。'];
    }
    $videoFields[] = ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">视频转码</span><small>处理由服务器上的 FFmpeg 完成，可能占用较多 CPU 和时间。</small>', 'class' => 'wpstow-step-heading'];
    $videoFields[] = wpstow_csf_button_field('video_compress', '视频压缩', ['no' => '关闭', 'yes' => '启用'], 'no', '压缩视频以减少存储空间和带宽消耗。');
    $videoFields[count($videoFields) - 1]['validate'] = 'wpstow_csf_validate_video_feature';
    $videoFields[] = wpstow_csf_button_field('video_compress_quality', '压缩质量', ['low' => '低 · 更小体积', 'medium' => '中 · 平衡', 'high' => '高 · 更好画质'], 'medium', '', ['video_compress', '==', 'yes']);
    $videoFields[] = wpstow_csf_button_field('video_max_resolution', '最大分辨率', ['480p' => '480p', '720p' => '720p', '1080p' => '1080p', '2160p' => '4K', 'original' => '保持原始'], '1080p', '超过该分辨率的视频会被缩放。', ['video_compress', '==', 'yes']);
    $videoFields[] = wpstow_csf_button_field('video_watermark', '视频水印', ['no' => '关闭', 'yes' => '启用'], 'no', '使用“图片处理”中选择的水印图片；未选择图片时不会处理。');
    $videoFields[count($videoFields) - 1]['validate'] = 'wpstow_csf_validate_video_feature';

    CSF::createSection($key, [
        'title' => '视频处理',
        'icon' => 'fa fa-video-camera',
        'fields' => $videoFields,
    ]);

    CSF::createSection($key, [
        'title' => '运行状态',
        'icon' => 'fas fa-heartbeat',
        'fields' => [
            ['type' => 'callback', 'function' => 'wpstow_csf_render_status'],
            ['type' => 'subheading', 'content' => '<span class="wpstow-section-heading">故障排查</span><small>正常使用无需开启日志或执行自检。</small>', 'class' => 'wpstow-step-heading'],
            wpstow_csf_button_field('log_enabled', '运行日志', ['no' => '关闭', 'yes' => '开启'], 'no', '默认关闭，需要排查问题时再开启。'),
            wpstow_csf_button_field('log_debug', '详细日志', ['no' => '关闭', 'yes' => '开启'], 'no', '排查完成后建议关闭。', ['log_enabled', '==', 'yes']),
            ['type' => 'callback', 'function' => 'wpstow_csf_render_diagnostics'],
        ],
    ]);
}
add_action('after_setup_theme', 'wpstow_register_csf_options', 20);
