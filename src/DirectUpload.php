<?php

namespace WPStow;

class DirectUpload
{
    private const NONCE_ACTION = 'wpstow_direct_upload';
    private const SESSION_TTL = 21600;
    private const TRANSIENT_TTL = 604800;
    private const SIMPLE_UPLOAD_LIMIT = 16777216;
    private const PRESIGN_TTL = 900;
    private const MAX_OBJECT_SIZE = 5497558138880;
    private const MAX_MULTIPART_PARTS = 10000;
    private const VALIDATION_SAMPLE_BYTES = 2097152;
    private const SESSION_LOCK_WAIT = 130;

    private static $sessionLocks = [];
    private static $shutdownRegistered = false;

    public static function init()
    {
        add_action('wp_ajax_wpstow_direct_upload_init', [__CLASS__, 'ajaxInit']);
        add_action('wp_ajax_wpstow_direct_upload_sign_parts', [__CLASS__, 'ajaxSignParts']);
        add_action('wp_ajax_wpstow_direct_upload_complete', [__CLASS__, 'ajaxComplete']);
        add_action('wp_ajax_wpstow_direct_upload_abort', [__CLASS__, 'ajaxAbort']);
        add_action('wpstow_abort_direct_upload', [__CLASS__, 'abortStaleSession']);
    }

    public static function getClientConfig()
    {
        $routes = [];
        foreach (['image', 'video', 'audio', 'other'] as $category) {
            $routes[$category] = MediaHandler::getStorageTypeForCategory($category);
        }

        return [
            'enabled' => MediaHandler::config('switch') === 'enable'
                && MediaHandler::config('keep_local') === 'no'
                && !is_multisite(),
            'routes' => $routes,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'maxUploadSize' => (int) wp_max_upload_size(),
            'concurrency' => 3,
            'maxRetries' => 4,
            'messages' => [
                'direct' => '正在直传对象存储',
                'fallback' => '直传失败，正在切换服务器上传',
            ],
        ];
    }

