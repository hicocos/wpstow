<?php

namespace WPStow;

class FileNaming
{
    public const DEFAULT_PRESET = 'original';
    public const DEFAULT_TEMPLATE = '{random:8}';

    private const PRESET_TEMPLATES = [
        'short' => '{random:8}',
        'date_random' => '{year}{month}{day}-{random:8}',
        'original_random' => '{name}-{random:8}',
        'timestamp_random' => '{timestamp}-{random:8}',
    ];

    private const TOKEN_PATTERN = '/\{(?:name|year|month|day|hour|minute|second|timestamp|random(?::\d{1,2})?)\}/';
    private const RANDOM_PATTERN = '/\{random(?::(\d{1,2}))?\}/';
    private const RANDOM_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function getPresets()
    {
        return [
            'original' => '文件原名',
            'short' => '极短随机名',
            'date_random' => '日期 + 随机码',
            'original_random' => '原文件名 + 随机码',
            'timestamp_random' => '时间戳 + 随机码',
            'custom' => '自定义模板',
        ];
    }

    public static function getPresetTemplates()
    {
        return self::PRESET_TEMPLATES;
    }

    public static function validateTemplate($template)
    {
        $template = trim((string) $template);
        if ($template === '') {
            return '文件名模板不能为空。';
        }
        if (strlen($template) > 120) {
            return '文件名模板不能超过 120 个字符。';
        }
        if (strpos($template, '/') !== false || strpos($template, '\\') !== false || strpos($template, '..') !== false) {
            return '模板只能生成文件名，不能包含目录符号或连续的点。';
        }
        if (preg_match('/\.[A-Za-z0-9]{1,10}$/', $template)) {
            return '模板末尾不要填写扩展名，插件会自动保留原文件扩展名。';
        }

        $literal = preg_replace(self::TOKEN_PATTERN, '', $template);
        if (strpos((string) $literal, '{') !== false || strpos((string) $literal, '}') !== false) {
            return '模板中包含未知字段或未闭合的大括号。';
        }
        if ($literal !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $literal)) {
            return '模板固定文字只允许字母、数字、点、短横线和下划线。';
        }
        if (!preg_match_all(self::RANDOM_PATTERN, $template, $randomMatches, PREG_SET_ORDER)) {
            return '模板必须包含 {random:N}，避免重名覆盖；N 可设为 8–32。';
        }
        foreach ($randomMatches as $match) {
            $length = isset($match[1]) && $match[1] !== '' ? (int) $match[1] : 8;
            if ($length < 8 || $length > 32) {
                return '随机码长度必须在 8–32 之间。';
            }
        }

