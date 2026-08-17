<?php

namespace WPStow;

class CloudDeletionQueue
{
    private const DB_VERSION = '1';
    private const DB_VERSION_OPTION = 'wpstow_delete_queue_db_version';
    private const WATCHDOG_HOOK = 'wpstow_cloud_delete_watchdog';
    private const LEASE_SECONDS = 180;
    private const MAX_ITEMS_PER_RUN = 2;
    private const MAX_RUN_SECONDS = 20;
    private const IMMEDIATE_RETRY_DELAY_US = 250000;
    private const SLOW_FAILURE_SECONDS = 5;

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpstow_delete_jobs';
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_key char(64) NOT NULL,
            storage_type varchar(20) NOT NULL,
            object_key text NOT NULL,
            storage_identity longtext NULL,
            context varchar(191) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NOT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            lease_token varchar(64) NULL,
            lease_expires_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY task_key (task_key),
            KEY status_due (status,next_attempt_at),
            KEY lease_expires_at (lease_expires_at)
        ) {$charsetCollate};";
        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        self::scheduleWatchdog();
    }

    public static function maybeInstall()
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
            return;
        }
        self::scheduleWatchdog();
    }

    public static function hasPendingDeletion($storageType, $key, array $storageIdentity = [])
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            return false;
        }

        $storageType = sanitize_key((string) $storageType);
        $key = StorageInterface::normalizeObjectKey($key);
        if ($storageType === '' || $key === false) {
            return false;
        }
        if (!$storageIdentity) {
            $storageIdentity = MediaHandler::getStorageIdentity($storageType);
        }
        $identity = MediaHandler::normalizeStorageIdentity($storageIdentity);

        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . self::tableName() . " WHERE task_key = %s AND status = 'pending' LIMIT 1",
            self::taskKey($storageType, $key, $identity)
        ));
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::WATCHDOG_HOOK);
    }

    public static function deleteObject($storageType, $key, $context = '', array $storageIdentity = [])
    {
        $storageType = sanitize_key((string) $storageType);
        $key = StorageInterface::normalizeObjectKey($key);
        $storageClass = MediaHandler::getStorageClass($storageType);
        if (!$storageClass || $key === false) {
            Utils::writeLog('云端删除参数无效: storage=' . $storageType);
            return false;
        }

        if (!$storageIdentity) {
            $storageIdentity = MediaHandler::getStorageIdentity($storageType);
        }
        $identity = MediaHandler::normalizeStorageIdentity($storageIdentity);
        $currentIdentity = MediaHandler::normalizeStorageIdentity(MediaHandler::getStorageIdentity($storageType));
        if ($identity && $identity !== $currentIdentity) {
            $lastError = '存储配置已变更，已暂停对旧存储目标执行删除';
            self::enqueue($storageType, $key, $identity, $context, 0, $lastError);
            return false;
        }

        $lastError = '删除接口返回失败';
        $attempts = 0;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $attempts = $attempt;
            $result = self::attemptDelete($storageClass, $key);
            if ($result['success']) {
                self::removeQueuedTask($storageType, $key, $identity);
                if ($attempt > 1) {
                    Utils::writeLog('云端删除即时重试成功: storage=' . $storageType . ', key=' . $key . ', attempt=' . $attempt);
                }
                return true;
            }

            $lastError = $result['error'];
            if ($attempt === 1 && $result['duration'] < self::SLOW_FAILURE_SECONDS) {
                usleep(self::IMMEDIATE_RETRY_DELAY_US);
                continue;
            }
            break;
        }

        self::enqueue($storageType, $key, $identity, $context, $attempts, $lastError);
        return false;
    }

    public static function watchdog()
    {
        global $wpdb;
        $startedAt = microtime(true);
        $table = self::tableName();
        $now = current_time('mysql', true);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = 'pending' AND next_attempt_at <= %s
               AND (lease_token IS NULL OR lease_expires_at IS NULL OR lease_expires_at <= %s)
             ORDER BY next_attempt_at ASC, id ASC
             LIMIT %d",
            $now,
            $now,
            self::MAX_ITEMS_PER_RUN
        ));

        foreach ($ids as $id) {
            $token = self::claim((int) $id);
            if (!$token) {
                continue;
            }
            self::processClaimed((int) $id, $token);
            if (microtime(true) - $startedAt >= self::MAX_RUN_SECONDS) {
                break;
            }
        }
    }

    private static function scheduleWatchdog()
    {
        if (!wp_next_scheduled(self::WATCHDOG_HOOK)) {
            wp_schedule_event(time() + 60, 'wpstow_minute', self::WATCHDOG_HOOK);
        }
    }

    private static function attemptDelete($storageClass, $key)
    {
        $startedAt = microtime(true);
        try {
            $success = (bool) $storageClass::delete($key);
            return [
                'success' => $success,
                'error' => $success ? '' : '删除接口返回失败',
                'duration' => microtime(true) - $startedAt,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => get_class($e) . ': ' . $e->getMessage(),
                'duration' => microtime(true) - $startedAt,
            ];
        }
    }

    private static function enqueue($storageType, $key, array $identity, $context, $attempts, $lastError)
    {
        global $wpdb;
        $table = self::tableName();
        $identityJson = wp_json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $taskKey = self::taskKey($storageType, $key, $identity);
        $now = current_time('mysql', true);
        $nextAttempt = gmdate('Y-m-d H:i:s', time() + 60);
        $context = substr(sanitize_text_field((string) $context), 0, 191);
        $lastError = substr(sanitize_text_field((string) $lastError), 0, 2000);

        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                (task_key, storage_type, object_key, storage_identity, context, status, attempts, next_attempt_at, last_error, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, 'pending', %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                status = 'pending',
                attempts = GREATEST(attempts, VALUES(attempts)),
                next_attempt_at = LEAST(next_attempt_at, VALUES(next_attempt_at)),
                last_error = VALUES(last_error),
                context = VALUES(context),
                updated_at = VALUES(updated_at),
                lease_token = NULL,
                lease_expires_at = NULL",
            $taskKey,
            $storageType,
            $key,
            $identityJson,
            $context,
            max(1, (int) $attempts),
            $nextAttempt,
            $lastError,
            $now,
            $now
        );
        $saved = $wpdb->query($sql);
        if ($saved === false) {
            Utils::writeLog('云端删除失败且无法写入重试队列: storage=' . $storageType . ', key=' . $key . ', error=' . $wpdb->last_error);
            return;
        }

        Utils::writeLog('云端删除已加入自动重试: storage=' . $storageType . ', key=' . $key . ', context=' . $context . ', error=' . $lastError);
        self::scheduleWatchdog();
    }

    private static function removeQueuedTask($storageType, $key, array $identity)
    {
        global $wpdb;
        $wpdb->delete(self::tableName(), [
            'task_key' => self::taskKey($storageType, $key, $identity),
        ], ['%s']);
    }

    private static function claim($id)
    {
        global $wpdb;
        $table = self::tableName();
        $token = wp_generate_password(40, false, false);
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET lease_token = %s, lease_expires_at = %s, updated_at = %s
             WHERE id = %d AND status = 'pending' AND next_attempt_at <= %s
               AND (lease_token IS NULL OR lease_expires_at IS NULL OR lease_expires_at <= %s)",
            $token,
            $expires,
            $now,
            $id,
            $now,
            $now
        ));
        return $updated === 1 ? $token : '';
    }

    private static function processClaimed($id, $token)
    {
        global $wpdb;
        $table = self::tableName();
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND lease_token = %s",
            $id,
            $token
        ), ARRAY_A);
        if (!$task) {
            return;
        }

        $storageType = sanitize_key((string) $task['storage_type']);
        $storageClass = MediaHandler::getStorageClass($storageType);
        $taskIdentity = json_decode((string) $task['storage_identity'], true);
        $taskIdentity = MediaHandler::normalizeStorageIdentity(is_array($taskIdentity) ? $taskIdentity : []);
        $currentIdentity = MediaHandler::normalizeStorageIdentity(MediaHandler::getStorageIdentity($storageType));

        if (!$storageClass) {
            $result = ['success' => false, 'error' => '存储驱动不存在'];
        } elseif ($taskIdentity && $taskIdentity !== $currentIdentity) {
            $result = ['success' => false, 'error' => '存储配置已变更，已暂停对旧存储目标执行删除'];
        } else {
            $result = self::attemptDelete($storageClass, (string) $task['object_key']);
        }

        if ($result['success']) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE id = %d AND lease_token = %s",
                $id,
                $token
            ));
            Utils::writeLog('云端删除后台重试成功: storage=' . $storageType . ', key=' . $task['object_key'] . ', attempts=' . ((int) $task['attempts'] + 1));
            return;
        }

        $attempts = (int) $task['attempts'] + 1;
        $delay = self::retryDelay($attempts);
        $nextAttempt = gmdate('Y-m-d H:i:s', time() + $delay);
        $error = substr(sanitize_text_field((string) $result['error']), 0, 2000);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET attempts = %d, next_attempt_at = %s, last_error = %s, updated_at = %s,
                 lease_token = NULL, lease_expires_at = NULL
             WHERE id = %d AND lease_token = %s",
            $attempts,
            $nextAttempt,
            $error,
            current_time('mysql', true),
            $id,
            $token
        ));

        if ($attempts <= 5 || $attempts % 10 === 0) {
            Utils::writeLog('云端删除后台重试失败: storage=' . $storageType . ', key=' . $task['object_key'] . ', attempts=' . $attempts . ', next=' . $nextAttempt . ', error=' . $error);
        }
    }

    private static function retryDelay($attempts)
    {
        $delays = [60, 300, 900, 3600, 21600, 86400];
        $index = min(count($delays) - 1, max(0, (int) $attempts - 2));
        return $delays[$index];
    }

    private static function taskKey($storageType, $key, array $identity)
    {
        return hash('sha256', $storageType . "\0" . $key . "\0" . wp_json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