    public static function ajaxInit()
    {
        self::authorize();

        $filename = sanitize_file_name(wp_unslash($_POST['filename'] ?? ''));
        $clientMime = sanitize_mime_type(wp_unslash($_POST['mime_type'] ?? ''));
        $size = isset($_POST['file_size']) ? (int) wp_unslash($_POST['file_size']) : 0;
        $postId = isset($_POST['post_id']) ? (int) wp_unslash($_POST['post_id']) : 0;
        self::validateParent($postId);

        if ($filename === '' || $size < 1) {
            wp_send_json_error(['message' => '文件信息不完整'], 400);
        }
        if ($size > self::MAX_OBJECT_SIZE) {
            wp_send_json_error(['message' => '文件超过对象存储允许的 5 TiB 上限'], 413);
        }
        $maxUploadSize = (int) wp_max_upload_size();
        if ($maxUploadSize > 0 && $size > $maxUploadSize) {
            wp_send_json_error(['message' => '文件超过站点允许的上传大小'], 413);
        }
        if (is_multisite()) {
            wp_send_json_error(['message' => '多站点环境需使用 WordPress 原生上传以正确计算站点配额'], 409);
        }

        $checked = wp_check_filetype($filename, get_allowed_mime_types());
        $mimetype = sanitize_mime_type((string) $checked['type']);
        if ($mimetype === '') {
            wp_send_json_error(['message' => 'WordPress 不允许上传该文件类型'], 400);
        }
        if ($clientMime !== '' && MediaHandler::getMediaCategory($clientMime) !== MediaHandler::getMediaCategory($mimetype)) {
            wp_send_json_error(['message' => '文件扩展名与 MIME 类型不匹配'], 400);
        }

        $category = MediaHandler::getMediaCategory($mimetype);
        $storageType = MediaHandler::getStorageTypeForCategory($category);
        if (!self::isEligible($category, $storageType, $mimetype)) {
            wp_send_json_error(['message' => '当前文件需要由服务器处理'], 409);
        }

        $storageClass = MediaHandler::getStorageClass($storageType);
        $key = self::createObjectKey($filename);
        $token = bin2hex(random_bytes(24));
        $mode = $size <= self::SIMPLE_UPLOAD_LIMIT ? 'put' : 'multipart';
        $session = [
            'version' => 1,
            'token' => $token,
            'user_id' => get_current_user_id(),
            'post_id' => $postId,
            'storage_type' => $storageType,
            'storage_identity' => MediaHandler::normalizeStorageIdentity(MediaHandler::getStorageIdentity($storageType)),
            'key' => $key,
            'filename' => $filename,
            'mime_type' => $mimetype,
            'category' => $category,
            'file_size' => $size,
            'mode' => $mode,
            'upload_id' => '',
            'part_size' => 0,
            'part_count' => 1,
            'expires_at' => time() + self::SESSION_TTL,
            'status' => 'uploading',
        ];

        if ($mode === 'put') {
            $uploadUrl = $storageClass::createPresignedRequestUrl(
                'PUT',
                $key,
                [],
                self::PRESIGN_TTL,
                ['Content-Type' => $mimetype]
            );
            if ($uploadUrl === '') {
                wp_send_json_error(['message' => '无法生成直传签名'], 500);
            }
            $response = [
                'mode' => $mode,
                'upload_url' => $uploadUrl,
            ];
        } else {
            $created = $storageClass::createMultipartUpload($key, $mimetype);
            if (empty($created['status']) || empty($created['upload_id'])) {
                wp_send_json_error(['message' => $created['message'] ?? '无法创建分片上传'], 502);
            }
            $session['upload_id'] = (string) $created['upload_id'];
            $session['part_size'] = self::getPartSize($size);
            $session['part_count'] = (int) ceil($size / $session['part_size']);
            if ($session['part_count'] < 1 || $session['part_count'] > self::MAX_MULTIPART_PARTS) {
                $storageClass::abortMultipartUpload($key, $session['upload_id']);
                wp_send_json_error(['message' => '文件无法按对象存储分片限制上传'], 413);
            }
            $partUrls = self::createPartUrls($storageClass, $session, range(1, $session['part_count']));
            if (count($partUrls) !== $session['part_count']) {
                $storageClass::abortMultipartUpload($key, $session['upload_id']);
                wp_send_json_error(['message' => '无法生成完整的分片直传签名'], 500);
            }
            $response = [
                'mode' => $mode,
                'part_size' => $session['part_size'],
                'part_count' => $session['part_count'],
                'part_urls' => $partUrls,
            ];
        }

        if (!self::saveSession($session)) {
            if ($mode === 'multipart' && !empty($session['upload_id'])) {
                $storageClass::abortMultipartUpload($key, $session['upload_id']);
            }
            wp_send_json_error(['message' => '无法保存直传会话，请改用服务器上传'], 500);
        }
        wp_schedule_single_event(time() + self::SESSION_TTL + 60, 'wpstow_abort_direct_upload', [$token]);

        wp_send_json_success(array_merge($response, [
            'upload_token' => $token,
            'storage_type' => $storageType,
            'key' => $key,
            'mime_type' => $mimetype,
        ]));
    }

    public static function ajaxSignParts()
    {
        self::authorize();
        $session = self::requireSession();
        if (!self::sessionTargetsCurrentStorage($session)) {
            wp_send_json_error(['message' => '上传期间存储目标已变更，请重新上传'], 409);
        }
        if ($session['mode'] !== 'multipart' || $session['status'] !== 'uploading') {
            wp_send_json_error(['message' => '当前上传会话不支持刷新分片签名'], 409);
        }

        $numbers = isset($_POST['part_numbers']) ? (array) wp_unslash($_POST['part_numbers']) : [];
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        if (!$numbers || count($numbers) > 50) {
            wp_send_json_error(['message' => '分片编号无效'], 400);
        }
        foreach ($numbers as $number) {
            if ($number < 1 || $number > (int) $session['part_count']) {
                wp_send_json_error(['message' => '分片编号超出范围'], 400);
            }
        }

        $storageClass = MediaHandler::getStorageClass($session['storage_type']);
        $partUrls = self::createPartUrls($storageClass, $session, $numbers);
        if (count($partUrls) !== count($numbers)) {
            wp_send_json_error(['message' => '无法刷新完整的分片签名'], 500);
        }
        wp_send_json_success(['part_urls' => $partUrls]);
    }

