<?php

namespace WPStow;

use WPStow\Plugin;

class Utils extends Plugin
{
    private const LOG_MAX_BYTES = 5242880; // 5 MB
    private const LOG_MAX_BACKUPS = 1;
    private const LOG_TAIL_LINES = 300;
    private const LOG_MAX_ENTRY_BYTES = 20000;

    public static function writeLog($message, $logFile_name = 'app.log', $level = 'info')
    {
        try {
            $level = strtolower((string) $level);
            if (!self::isLogEnabled()) {
                return;
            }
            if ($level === 'debug' && !self::isDebugLogEnabled()) {
                return;
            }

            $logDir = self::prepareLogDir();
            if (!$logDir) {
                return;
            }

            $logFile = $logDir . self::sanitizeLogFileName($logFile_name);
            self::enforceLogRetention($logFile_name);
            $timestamp = self::currentTimestamp();
            $message = self::normalizeLogMessage($message);
            $levelPrefix = $level === 'debug' ? ' [debug]' : '';
            $logMessage = "[$timestamp]$levelPrefix $message" . PHP_EOL;

            $lockHandle = @fopen(self::getLockFilePath($logFile), 'c');
            if ($lockHandle && @flock($lockHandle, LOCK_EX)) {
                $rotated = self::rotateLogIfNeeded($logFile, strlen($logMessage));
                if ($rotated) {
                    self::writeRotationNotice($logFile, $timestamp);
                }

                $result = @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            } else {
                $rotated = self::rotateLogIfNeeded($logFile, strlen($logMessage));
                if ($rotated) {
                    self::writeRotationNotice($logFile, $timestamp);
                }
                $result = @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
                if ($lockHandle) {
                    @fclose($lockHandle);
                }
            }

            if ($result === false) {
                error_log("WPStow: 无法写入日志文件: $logFile");
            }
        } catch (\Throwable $e) {
            error_log("WPStow: 日志记录异常: " . $e->getMessage());
        }
    }

    public static function debugLog($message, $logFile_name = 'app.log')
    {
        self::writeLog($message, $logFile_name, 'debug');
    }

    public static function readLogTail($logFile_name = 'app.log', $lines = null)
    {
        $lines = $lines === null ? self::LOG_TAIL_LINES : (int) $lines;
        $lines = max(1, min(2000, $lines));
        self::enforceLogRetention($logFile_name);
        $logFile = self::getLogDir() . self::sanitizeLogFileName($logFile_name);

        if (!is_file($logFile)) {
            return '暂无日志';
        }

        $content = self::tailFile($logFile, $lines);
        return $content !== '' ? $content : '暂无日志';
    }

    public static function getLogStats($logFile_name = 'app.log')
    {
        self::enforceLogRetention($logFile_name);
        $logFile = self::getLogDir() . self::sanitizeLogFileName($logFile_name);
        clearstatcache(true, $logFile);

        $size = is_file($logFile) ? (int) @filesize($logFile) : 0;
        $mtime = is_file($logFile) ? (int) @filemtime($logFile) : 0;
        $rotatedFiles = [];

        for ($i = 1; $i <= self::LOG_MAX_BACKUPS; $i++) {
            $backup = $logFile . '.' . $i;
            if (is_file($backup)) {
                $rotatedFiles[] = basename($backup) . ' (' . self::formatBytes((int) @filesize($backup)) . ')';
            }
        }

        return [
            'exists' => is_file($logFile),
            'size_bytes' => $size,
            'size_label' => self::formatBytes($size),
            'mtime' => $mtime,
            'mtime_label' => $mtime ? self::formatTimestamp($mtime) : '暂无',
            'tail_lines' => self::LOG_TAIL_LINES,
            'max_size_bytes' => self::LOG_MAX_BYTES,
            'max_size_label' => self::formatBytes(self::LOG_MAX_BYTES),
            'max_backups' => self::LOG_MAX_BACKUPS,
            'rotated_files' => $rotatedFiles,
        ];
    }

    public static function clearLogs($logFile_name = 'app.log')
    {
        $logDir = self::prepareLogDir();
        if (!$logDir) {
            return 0;
        }

        $logFile = $logDir . self::sanitizeLogFileName($logFile_name);
        $deleted = 0;
        $targets = [$logFile, self::getLockFilePath($logFile)];
        $legacyLock = preg_replace('/\.log$/', '.lock', $logFile) ?: '';
        if ($legacyLock && $legacyLock !== self::getLockFilePath($logFile)) {
            $targets[] = $legacyLock;
        }
        for ($i = 1; $i <= max(self::LOG_MAX_BACKUPS, 10); $i++) {
            $targets[] = $logFile . '.' . $i;
        }

        foreach ($targets as $target) {
            if (is_file($target) && @unlink($target)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public static function getMaxLogSize()
    {
        return self::LOG_MAX_BYTES;
    }

    public static function getMaxLogBackups()
    {
        return self::LOG_MAX_BACKUPS;
    }

    public static function getLogTailLines()
    {
        return self::LOG_TAIL_LINES;
    }

    public static function formatBytes($bytes)
    {
        $bytes = max(0, (int) $bytes);
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        if ($unitIndex === 0) {
            return $bytes . ' B';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$unitIndex];
    }

    public static function sanitizeSecret($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        return substr((string) $value, 0, 4096);
    }

    private static function prepareLogDir()
    {
        $logDir = self::getLogDir();

        if (!is_dir($logDir)) {
            $created = function_exists('wp_mkdir_p') ? wp_mkdir_p($logDir) : @mkdir($logDir, 0755, true);
            if (!$created) {
                error_log("WPStow: 无法创建日志目录: $logDir");
                return false;
            }
        }

        if (!is_writable($logDir)) {
            @chmod($logDir, 0755);
        }

        $htaccess = $logDir . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
        }

        $index = $logDir . 'index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }

        return $logDir;
    }

    private static function sanitizeLogFileName($logFile_name)
    {
        $name = basename((string) $logFile_name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            return 'app.log';
        }

        return $name;
    }

    private static function currentTimestamp()
    {
        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s');
        }

        date_default_timezone_set('Asia/Shanghai');
        return date('Y-m-d H:i:s');
    }

    private static function formatTimestamp($timestamp)
    {
        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', (int) $timestamp);
        }

        date_default_timezone_set('Asia/Shanghai');
        return date('Y-m-d H:i:s', (int) $timestamp);
    }

