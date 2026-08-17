<?php

namespace WPStow;

class MediaLibraryManager
{
    private const SCAN_PAGE_SIZE = 100;
    private const LOCK_TTL = 900;
    private const LOCK_META = '_wpstow_batch_lock';

    private const STATUS_LABELS = [
        'managed' => '已接管',
        'ready' => '可接管',
        'failed' => '上次失败',
        'pending' => '正在处理',
        'local' => '仅本地路由',
        'missing' => '源文件缺失',
        'unavailable' => '配置不可用',
    ];

    private const STORAGE_LABELS = [
        'oneimg' => 'OneImg',
        'superbed' => '聚合图床',
        's3' => 'S3',
        'r2' => 'Cloudflare R2',
        'webdav' => 'WebDAV',
        'ftp' => 'FTP / FTPS',
        'local' => '仅本地',
    ];

    public static function ajaxScan()
    {
        self::authorize();
        $category = self::sanitizeCategory(isset($_POST['category']) ? wp_unslash($_POST['category']) : 'all');
        $cursor = isset($_POST['cursor']) ? max(0, (int) wp_unslash($_POST['cursor'])) : 0;
        $maxId = isset($_POST['max_id']) ? max(0, (int) wp_unslash($_POST['max_id'])) : 0;
        wp_send_json_success(self::scanBatch($category, $cursor, $maxId));
    }