    public static function ajaxComplete()
    {
        self::authorize();
        $token = self::getRequestToken();
        if (!self::isValidToken($token)) {
            wp_send_json_error(['message' => '上传会话不存在或已过期'], 404);
        }
        if (!self::acquireSessionLock($token, self::SESSION_LOCK_WAIT)) {
            wp_send_json_error(['message' => '上传会话正在由另一个请求处理，请稍后重试'], 409);
        }
        $session = self::requireSession();
        if ($session['status'] === 'completed' && !empty($session['attachment'])) {
            wp_send_json_success($session['attachment']);
        }
        if ($session['status'] !== 'uploading') {
            wp_send_json_error(['message' => '上传会话状态无效'], 409);
        }
        if (!self::sessionTargetsCurrentStorage($session)) {
            wp_send_json_error(['message' => '上传期间存储目标已变更，请重新上传'], 409);
        }

        $storageClass = MediaHandler::getStorageClass($session['storage_type']);
        if ($session['mode'] === 'multipart') {
            $parts = self::validateParts($session, isset($_POST['parts']) ? wp_unslash($_POST['parts']) : []);
            $completed = $storageClass::completeMultipartUpload($session['key'], $session['upload_id'], $parts);
            if (empty($completed['status'])) {
                // A lost completion response can leave a valid object and an already-closed UploadId.
                $possibleObject = $storageClass::headObject($session['key']);
                if (!self::headMatchesSession($possibleObject, $session)) {
                    wp_send_json_error(['message' => $completed['message'] ?? '合并分片失败'], 502);
                }
            }
        }

        $head = $storageClass::headObject($session['key']);
        if (!self::headMatchesSession($head, $session)) {
            self::removeRemoteObject($session, '直传校验失败');
            wp_send_json_error(['message' => '云端对象大小校验失败，已取消登记'], 502);
        }

        $inspection = self::inspectRemoteContent($storageClass, $session);
        if (empty($inspection['status'])) {
            self::removeRemoteObject($session, '直传内容校验失败');
            wp_send_json_error(['message' => $inspection['message'] ?? '云端对象内容校验失败，已取消登记'], 400);
        }

        // Image dimensions come from bytes fetched by the server, never from client claims.
        $width = (int) ($inspection['width'] ?? 0);
        $height = (int) ($inspection['height'] ?? 0);
        $attachment = self::registerAttachment($session, $width, $height);
        if (is_wp_error($attachment)) {
            self::removeRemoteObject($session, '直传登记失败');
            wp_send_json_error(['message' => $attachment->get_error_message()], 500);
        }

        $session['status'] = 'completed';
        $session['attachment'] = $attachment;
        $session['expires_at'] = time() + 600;
        self::saveSession($session, 600);
        $timestamp = wp_next_scheduled('wpstow_abort_direct_upload', [$session['token']]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wpstow_abort_direct_upload', [$session['token']]);
        }
        wp_send_json_success($attachment);
    }

    public static function ajaxAbort()
    {
        self::authorize();
        $token = self::getRequestToken();
        if (!self::isValidToken($token)) {
            wp_send_json_success(['aborted' => true]);
        }
        if (!self::acquireSessionLock($token, self::SESSION_LOCK_WAIT)) {
            wp_send_json_error(['message' => '上传会话正在完成登记，请稍后重试'], 409);
        }
        $session = self::requireSession(false);
        if (!$session) {
            wp_send_json_success(['aborted' => true]);
        }
        if (($session['status'] ?? '') === 'completed') {
            wp_send_json_success([
                'aborted' => false,
                'completed' => true,
                'attachment' => $session['attachment'] ?? null,
            ]);
        }
        self::abortSession($session);
        wp_send_json_success(['aborted' => true]);
    }

    public static function abortStaleSession($token)
    {
        $token = (string) $token;
        if (!self::isValidToken($token)) {
            return;
        }
        if (!self::acquireSessionLock($token, 1)) {
            wp_schedule_single_event(time() + 60, 'wpstow_abort_direct_upload', [$token]);
            return;
        }
        $session = self::loadSession((string) $token);
        if (!$session || ($session['status'] ?? '') === 'completed') {
            self::releaseSessionLock($token);
            return;
        }
        self::abortSession($session);
        self::releaseSessionLock($token);
    }