    private static function normalizeLogMessage($message)
    {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $message = (string) $message;
        if (strlen($message) > self::LOG_MAX_ENTRY_BYTES) {
            $message = substr($message, 0, self::LOG_MAX_ENTRY_BYTES) . '... [日志单条过长，已截断]';
        }

        $patterns = [
            '/((?:Authorization|X-API-Key)\s*[:=]\s*)[^\s,;]+/i',
            '/((?:oneimg_token|password|secret(?:_key)?|api[_-]?key|access[_-]?key|token)["\']?\s*[:=]\s*["\']?)[^"\'\s,}&]+/i',
            '/(X-Amz-(?:Credential|Signature)=)[^&\s]+/i',
            '#(https?://[^:/\s]+:)[^@/\s]+@#i',
        ];
        $message = preg_replace($patterns, '$1[REDACTED]', $message);

        return $message;
    }

    private static function getLockFilePath($logFile)
    {
        return $logFile . '.lock';
    }

    public static function enforceLogRetention($logFile_name = 'app.log')
    {
        $logFile = self::getLogDir() . self::sanitizeLogFileName($logFile_name);

        for ($i = self::LOG_MAX_BACKUPS + 1; $i <= 10; $i++) {
            $oldBackup = $logFile . '.' . $i;
            if (is_file($oldBackup)) {
                @unlink($oldBackup);
            }
        }

        $extraLockPatterns = [
            preg_replace('/\.log$/', '.lock', $logFile) ?: '',
        ];
        foreach ($extraLockPatterns as $extraLock) {
            if ($extraLock && $extraLock !== self::getLockFilePath($logFile) && is_file($extraLock)) {
                @unlink($extraLock);
            }
        }
    }

    private static function rotateLogIfNeeded($logFile, $incomingBytes)
    {
        clearstatcache(true, $logFile);
        $currentSize = is_file($logFile) ? (int) @filesize($logFile) : 0;
        if ($currentSize + (int) $incomingBytes <= self::LOG_MAX_BYTES) {
            return false;
        }

        $backup = $logFile . '.1';
        if (is_file($backup)) {
            @unlink($backup);
        }

        if (is_file($logFile)) {
            return @rename($logFile, $logFile . '.1');
        }

        return false;
    }

    private static function writeRotationNotice($logFile, $timestamp)
    {
        $notice = "[$timestamp] 日志超过 " . self::formatBytes(self::LOG_MAX_BYTES) . "，已自动轮转到 " . basename($logFile) . ".1" . PHP_EOL;
        @file_put_contents($logFile, $notice, FILE_APPEND | LOCK_EX);
    }

    private static function tailFile($file, $lines)
    {
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return '';
        }

        $chunkSize = 8192;
        $buffer = '';
        @fseek($handle, 0, SEEK_END);
        $position = @ftell($handle);

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            @fseek($handle, $position);
            $buffer = (string) @fread($handle, $readSize) . $buffer;
        }

        @fclose($handle);

        $buffer = trim($buffer, "\r\n");
        if ($buffer === '') {
            return '';
        }

        $allLines = preg_split('/\r\n|\r|\n/', $buffer);
        if (count($allLines) > $lines) {
            $allLines = array_slice($allLines, -$lines);
        }

        return implode(PHP_EOL, $allLines);
    }

    private static function isLogEnabled()
    {
        if (defined('WPSTOW_LOG_ENABLED')) {
            return (bool) WPSTOW_LOG_ENABLED;
        }

        $setting = self::getLogSettings();
        return is_array($setting) && (($setting['log_enabled'] ?? 'no') === 'yes');
    }

    private static function isDebugLogEnabled()
    {
        if (defined('WPSTOW_DEBUG_LOG') && WPSTOW_DEBUG_LOG) {
            return true;
        }

        $setting = self::getLogSettings();
        return is_array($setting) && (($setting['log_debug'] ?? 'no') === 'yes');
    }

    private static function getLogSettings()
    {
        if (!function_exists('get_option')) {
            return [];
        }

        $setting = get_option('wpstow_setting');
        if ($setting && !is_array($setting)) {
            $setting = @unserialize($setting, ['allowed_classes' => false]);
        }

        return is_array($setting) ? $setting : [];
    }

    public static function curl_get($url, $header = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $output = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);
        return json_decode($output, true);
    }

    public static function curl_post($url, $data, $header = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $output = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);
        return json_decode($output, true);
    }

    public static function curl_delete($url, $header = array(), $data = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $output = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);
        return json_decode($output, true);
    }
}