    private static function authorize()
    {
        check_ajax_referer('wpstow_admin', 'nonce');
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_send_json_error(['message' => '权限不足'], 403);
        }
    }

    public static function sanitizeCategory($category)
    {
        $category = sanitize_key(wp_unslash((string) $category));
        return in_array($category, ['all', 'image', 'video', 'audio', 'other'], true) ? $category : 'all';
    }

    private static function categorySql($category, array &$params)
    {
        global $wpdb;
        if (in_array($category, ['image', 'video', 'audio'], true)) {
            $params[] = $wpdb->esc_like($category . '/') . '%';
            return ' AND p.post_mime_type LIKE %s';
        }
        if ($category === 'other') {
            foreach (['image/', 'video/', 'audio/'] as $prefix) {
                $params[] = $wpdb->esc_like($prefix) . '%';
            }
            return ' AND p.post_mime_type NOT LIKE %s AND p.post_mime_type NOT LIKE %s AND p.post_mime_type NOT LIKE %s';
        }
        return '';
    }

    public static function getMaxId($category)
    {
        global $wpdb;
        $params = [];
        $categorySql = self::categorySql($category, $params);
        $sql = "SELECT COALESCE(MAX(p.ID), 0) FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'" . $categorySql;
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, $params)) : $wpdb->get_var($sql));
    }

    public static function getTotal($category, $maxId)
    {
        global $wpdb;
        $params = [(int) $maxId];
        $categorySql = self::categorySql($category, $params);
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND p.ID <= %d" . $categorySql;
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    public static function getAttachmentIds($category, $cursor, $maxId, $limit)
    {
        global $wpdb;
        $params = [(int) $cursor, (int) $maxId];
        $categorySql = self::categorySql($category, $params);
        $params[] = max(1, (int) $limit);
        $sql = "SELECT p.ID FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment' AND p.post_status <> 'trash'
            AND p.ID > %d AND p.ID <= %d" . $categorySql . '
            ORDER BY p.ID ASC LIMIT %d';
        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $params)));
    }

    private static function emptyCounts()
    {
        return array_fill_keys(array_keys(self::STATUS_LABELS), 0);
    }

    private static function hasFreshPendingState($attachmentId)
    {
        if (!get_post_meta($attachmentId, '_wpstow_pending', true)) {
            return false;
        }
        $pendingAt = (int) get_post_meta($attachmentId, '_wpstow_pending_at', true);
        return $pendingAt > 0 && $pendingAt >= time() - self::LOCK_TTL;
    }

    private static function getLock($attachmentId, $cleanup = false)
    {
        $lock = get_post_meta($attachmentId, self::LOCK_META, true);
        if (!is_array($lock) || empty($lock['token']) || empty($lock['created_at'])) {
            if ($cleanup && $lock !== '') {
                delete_post_meta($attachmentId, self::LOCK_META);
            }
            return null;
        }
        if ((int) $lock['created_at'] < time() - self::LOCK_TTL) {
            if ($cleanup) {
                delete_post_meta($attachmentId, self::LOCK_META);
            }
            return null;
        }
        return $lock;
    }

    public static function classify($attachmentId)
    {
        $status = MediaHandler::getProcessingStatus($attachmentId);
        $category = MediaHandler::getMediaCategory(get_post_mime_type($attachmentId));
        $storageType = $status['storage_type'] ?: MediaHandler::getStorageTypeForCategory($category);

        if (!empty($status['uploaded'])) {
            $code = 'managed';
        } elseif (self::getLock($attachmentId) || self::hasFreshPendingState($attachmentId)) {
            $code = 'pending';
        } elseif ($storageType === 'local') {
            $code = 'local';
        } elseif (empty($status['local_exists'])) {
            $code = 'missing';
        } elseif (!MediaHandler::isStorageEnabledAndConfigured($attachmentId)) {
            $code = 'unavailable';
        } elseif (get_post_meta($attachmentId, '_wpstow_upload_error', true) !== '') {
            $code = 'failed';
        } else {
            $code = 'ready';
        }

        return [
            'id' => (int) $attachmentId,
            'title' => get_the_title($attachmentId) ?: basename((string) get_attached_file($attachmentId)),
            'category' => $category,
            'mime_type' => (string) get_post_mime_type($attachmentId),
            'status' => $code,
            'status_label' => self::STATUS_LABELS[$code],
            'storage_type' => $storageType,
            'storage_label' => self::STORAGE_LABELS[$storageType] ?? strtoupper((string) $storageType),
            'message' => self::statusMessage($code, $status),
            'actionable' => in_array($code, ['ready', 'failed'], true),
            'edit_url' => current_user_can('edit_post', $attachmentId) ? get_edit_post_link($attachmentId, 'raw') : '',
        ];
    }

    private static function statusMessage($code, array $status)
    {
        switch ($code) {
            case 'managed':
                return $status['label'];
            case 'ready':
                return '本地源文件和目标存储均可用';
            case 'failed':
                return (string) ($status['message'] ?: '上次处理未成功，可重试');
            case 'pending':
                return '已有上传任务正在处理该附件';
            case 'local':
                return '该文件类型当前不进入云端';
            case 'missing':
                return 'WordPress 记录存在，但本地源文件不可读';
            case 'unavailable':
                return '自动转存未启用或目标存储配置不完整';
            default:
                return '';
        }
    }

    public static function scanBatch($category = 'all', $cursor = 0, $maxId = 0)
    {
        $category = self::sanitizeCategory($category);
        $maxId = $maxId > 0 ? (int) $maxId : self::getMaxId($category);
        $ids = $maxId > 0 ? self::getAttachmentIds($category, $cursor, $maxId, self::SCAN_PAGE_SIZE) : [];
        $counts = self::emptyCounts();
        $items = [];

        foreach ($ids as $attachmentId) {
            $item = self::classify($attachmentId);
            $counts[$item['status']]++;
            $items[] = $item;
        }

        $nextCursor = $ids ? (int) end($ids) : (int) $cursor;
        return [
            'category' => $category,
            'cursor' => $nextCursor,
            'max_id' => $maxId,
            'total' => (int) $cursor === 0 ? self::getTotal($category, $maxId) : null,
            'scanned' => count($ids),
            'counts' => $counts,
            'items' => $items,
            'done' => !$ids || count($ids) < self::SCAN_PAGE_SIZE || $nextCursor >= $maxId,
            'keep_local' => MediaHandler::shouldKeepLocalFiles(),
        ];
    }

    private static function acquireLock($attachmentId)
    {
        self::getLock($attachmentId, true);
        $lock = [
            'token' => wp_generate_uuid4(),
            'created_at' => time(),
        ];
        return add_post_meta($attachmentId, self::LOCK_META, $lock, true) ? $lock : null;
    }

    private static function releaseLock($attachmentId, array $lock)
    {
        delete_post_meta($attachmentId, self::LOCK_META, $lock);
    }

    public static function processAttachment($attachmentId, $checkCapability = true)
    {
        $attachmentId = (int) $attachmentId;
        if (get_post_type($attachmentId) !== 'attachment') {
            return ['status' => false, 'message' => '附件不存在'];
        }
        if ($checkCapability && !current_user_can('edit_post', $attachmentId)) {
            return ['status' => false, 'message' => '无权操作该附件'];
        }

        $before = self::classify($attachmentId);
        if (!$before['actionable']) {
            return ['status' => false, 'skipped' => true, 'message' => $before['message'], 'item' => $before];
        }

        $lock = self::acquireLock($attachmentId);
        if (!$lock) {
            return [
                'status' => false,
                'skipped' => true,
                'message' => '附件已被其他任务锁定',
                'previous_status' => $before['status'],
                'item' => self::classify($attachmentId),
            ];
        }

        update_post_meta($attachmentId, '_wpstow_pending', '1');
        update_post_meta($attachmentId, '_wpstow_pending_at', time());
        update_post_meta($attachmentId, '_wpstow_pending_storage', $before['storage_type']);
        try {
            $success = MediaHandler::update_to_cloud($attachmentId);
        } catch (\Throwable $e) {
            $success = false;
            update_post_meta($attachmentId, '_wpstow_upload_error', '批量接管异常，本地文件已保留');
            Utils::writeLog('批量接管异常: attachment_id=' . $attachmentId . ', ' . $e->getMessage());
        } finally {
            delete_post_meta($attachmentId, '_wpstow_pending');
            delete_post_meta($attachmentId, '_wpstow_pending_at');
            delete_post_meta($attachmentId, '_wpstow_pending_storage');
            self::releaseLock($attachmentId, $lock);
        }

        $after = self::classify($attachmentId);
        return [
            'status' => (bool) $success,
            'skipped' => false,
            'message' => $success ? '接管完成' : ((string) get_post_meta($attachmentId, '_wpstow_upload_error', true) ?: '接管失败，本地文件已保留'),
            'previous_status' => $before['status'],
            'item' => $after,
        ];
    }

}
