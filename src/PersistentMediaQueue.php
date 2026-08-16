<?php

namespace WPStow;

class PersistentMediaQueue
{
    private const DB_VERSION = '2';
    private const DB_VERSION_OPTION = 'wpstow_media_queue_db_version';
    private const RUN_HOOK = 'wpstow_run_media_queue';
    private const WATCHDOG_HOOK = 'wpstow_media_queue_watchdog';
    private const LEASE_SECONDS = 1800;
    private const MAX_ATTEMPTS = 3;
    private const QUERY_SIZE = 25;
    private const MAX_ITEMS_PER_RUN = 3;
    private const MAX_RUN_SECONDS = 20;

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpstow_media_jobs';
    }

    public static function addCronSchedule($schedules)
    {
        $schedules['wpstow_minute'] = [
            'interval' => 60,
            'display' => 'WPStow 每分钟',
        ];
        return $schedules;
    }

    public static function maybeInstall()
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
        }
        self::scheduleWatchdog();
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key char(36) NOT NULL,
            category varchar(20) NOT NULL DEFAULT 'all',
            status varchar(20) NOT NULL DEFAULT 'pending',
            max_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cursor_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            total bigint(20) unsigned NOT NULL DEFAULT 0,
            examined bigint(20) unsigned NOT NULL DEFAULT 0,
            processed bigint(20) unsigned NOT NULL DEFAULT 0,
            failed bigint(20) unsigned NOT NULL DEFAULT 0,
            skipped bigint(20) unsigned NOT NULL DEFAULT 0,
            current_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            current_attempt tinyint(3) unsigned NOT NULL DEFAULT 0,
            worker_failures tinyint(3) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NULL DEFAULT NULL,
            last_message text NULL,
            last_item longtext NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            started_at datetime NULL DEFAULT NULL,
            updated_at datetime NOT NULL,
            finished_at datetime NULL DEFAULT NULL,
            lease_token varchar(64) NULL DEFAULT NULL,
            lease_expires_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_key (job_key),
            KEY status_due (status,next_attempt_at),
            KEY updated_at (updated_at)
        ) {$charsetCollate};";
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        self::scheduleWatchdog();
    }

    private static function scheduleWatchdog()
    {
        if (!wp_next_scheduled(self::WATCHDOG_HOOK)) {
            wp_schedule_event(time() + 60, 'wpstow_minute', self::WATCHDOG_HOOK);
        }
    }

    public static function deactivate()
    {
        global $wpdb;
        wp_clear_scheduled_hook(self::WATCHDOG_HOOK);
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook(self::RUN_HOOK);
        }
        $table = self::tableName();
        $wpdb->query("UPDATE {$table} SET status = 'paused', lease_token = NULL, lease_expires_at = NULL WHERE status IN ('pending', 'running')");
    }

    private static function authorize()
    {
        check_ajax_referer('wpstow_admin', 'nonce');
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_send_json_error(['message' => '权限不足'], 403);
        }
    }

    public static function ajaxStart()
    {
        self::authorize();
        $category = MediaLibraryManager::sanitizeCategory(isset($_POST['category']) ? wp_unslash($_POST['category']) : 'all');
        $requestedMaxId = isset($_POST['max_id']) ? max(0, (int) wp_unslash($_POST['max_id'])) : 0;
        $maxId = MediaLibraryManager::getMaxId($category);
        if ($requestedMaxId > 0) {
            $maxId = min($requestedMaxId, $maxId);
        }

        $result = self::createJob($category, $maxId, get_current_user_id());
        if (empty($result['created'])) {
            wp_send_json_error([
                'message' => !empty($result['job']) ? '已有未结束的媒体接管任务，请先完成或取消该任务。' : '无法创建媒体接管任务，请检查数据库状态。',
                'job' => !empty($result['job']) ? self::formatJob($result['job']) : null,
            ], 409);
        }
        wp_send_json_success([
            'message' => '服务器队列已创建，关闭页面后仍会继续。',
            'job' => self::formatJob($result['job']),
        ]);
    }

    public static function ajaxStatus()
    {
        self::authorize();
        $jobId = isset($_POST['job_id']) ? max(0, (int) wp_unslash($_POST['job_id'])) : 0;
        $job = $jobId ? self::getJob($jobId) : self::getLatestJob();
        if ($job && in_array($job['status'], ['pending', 'running'], true)) {
            self::scheduleJob((int) $job['id'], 1);
        }
        wp_send_json_success(['job' => $job ? self::formatJob($job) : null]);
    }

    public static function ajaxControl()
    {
        self::authorize();
        $jobId = isset($_POST['job_id']) ? max(0, (int) wp_unslash($_POST['job_id'])) : 0;
        $command = sanitize_key(wp_unslash((string) ($_POST['command'] ?? '')));
        if (!$jobId || !in_array($command, ['pause', 'resume', 'cancel'], true)) {
            wp_send_json_error(['message' => '队列操作参数无效'], 400);
        }

        $result = self::controlJob($jobId, $command);
        if (empty($result['status'])) {
            wp_send_json_error(['message' => $result['message']], 409);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'job' => self::formatJob($result['job']),
        ]);
    }

    public static function createJob($category, $maxId, $userId)
    {
        global $wpdb;
        $table = self::tableName();
        $lockName = 'wpstow_queue_create_' . get_current_blog_id();
        $hasLock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lockName)) === 1;
        if (!$hasLock) {
            return ['created' => false, 'job' => self::getLatestActiveJob()];
        }

        try {
            $active = self::getLatestActiveJob();
            if ($active) {
                return ['created' => false, 'job' => $active];
            }

            self::cleanupHistory();
            $category = MediaLibraryManager::sanitizeCategory($category);
            $maxId = max(0, (int) $maxId);
            $now = current_time('mysql', true);
            $inserted = $wpdb->insert($table, [
                'job_key' => wp_generate_uuid4(),
                'category' => $category,
                'status' => 'pending',
                'max_attachment_id' => $maxId,
                'total' => MediaLibraryManager::getTotal($category, $maxId),
                'created_by' => max(0, (int) $userId),
                'created_at' => $now,
                'updated_at' => $now,
                'last_message' => '等待服务器处理',
            ]);
            if (!$inserted) {
                return ['created' => false, 'job' => null];
            }
            $job = self::getJob((int) $wpdb->insert_id);
            self::scheduleJob((int) $job['id'], 1);
            return ['created' => true, 'job' => $job];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private static function cleanupHistory()
    {
        global $wpdb;
        $table = self::tableName();
        $cutoff = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS * 30);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN ('completed', 'cancelled') AND updated_at < %s",
            $cutoff
        ));
    }

    private static function getLatestActiveJob()
    {
        global $wpdb;
        $table = self::tableName();
        return $wpdb->get_row("SELECT * FROM {$table} WHERE status IN ('pending', 'running', 'paused') ORDER BY id DESC LIMIT 1", ARRAY_A);
    }

    private static function getLatestJob()
    {
        global $wpdb;
        $table = self::tableName();
        return $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A);
    }

    public static function getJob($jobId)
    {
        global $wpdb;
        $table = self::tableName();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $jobId), ARRAY_A);
    }

    private static function scheduleJob($jobId, $delay = 1)
    {
        $args = [(int) $jobId];
        if (!wp_next_scheduled(self::RUN_HOOK, $args)) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::RUN_HOOK, $args);
        }
    }

    public static function watchdog()
    {
        global $wpdb;
        $table = self::tableName();
        $now = current_time('mysql', true);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = 'pending'
                OR (status = 'running' AND (lease_expires_at IS NULL OR lease_expires_at <= %s))",
            $now
        ));
        foreach ($ids as $jobId) {
            self::scheduleJob((int) $jobId, 1);
        }
    }

    private static function claimJob($jobId)
    {
        global $wpdb;
        $table = self::tableName();
        $token = wp_generate_password(40, false, false);
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'running', lease_token = %s, lease_expires_at = %s,
                 started_at = COALESCE(started_at, %s), updated_at = %s
             WHERE id = %d AND status IN ('pending', 'running')
               AND (lease_token IS NULL OR lease_expires_at IS NULL OR lease_expires_at <= %s)",
            $token,
            $expires,
            $now,
            $now,
            (int) $jobId,
            $now
        ));
        return $updated === 1 ? $token : null;
    }

    public static function runJob($jobId)
    {
        $startedAt = microtime(true);
        for ($iteration = 0; $iteration < self::MAX_ITEMS_PER_RUN; $iteration++) {
            $job = self::getJob((int) $jobId);
            if (!$job || !in_array($job['status'], ['pending', 'running'], true)) {
                return;
            }

            $nextAttempt = !empty($job['next_attempt_at']) ? strtotime($job['next_attempt_at'] . ' UTC') : 0;
            if ($nextAttempt > time()) {
                self::scheduleJob((int) $jobId, max(1, $nextAttempt - time()));
                return;
            }

            $token = self::claimJob((int) $jobId);
            if (!$token) {
                return;
            }

            $job = self::getJob((int) $jobId);
            try {
                self::processClaimedJob($job, $token);
            } catch (\Throwable $e) {
                Utils::writeLog('持久媒体队列异常: job_id=' . (int) $jobId . ', ' . $e->getMessage());
                $workerFailures = (int) $job['worker_failures'] + 1;
                $shouldPause = $workerFailures >= self::MAX_ATTEMPTS;
                self::releaseJob($job, $token, $shouldPause ? 'paused' : 'pending', [
                    'worker_failures' => $workerFailures,
                    'last_message' => $shouldPause ? '队列连续执行异常，已自动暂停，请检查日志后继续' : '队列执行异常，将由看门狗重试',
                    'next_attempt_at' => $shouldPause ? null : gmdate('Y-m-d H:i:s', time() + 60),
                ], $shouldPause ? 0 : 60);
                return;
            }

            if (microtime(true) - $startedAt >= self::MAX_RUN_SECONDS) {
                return;
            }
        }
    }

    private static function processClaimedJob(array $job, $token)
    {
        $jobId = (int) $job['id'];
        $cursor = (int) $job['cursor_attachment_id'];
        $examined = (int) $job['examined'];
        $processed = (int) $job['processed'];
        $failed = (int) $job['failed'];
        $skipped = (int) $job['skipped'];
        $attachmentId = (int) $job['current_attachment_id'];

        if (!$attachmentId) {
            $ids = MediaLibraryManager::getAttachmentIds(
                $job['category'],
                $cursor,
                (int) $job['max_attachment_id'],
                self::QUERY_SIZE
            );
            foreach ($ids as $candidateId) {
                $item = MediaLibraryManager::classify($candidateId);
                if (!empty($item['actionable'])) {
                    $attachmentId = (int) $candidateId;
                    break;
                }
                $cursor = (int) $candidateId;
                $examined++;
                $skipped++;
            }

            if (!$attachmentId) {
                $done = empty($ids) || count($ids) < self::QUERY_SIZE || $cursor >= (int) $job['max_attachment_id'];
                self::releaseJob($job, $token, $done ? 'completed' : 'pending', [
                    'cursor_attachment_id' => $cursor,
                    'examined' => $examined,
                    'processed' => $processed,
                    'failed' => $failed,
                    'skipped' => $skipped,
                    'last_message' => $done ? '媒体接管任务已完成' : '继续查找可处理附件',
                    'finished_at' => $done ? current_time('mysql', true) : null,
                ], $done ? 0 : 1);
                return;
            }
        }

        global $wpdb;
        $wpdb->update(
            self::tableName(),
            ['current_attachment_id' => $attachmentId, 'updated_at' => current_time('mysql', true)],
            ['id' => $jobId, 'lease_token' => $token]
        );
        $result = MediaLibraryManager::processAttachment($attachmentId, false);
        $lastItem = !empty($result['item']) ? wp_json_encode($result['item'], JSON_UNESCAPED_UNICODE) : null;
        if (!empty($result['status'])) {
            self::releaseJob($job, $token, 'pending', [
                'cursor_attachment_id' => $attachmentId,
                'examined' => $examined + 1,
                'processed' => $processed + 1,
                'failed' => $failed,
                'skipped' => $skipped,
                'current_attachment_id' => 0,
                'current_attempt' => 0,
                'next_attempt_at' => null,
                'last_message' => '附件 #' . $attachmentId . ' 接管完成',
                'last_item' => $lastItem,
            ], 1);
            return;
        }

        if (!empty($result['skipped']) && (($result['item']['status'] ?? '') === 'pending')) {
            self::releaseJob($job, $token, 'pending', [
                'current_attachment_id' => $attachmentId,
                'last_message' => '附件 #' . $attachmentId . ' 正被其他任务处理，稍后重试',
                'last_item' => $lastItem,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 60),
            ], 60);
            return;
        }

        if (!empty($result['skipped'])) {
            self::releaseJob($job, $token, 'pending', [
                'cursor_attachment_id' => $attachmentId,
                'examined' => $examined + 1,
                'processed' => $processed,
                'failed' => $failed,
                'skipped' => $skipped + 1,
                'current_attachment_id' => 0,
                'current_attempt' => 0,
                'next_attempt_at' => null,
                'last_message' => '附件 #' . $attachmentId . ' 已跳过：' . ($result['message'] ?? '不可处理'),
                'last_item' => $lastItem,
            ], 1);
            return;
        }

        $attempt = (int) $job['current_attempt'] + 1;
        if ($attempt < self::MAX_ATTEMPTS) {
            $delay = $attempt === 1 ? 60 : 300;
            self::releaseJob($job, $token, 'pending', [
                'current_attachment_id' => $attachmentId,
                'current_attempt' => $attempt,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'last_message' => '附件 #' . $attachmentId . ' 失败，将进行第 ' . ($attempt + 1) . '/' . self::MAX_ATTEMPTS . ' 次尝试',
                'last_item' => $lastItem,
            ], $delay);
            return;
        }

        self::releaseJob($job, $token, 'pending', [
            'cursor_attachment_id' => $attachmentId,
            'examined' => $examined + 1,
            'processed' => $processed,
            'failed' => $failed + 1,
            'skipped' => $skipped,
            'current_attachment_id' => 0,
            'current_attempt' => 0,
            'next_attempt_at' => null,
            'last_message' => '附件 #' . $attachmentId . ' 连续 3 次失败，队列继续处理下一项',
            'last_item' => $lastItem,
        ], 1);
    }

    private static function releaseJob(array $job, $token, $nextStatus, array $fields, $delay)
    {
        global $wpdb;
        $table = self::tableName();
        $allowed = [
            'cursor_attachment_id', 'examined', 'processed', 'failed', 'skipped',
            'current_attachment_id', 'current_attempt', 'worker_failures', 'next_attempt_at',
            'last_message', 'last_item', 'finished_at',
        ];
        if (!array_key_exists('worker_failures', $fields)) {
            $fields['worker_failures'] = 0;
        }
        $set = ['status = IF(status = \'running\', %s, status)'];
        $values = [$nextStatus];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = $key . ' = ' . ($value === null ? 'NULL' : '%s');
            if ($value !== null) {
                $values[] = (string) $value;
            }
        }
        $set[] = 'updated_at = %s';
        $values[] = current_time('mysql', true);
        $set[] = 'lease_token = NULL';
        $set[] = 'lease_expires_at = NULL';
        $values[] = (int) $job['id'];
        $values[] = $token;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET " . implode(', ', $set) . ' WHERE id = %d AND lease_token = %s',
            $values
        ));

        $fresh = self::getJob((int) $job['id']);
        if ($fresh && in_array($fresh['status'], ['completed', 'cancelled', 'paused'], true)) {
            wp_clear_scheduled_hook(self::RUN_HOOK, [(int) $job['id']]);
        } elseif ($fresh && in_array($fresh['status'], ['pending', 'running'], true) && $delay > 0) {
            self::scheduleJob((int) $job['id'], $delay);
        }
    }

    public static function controlJob($jobId, $command)
    {
        global $wpdb;
        $table = self::tableName();
        $job = self::getJob($jobId);
        if (!$job) {
            return ['status' => false, 'message' => '队列任务不存在'];
        }

        $now = current_time('mysql', true);
        if ($command === 'pause' && in_array($job['status'], ['pending', 'running'], true)) {
            $wpdb->update($table, ['status' => 'paused', 'updated_at' => $now, 'last_message' => '任务已暂停'], ['id' => $jobId]);
            wp_clear_scheduled_hook(self::RUN_HOOK, [$jobId]);
            $message = '已暂停；正在上传的附件会先完成。';
        } elseif ($command === 'resume' && $job['status'] === 'paused') {
            $wpdb->update($table, [
                'status' => 'pending',
                'updated_at' => $now,
                'last_message' => '任务已继续',
            ], ['id' => $jobId]);
            self::scheduleJob($jobId, 1);
            $message = '任务已继续。';
        } elseif ($command === 'cancel' && in_array($job['status'], ['pending', 'running', 'paused'], true)) {
            $wpdb->update($table, [
                'status' => 'cancelled',
                'updated_at' => $now,
                'finished_at' => $now,
                'last_message' => '任务已取消',
            ], ['id' => $jobId]);
            wp_clear_scheduled_hook(self::RUN_HOOK, [$jobId]);
            $message = '任务已取消；正在上传的附件会先完成。';
        } else {
            return ['status' => false, 'message' => '当前任务状态不允许该操作'];
        }

        return ['status' => true, 'message' => $message, 'job' => self::getJob($jobId)];
    }

    private static function formatJob(array $job)
    {
        $lastItem = null;
        if (!empty($job['last_item'])) {
            $lastItem = json_decode($job['last_item'], true);
        }
        $labels = [
            'pending' => '等待处理',
            'running' => '正在处理',
            'paused' => '已暂停',
            'completed' => '已完成',
            'cancelled' => '已取消',
        ];
        return [
            'id' => (int) $job['id'],
            'key' => (string) $job['job_key'],
            'category' => (string) $job['category'],
            'status' => (string) $job['status'],
            'status_label' => $labels[$job['status']] ?? $job['status'],
            'max_id' => (int) $job['max_attachment_id'],
            'cursor' => (int) $job['cursor_attachment_id'],
            'total' => (int) $job['total'],
            'examined' => (int) $job['examined'],
            'processed' => (int) $job['processed'],
            'failed' => (int) $job['failed'],
            'skipped' => (int) $job['skipped'],
            'current_attachment_id' => (int) $job['current_attachment_id'],
            'current_attempt' => (int) $job['current_attempt'],
            'max_attempts' => self::MAX_ATTEMPTS,
            'next_attempt_at' => $job['next_attempt_at'],
            'message' => (string) $job['last_message'],
            'last_item' => is_array($lastItem) ? $lastItem : null,
            'created_at' => (string) $job['created_at'],
            'updated_at' => (string) $job['updated_at'],
            'finished_at' => $job['finished_at'],
            'active' => in_array($job['status'], ['pending', 'running', 'paused'], true),
            'can_pause' => in_array($job['status'], ['pending', 'running'], true),
            'can_resume' => $job['status'] === 'paused',
            'can_cancel' => in_array($job['status'], ['pending', 'running', 'paused'], true),
        ];
    }
}