    private static function authorize()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => '无权上传文件'], 403);
        }
    }

    private static function validateParent($postId)
    {
        if ($postId > 0 && (!get_post($postId) || !current_user_can('edit_post', $postId))) {
            wp_send_json_error(['message' => '无权关联目标文章'], 403);
        }
    }

    private static function isEligible($category, $storageType, $mimetype = '')
    {
        if (MediaHandler::config('switch') !== 'enable' || MediaHandler::config('keep_local') !== 'no') {
            return false;
        }
        if (!in_array($storageType, ['s3', 'r2'], true) || !MediaHandler::isStorageTypeConfigured($storageType)) {
            return false;
        }
        if (in_array(strtolower((string) $mimetype), [
            'text/html', 'application/xhtml+xml', 'application/javascript', 'text/javascript',
            'application/x-httpd-php', 'application/x-php', 'text/x-php',
        ], true)) {
            return false;
        }
        if ($category === 'image') {
            return MediaHandler::config('disable_image_subsizes') === 'yes'
                && MediaHandler::config('image_compress') !== 'yes'
                && MediaHandler::config('image_watermark') !== 'yes';
        }
        if ($category === 'video') {
            return MediaHandler::config('video_compress') !== 'yes'
                && MediaHandler::config('video_watermark') !== 'yes';
        }
        return true;
    }

    private static function createObjectKey($filename)
    {
        $uploadDir = wp_upload_dir();
        $objectName = FileNaming::generateFilename($filename);
        $subdir = trim((string) $uploadDir['subdir'], '/');
        return $subdir === '' ? $objectName : $subdir . '/' . $objectName;
    }

    private static function getPartSize($fileSize)
    {
        if ($fileSize <= 134217728) {
            $partSize = 8388608;
        } elseif ($fileSize <= 1073741824) {
            $partSize = 16777216;
        } else {
            $partSize = 33554432;
        }
        while ((int) ceil($fileSize / $partSize) > 5000 && $partSize < 1073741824) {
            $partSize *= 2;
        }
        return $partSize;
    }

    private static function createPartUrls($storageClass, array $session, array $numbers)
    {
        $urls = [];
        foreach ($numbers as $number) {
            $url = $storageClass::createMultipartPartUrl(
                $session['key'],
                $session['upload_id'],
                $number,
                self::PRESIGN_TTL
            );
            if ($url === '') {
                return [];
            }
            $urls[(string) $number] = $url;
        }
        return $urls;
    }

    private static function validateParts(array $session, $rawParts)
    {
        if (is_string($rawParts)) {
            $rawParts = json_decode($rawParts, true);
        }
        if (!is_array($rawParts) || count($rawParts) !== (int) $session['part_count']) {
            wp_send_json_error(['message' => '分片数量不完整'], 400);
        }

        $parts = [];
        foreach ($rawParts as $part) {
            $number = (int) ($part['part_number'] ?? 0);
            $etag = trim((string) ($part['etag'] ?? ''), "\" \t\r\n");
            if ($number < 1 || $number > (int) $session['part_count'] || !preg_match('/^[A-Za-z0-9+\/_=-]{8,128}$/', $etag)) {
                wp_send_json_error(['message' => '分片 ETag 无效'], 400);
            }
            $parts[$number] = ['part_number' => $number, 'etag' => $etag];
        }
        ksort($parts, SORT_NUMERIC);
        if (array_keys($parts) !== range(1, (int) $session['part_count'])) {
            wp_send_json_error(['message' => '分片编号不连续'], 400);
        }
        return array_values($parts);
    }

    private static function headMatchesSession(array $head, array $session)
    {
        if (empty($head['status'])) {
            return false;
        }
        $length = isset($head['headers']['content-length']) ? (int) $head['headers']['content-length'] : -1;
        $remoteMimeHeader = (string) ($head['headers']['content-type'] ?? '');
        $remoteMime = sanitize_mime_type(trim(strtok($remoteMimeHeader, ';')));
        return $length === (int) $session['file_size']
            && ($remoteMime === '' || $remoteMime === $session['mime_type']);
    }

    private static function inspectRemoteContent($storageClass, array $session)
    {
        $url = $storageClass::createPresignedRequestUrl('GET', $session['key'], [], 300);
        if ($url === '') {
            return ['status' => false, 'message' => '无法生成云端内容校验地址'];
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => [
                'Range' => 'bytes=0-' . (self::VALIDATION_SAMPLE_BYTES - 1),
            ],
            'limit_response_size' => self::VALIDATION_SAMPLE_BYTES,
        ]);
        if (is_wp_error($response)) {
            return ['status' => false, 'message' => '无法读取云端对象进行内容校验'];
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $sample = (string) wp_remote_retrieve_body($response);
        if (!in_array($httpCode, [200, 206], true) || $sample === '') {
            return ['status' => false, 'message' => '云端对象内容校验请求失败'];
        }

        $expectedMime = strtolower((string) $session['mime_type']);
        $category = (string) ($session['category'] ?? 'other');
        if ($category === 'image') {
            $imageInfo = @getimagesizefromstring($sample);
            $actualMime = is_array($imageInfo) ? strtolower((string) ($imageInfo['mime'] ?? '')) : '';
            if (!is_array($imageInfo) || self::normalizeComparableMime($actualMime) !== self::normalizeComparableMime($expectedMime)) {
                return ['status' => false, 'message' => '图片内容与扩展名不匹配'];
            }
            $width = (int) ($imageInfo[0] ?? 0);
            $height = (int) ($imageInfo[1] ?? 0);
            if ($width < 1 || $height < 1 || $width * $height > 100000000) {
                return ['status' => false, 'message' => '图片尺寸无效或超过一亿像素'];
            }
            return ['status' => true, 'width' => $width, 'height' => $height, 'detected_mime' => $actualMime];
        }

        $detectedMime = '';
        if (extension_loaded('fileinfo') && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = strtolower((string) finfo_buffer($finfo, $sample));
                finfo_close($finfo);
            }
        }
        if ($detectedMime === '' || $detectedMime === 'application/octet-stream') {
            return ['status' => false, 'message' => '无法识别文件真实类型，请改用服务器上传'];
        }

        if (in_array($category, ['video', 'audio'], true)
            && MediaHandler::getMediaCategory($detectedMime) !== $category) {
            return ['status' => false, 'message' => '文件内容与声明的媒体类型不匹配'];
        }

        $activeMimes = [
            'text/html', 'application/xhtml+xml', 'image/svg+xml',
            'application/javascript', 'text/javascript',
            'application/x-httpd-php', 'application/x-php', 'text/x-php',
        ];
        if (in_array($detectedMime, $activeMimes, true) && $detectedMime !== $expectedMime) {
            return ['status' => false, 'message' => '文件内容包含与扩展名不符的可执行格式'];
        }

        return ['status' => true, 'width' => 0, 'height' => 0, 'detected_mime' => $detectedMime];
    }

    private static function normalizeComparableMime($mimetype)
    {
        $mimetype = strtolower(trim((string) $mimetype));
        $aliases = [
            'image/jpg' => 'image/jpeg',
            'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
        ];
        return $aliases[$mimetype] ?? $mimetype;
    }

    private static function registerAttachment(array $session, $width, $height)
    {
        $storageClass = MediaHandler::getStorageClass($session['storage_type']);
        $title = sanitize_text_field(pathinfo($session['filename'], PATHINFO_FILENAME));
        $attachmentId = wp_insert_attachment([
            'guid' => $storageClass::getCloudUrl($session['key']),
            'post_mime_type' => $session['mime_type'],
            'post_title' => $title !== '' ? $title : $session['filename'],
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => (int) $session['post_id'],
        ], false, (int) $session['post_id'], true);
        if (is_wp_error($attachmentId)) {
            return $attachmentId;
        }

        if (!update_attached_file($attachmentId, $session['key'])) {
            wp_delete_attachment($attachmentId, true);
            return new \WP_Error('wpstow_attached_file_failed', '无法保存附件文件路径');
        }
        $meta = [
            'file' => $session['key'],
            'filesize' => (int) $session['file_size'],
        ];
        if (strpos($session['mime_type'], 'image/') === 0) {
            $meta['width'] = $width;
            $meta['height'] = $height;
            $meta['sizes'] = [];
            $meta['image_meta'] = [];
        }
        if (wp_update_attachment_metadata($attachmentId, $meta) === false) {
            wp_delete_attachment($attachmentId, true);
            return new \WP_Error('wpstow_attachment_metadata_failed', '无法保存附件元数据');
        }

        update_post_meta($attachmentId, '_wpstow_cloud_key', $session['key']);
        update_post_meta($attachmentId, '_wpstow_storage_type', $session['storage_type']);
        update_post_meta($attachmentId, '_wpstow_uploaded', '1');
        update_post_meta($attachmentId, '_wpstow_storage_manifest', [
            'version' => 2,
            'storage_type' => $session['storage_type'],
            'storage_identity' => MediaHandler::getStorageIdentity($session['storage_type']),
            'main_key' => $session['key'],
            'keys' => [$session['key']],
            'created_at' => gmdate('c'),
        ]);
        delete_post_meta($attachmentId, '_wpstow_pending');
        delete_post_meta($attachmentId, '_wpstow_pending_at');
        delete_post_meta($attachmentId, '_wpstow_pending_storage');
        delete_post_meta($attachmentId, '_wpstow_upload_error');

        $prepared = wp_prepare_attachment_for_js($attachmentId);
        if (!$prepared) {
            wp_delete_attachment($attachmentId, true);
            return new \WP_Error('wpstow_prepare_attachment_failed', '媒体附件创建后无法读取');
        }
        return $prepared;
    }

    private static function requireSession($fail = true)
    {
        $token = self::getRequestToken();
        $session = self::loadSession($token);
        if (!$session || (int) ($session['user_id'] ?? 0) !== get_current_user_id()) {
            if ($fail) {
                wp_send_json_error(['message' => '上传会话不存在或已过期'], 404);
            }
            return null;
        }
        if (($session['expires_at'] ?? 0) < time() && ($session['status'] ?? '') !== 'completed') {
            self::abortSession($session);
            if ($fail) {
                wp_send_json_error(['message' => '上传会话已过期'], 410);
            }
            return null;
        }
        return $session;
    }

    private static function saveSession(array $session, $ttl = self::TRANSIENT_TTL)
    {
        return set_transient(self::transientKey($session['token']), $session, $ttl);
    }

    private static function loadSession($token)
    {
        if (!self::isValidToken($token)) {
            return null;
        }
        $session = get_transient(self::transientKey($token));
        return is_array($session) ? $session : null;
    }

    private static function transientKey($token)
    {
        return 'wpstow_du_' . hash('sha256', $token);
    }

    private static function getRequestToken()
    {
        return sanitize_text_field(wp_unslash($_POST['upload_token'] ?? ''));
    }

    private static function isValidToken($token)
    {
        return is_string($token) && preg_match('/^[a-f0-9]{48}$/', $token) === 1;
    }

    private static function acquireSessionLock($token, $waitSeconds)
    {
        if (!self::isValidToken($token)) {
            return false;
        }
        if (isset(self::$sessionLocks[$token])) {
            return true;
        }

        global $wpdb;
        $lockName = 'wpstow_du_' . substr(hash('sha256', $token), 0, 48);
        $acquired = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lockName,
            max(0, (int) $waitSeconds)
        )) === 1;
        if (!$acquired) {
            return false;
        }

        self::$sessionLocks[$token] = $lockName;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([__CLASS__, 'releaseAllSessionLocks']);
        }
        return true;
    }

    private static function releaseSessionLock($token)
    {
        if (!isset(self::$sessionLocks[$token])) {
            return;
        }
        global $wpdb;
        $lockName = self::$sessionLocks[$token];
        unset(self::$sessionLocks[$token]);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
    }

    public static function releaseAllSessionLocks()
    {
        foreach (array_keys(self::$sessionLocks) as $token) {
            self::releaseSessionLock($token);
        }
    }

    private static function abortSession(array $session)
    {
        $storageClass = MediaHandler::getStorageClass($session['storage_type']);
        if ($storageClass) {
            if ($session['mode'] === 'multipart' && !empty($session['upload_id'])) {
                if (self::sessionTargetsCurrentStorage($session)) {
                    $storageClass::abortMultipartUpload($session['key'], $session['upload_id']);
                } else {
                    Utils::writeLog('存储目标已变更，无法安全终止旧目标的分片上传: key=' . $session['key']);
                }
            } else {
                self::removeRemoteObject($session, '取消浏览器直传');
            }
        }
        delete_transient(self::transientKey($session['token']));
        $timestamp = wp_next_scheduled('wpstow_abort_direct_upload', [$session['token']]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wpstow_abort_direct_upload', [$session['token']]);
        }
    }

    private static function removeRemoteObject(array $session, $context)
    {
        CloudDeletionQueue::deleteObject(
            $session['storage_type'],
            $session['key'],
            $context,
            isset($session['storage_identity']) && is_array($session['storage_identity'])
                ? $session['storage_identity']
                : MediaHandler::getStorageIdentity($session['storage_type'])
        );
    }

    private static function sessionTargetsCurrentStorage(array $session)
    {
        if (empty($session['storage_identity']) || !is_array($session['storage_identity'])) {
            return true;
        }

        return MediaHandler::normalizeStorageIdentity($session['storage_identity'])
            === MediaHandler::normalizeStorageIdentity(
                MediaHandler::getStorageIdentity((string) ($session['storage_type'] ?? ''))
            );
    }
}