        return '';
    }

    public static function getActiveTemplate($preset = null, $customTemplate = null)
    {
        $preset = $preset === null ? (string) MediaHandler::config('filename_preset') : (string) $preset;
        if ($preset === 'custom') {
            $template = $customTemplate === null ? (string) MediaHandler::config('filename_template') : (string) $customTemplate;
            return self::validateTemplate($template) === '' ? trim($template) : self::DEFAULT_TEMPLATE;
        }

        return self::PRESET_TEMPLATES[$preset] ?? self::DEFAULT_TEMPLATE;
    }

    public static function generateFilename($originalFilename, $preset = null, $customTemplate = null, $timestamp = null)
    {
        $originalFilename = self::normalizeOriginalFilename($originalFilename);
        $preset = $preset === null ? (string) MediaHandler::config('filename_preset') : (string) $preset;
        if ($preset === 'original') {
            return $originalFilename;
        }

        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $name = (string) pathinfo($originalFilename, PATHINFO_FILENAME);
        $name = trim($name, ".-_ \t\n\r\0\x0B");
        if ($name === '') {
            $name = 'file';
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 80);
        } else {
            $name = substr($name, 0, 80);
        }

        $timestamp = $timestamp === null ? time() : (int) $timestamp;
        $date = static function ($format) use ($timestamp) {
            return function_exists('wp_date') ? wp_date($format, $timestamp) : date($format, $timestamp);
        };
        $replacements = [
            '{name}' => $name,
            '{year}' => $date('Y'),
            '{month}' => $date('m'),
            '{day}' => $date('d'),
            '{hour}' => $date('H'),
            '{minute}' => $date('i'),
            '{second}' => $date('s'),
            '{timestamp}' => (string) $timestamp,
        ];

        $stem = strtr(self::getActiveTemplate($preset, $customTemplate), $replacements);
        $stem = preg_replace_callback(self::RANDOM_PATTERN, static function ($match) {
            $length = isset($match[1]) && $match[1] !== '' ? (int) $match[1] : 8;
            return self::randomString($length);
        }, $stem);
        $stem = sanitize_file_name(trim((string) $stem, ".-_ \t\n\r\0\x0B"));
        if ($stem === '') {
            $stem = self::randomString(8);
        }

        return $extension !== '' ? $stem . '.' . $extension : $stem;
    }

    public static function isOriginalPreset($preset = null)
    {
        $preset = $preset === null ? (string) MediaHandler::config('filename_preset') : (string) $preset;
        return $preset === 'original';
    }

    /**
     * Resolve duplicate names within the caller's directory scope.
     */
    public static function makeUniqueFilename($filename, callable $isUsed)
    {
        $filename = self::normalizeOriginalFilename($filename);
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($filename, PATHINFO_FILENAME);
        $baseStem = $stem;
        $nextNumber = 1;

        if (preg_match('/^(.*)（([1-9][0-9]*)）$/u', $stem, $matches) && trim($matches[1]) !== '') {
            $baseStem = $matches[1];
            $nextNumber = (int) $matches[2] + 1;
        }

        $candidate = $filename;
        for ($attempt = 0; $attempt < 10000; $attempt++) {
            if (!$isUsed($candidate)) {
                return $candidate;
            }
            $candidate = self::buildNumberedFilename($baseStem, $extension, $nextNumber);
            $nextNumber++;
        }

        throw new \RuntimeException('同目录重名文件过多，无法生成可用文件名');
    }

    public static function attachmentPathExists($relativePath, $storageType = '')
    {
        $relativePath = StorageInterface::normalizeObjectKey($relativePath);
        if ($relativePath === false) {
            return true;
        }

        global $wpdb;
        $attachmentExists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_wp_attached_file', '_wpstow_cloud_key') AND meta_value = %s
             LIMIT 1",
            $relativePath
        ));
        if ($attachmentExists) {
            return true;
        }

        return $storageType !== ''
            && CloudDeletionQueue::hasPendingDeletion($storageType, $relativePath);
    }

    public static function makeUniqueUploadFilename($filename, array $uploadDir = null, $storageType = '')
    {
        $uploadDir = $uploadDir ?: wp_upload_dir();
        if (!empty($uploadDir['error']) || empty($uploadDir['path'])) {
            return self::normalizeOriginalFilename($filename);
        }

        $subdir = trim((string) ($uploadDir['subdir'] ?? ''), '/');
        return self::makeUniqueFilename($filename, static function ($candidate) use ($uploadDir, $subdir, $storageType) {
            $localCandidate = wp_unique_filename((string) $uploadDir['path'], $candidate);
            if ($localCandidate !== $candidate) {
                return true;
            }
            $relativePath = $subdir === '' ? $candidate : $subdir . '/' . $candidate;
            return self::attachmentPathExists($relativePath, $storageType);
        });
    }

    public static function filterUploadFilename($file)
    {
        if (!is_array($file) || empty($file['name']) || !empty($file['error'])) {
            return $file;
        }

        $mimeType = sanitize_mime_type((string) ($file['type'] ?? ''));
        if ($mimeType === '') {
            $checked = wp_check_filetype((string) $file['name'], get_allowed_mime_types());
            $mimeType = sanitize_mime_type((string) $checked['type']);
        }
        $storageType = MediaHandler::getStorageTypeForCategory(MediaHandler::getMediaCategory($mimeType));
        if (self::isOriginalPreset()) {
            $file['name'] = self::makeUniqueUploadFilename((string) $file['name'], null, $storageType);
            return $file;
        }

        if ($storageType === 'local') {
            return $file;
        }

        $file['name'] = self::generateFilename((string) $file['name']);
        return $file;
    }

    private static function normalizeOriginalFilename($filename)
    {
        $filename = sanitize_file_name(wp_basename((string) $filename));
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($filename, PATHINFO_FILENAME);
        $stem = trim($stem, ".-_ \t\n\r\0\x0B");
        if ($stem === '') {
            $stem = 'file';
        }

        $maxStemBytes = max(1, 240 - ($extension !== '' ? strlen($extension) + 1 : 0));
        $stem = self::truncateUtf8($stem, $maxStemBytes);
        return $extension !== '' ? $stem . '.' . $extension : $stem;
    }

    private static function buildNumberedFilename($stem, $extension, $number)
    {
        $suffix = '（' . max(1, (int) $number) . '）';
        $maxStemBytes = max(1, 240 - strlen($suffix) - ($extension !== '' ? strlen($extension) + 1 : 0));
        $stem = rtrim(self::truncateUtf8((string) $stem, $maxStemBytes), ".-_ \t\n\r\0\x0B");
        if ($stem === '') {
            $stem = 'file';
        }
        return $stem . $suffix . ($extension !== '' ? '.' . $extension : '');
    }

    private static function truncateUtf8($value, $maxBytes)
    {
        $value = (string) $value;
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }
        $truncated = substr($value, 0, $maxBytes);
        while ($truncated !== '' && preg_match('//u', $truncated) !== 1) {
            $truncated = substr($truncated, 0, -1);
        }
        return $truncated;
    }

    private static function randomString($length)
    {
        $length = min(32, max(8, (int) $length));
        $alphabetLength = strlen(self::RANDOM_ALPHABET);
        $result = '';
        for ($index = 0; $index < $length; $index++) {
            try {
                $position = random_int(0, $alphabetLength - 1);
            } catch (\Throwable $e) {
                $position = mt_rand(0, $alphabetLength - 1);
            }
            $result .= self::RANDOM_ALPHABET[$position];
        }
        return $result;
    }
}
