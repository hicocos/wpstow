<?php

namespace WPStow;

use WPStow\MediaHandler;
use WPStow\Utils;

class ImageProcessor
{
    private static $originalOnlyFiles = [];

    /**
     * Record the upload being processed and disable WordPress' "-scaled" copy.
     */
    public static function disableBigImageScaling($threshold, $imagesize, $file, $attachmentId)
    {
        self::$originalOnlyFiles[wp_normalize_path((string) $file)] = (int) $attachmentId;
        return false;
    }

    /**
     * Disable every registered intermediate size, including theme/plugin sizes.
     */
    public static function disableImageSubsizes($sizes, $imageMeta = [], $attachmentId = 0)
    {
        return [];
    }

    /**
     * Keep HEIC/HEIF and plugin-defined formats unchanged during metadata creation.
     */
    public static function preserveUploadedImageFormat($formats, $filename, $mimeType)
    {
        if (self::isOriginalOnlyFile($filename) && is_array($formats)) {
            unset($formats[(string) $mimeType]);
        }
        return $formats;
    }

    /**
     * Avoid WordPress creating a separate "-rotated" copy for EXIF orientation.
     */
    public static function disableUploadedImageRotation($orientation, $file)
    {
        return self::isOriginalOnlyFile($file) ? 1 : $orientation;
    }

    private static function isOriginalOnlyFile($file)
    {
        return isset(self::$originalOnlyFiles[wp_normalize_path((string) $file)]);
    }

    /**
     * 处理图片（水印后按需转换为 WebP）。
     */
    public static function process($filepath, $attachment_id = null)
    {
        if (!file_exists($filepath)) {
            Utils::writeLog('ImageProcessor: 文件不存在 ' . $filepath);
            return $filepath;
        }

        // 检查是否是图片
        $mime = self::getMimeType($filepath);
        if (!self::isImage($mime)) {
            Utils::writeLog('ImageProcessor: 非图片文件，跳过处理');
            return $filepath;
        }

        // 添加水印
        if (MediaHandler::config('image_watermark') === 'yes') {
            self::addWatermark($filepath);
        }

        if (MediaHandler::config('image_format_conversion') === 'yes') {
            self::convertToWebp($filepath);
        }

        return $filepath;
    }

    /**
     * 将可安全解码的栅格图片转换为 WebP。失败时不触碰原文件。
     */
    private static function convertToWebp($filepath)
    {
        $sourceMime = strtolower((string) self::getMimeType($filepath));
        if ($sourceMime === 'image/webp') {
            return true;
        }

        $convertibleMimes = [
            'image/jpeg',
            'image/png',
            'image/bmp',
            'image/x-ms-bmp',
            'image/tiff',
            'image/avif',
            'image/heic',
            'image/heif',
        ];
        if (!in_array($sourceMime, $convertibleMimes, true)) {
            if (in_array($sourceMime, ['image/gif', 'image/svg+xml'], true)) {
                Utils::writeLog('ImageProcessor: ' . $sourceMime . ' 使用兼容兜底，保留原格式');
            } else {
                Utils::writeLog('ImageProcessor: 当前图片格式不支持安全转换，保留原格式: ' . $sourceMime);
            }
            return false;
        }

        if (!function_exists('wp_get_image_editor')) {
            Utils::writeLog('ImageProcessor: WordPress 图像编辑器不可用，保留原格式');
            return false;
        }

        $editor = wp_get_image_editor($filepath);
        if (is_wp_error($editor)) {
            Utils::writeLog('ImageProcessor: 无法解码图片，保留原格式: ' . $editor->get_error_message());
            return false;
        }

        if (method_exists($editor, 'maybe_exif_rotate')) {
            $rotated = $editor->maybe_exif_rotate();
            if (is_wp_error($rotated)) {
                Utils::writeLog('ImageProcessor: EXIF 方向校正失败，保留原格式: ' . $rotated->get_error_message());
                return false;
            }
        }

        $quality = (int) (MediaHandler::config('image_webp_quality') ?: 82);
        $quality = max(10, min(100, $quality));
        $qualityResult = $editor->set_quality($quality);
        if (is_wp_error($qualityResult)) {
            Utils::writeLog('ImageProcessor: 无法设置 WebP 质量，保留原格式: ' . $qualityResult->get_error_message());
            return false;
        }

        try {
            $suffix = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true);
        } catch (\Throwable $e) {
            $suffix = uniqid('', true);
        }
        $target = trailingslashit(dirname($filepath)) . '.wpstow-webp-' . sanitize_file_name($suffix) . '.webp';
        $saved = $editor->save($target, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path'])) {
            $message = is_wp_error($saved) ? $saved->get_error_message() : '未生成输出文件';
            Utils::writeLog('ImageProcessor: WebP 转换失败，保留原格式: ' . $message);
            @unlink($target);
            return false;
        }

        $output = (string) $saved['path'];
        if (wp_normalize_path($output) !== wp_normalize_path($target)) {
            Utils::writeLog('ImageProcessor: WebP 编辑器返回了非预期输出路径，保留原格式');
            if (wp_normalize_path(dirname($output)) === wp_normalize_path(dirname($target))) {
                @unlink($output);
            }
            @unlink($target);
            return false;
        }
        if (strtolower((string) self::getMimeType($output)) !== 'image/webp') {
            Utils::writeLog('ImageProcessor: WebP 输出校验失败，保留原格式');
            @unlink($output);
            if ($output !== $target) {
                @unlink($target);
            }
            return false;
        }

        unset($editor);
        if (!self::replaceFile($output, $filepath)) {
            Utils::writeLog('ImageProcessor: 无法替换为 WebP 文件，保留原格式');
            @unlink($output);
            if ($output !== $target) {
                @unlink($target);
            }
            return false;
        }

        if ($output !== $target) {
            @unlink($target);
        }
        Utils::writeLog('ImageProcessor: 已转换为 WebP，质量=' . $quality . '，来源=' . $sourceMime);
        return true;
    }

    /**
     * 添加水印
     */
    public static function addWatermark($filepath)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            Utils::writeLog('ImageProcessor: GD 库未安装，无法添加水印');
            return $filepath;
        }

        $watermarkType = MediaHandler::config('watermark_type') ?: 'text';
        $position = MediaHandler::config('watermark_position') ?: 'bottom-right';
        $opacity = intval(MediaHandler::config('watermark_opacity') ?: 50);
        $opacity = max(10, min(100, $opacity));

        $info = getimagesize($filepath);
        if (!$info) {
            return $filepath;
        }

        $width = $info[0];
        $height = $info[1];
        $type = $info[2];

        // 创建图像资源
        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = @imagecreatefromjpeg($filepath);
                break;
            case IMAGETYPE_PNG:
                $image = @imagecreatefrompng($filepath);
                break;
            case IMAGETYPE_GIF:
                // GD 会丢弃 GIF 动画帧，保持原文件不变。
                return $filepath;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($filepath);
                } else {
                    return $filepath;
                }
                break;
            default:
                return $filepath;
        }

        if (!$image) {
            return $filepath;
        }

        imagealphablending($image, true);

        if ($watermarkType === 'text') {
            $text = MediaHandler::config('watermark_text');
            if (empty($text)) {
                Utils::writeLog('ImageProcessor: 水印文字为空');
                imagedestroy($image);
                return $filepath;
            }

            // 字体大小根据图片大小动态调整
            $fontSize = max(12, min($width, $height) / 20);

            // 尝试使用系统中文字体
            $fontPath = self::findFont();
            if (!$fontPath) {
                Utils::writeLog('ImageProcessor: 未找到字体文件，使用默认字体');
                $fontPath = 5; // 内置字体
            }

            // 计算水印文字大小
            if (is_numeric($fontPath)) {
                $textWidth = imagefontwidth($fontPath) * strlen($text);
                $textHeight = imagefontheight($fontPath);
            } else {
                $textBox = imagettfbbox($fontSize, 0, $fontPath, $text);
                $textWidth = abs($textBox[4] - $textBox[0]);
                $textHeight = abs($textBox[5] - $textBox[1]);
            }

            // 计算位置
            $margin = 10;
            $coords = self::calculatePosition($position, $width, $height, $textWidth, $textHeight, $margin);

            // 设置水印颜色
            $color = imagecolorallocatealpha($image, 255, 255, 255, (int) round((100 - $opacity) * 1.27));

            // 添加文字水印
            if (is_numeric($fontPath)) {
                imagestring($image, $fontPath, $coords['x'], $coords['y'], $text, $color);
            } else {
                imagettftext($image, $fontSize, 0, $coords['x'], $coords['y'] + $textHeight, $color, $fontPath, $text);
            }

        } else {
            // 图片水印
            $watermarkImageId = MediaHandler::config('watermark_image');
            if (empty($watermarkImageId)) {
                Utils::writeLog('ImageProcessor: 水印图片未设置');
                imagedestroy($image);
                return $filepath;
            }

            $watermarkPath = get_attached_file($watermarkImageId);
            if (!$watermarkPath || !file_exists($watermarkPath)) {
                Utils::writeLog('ImageProcessor: 水印图片不存在');
                imagedestroy($image);
                return $filepath;
            }

            $watermarkInfo = getimagesize($watermarkPath);
            if (!$watermarkInfo) {
                imagedestroy($image);
                return $filepath;
            }

            $wmWidth = $watermarkInfo[0];
            $wmHeight = $watermarkInfo[1];
            $wmType = $watermarkInfo[2];

            // 创建水印图像资源
            $watermark = false;
            switch ($wmType) {
                case IMAGETYPE_JPEG:
                    $watermark = @imagecreatefromjpeg($watermarkPath);
                    break;
                case IMAGETYPE_PNG:
                    $watermark = @imagecreatefrompng($watermarkPath);
                    break;
                case IMAGETYPE_GIF:
                    $watermark = @imagecreatefromgif($watermarkPath);
                    break;
                case IMAGETYPE_WEBP:
                    if (function_exists('imagecreatefromwebp')) {
                        $watermark = @imagecreatefromwebp($watermarkPath);
                    }
                    break;
                default:
                    imagedestroy($image);
                    return $filepath;
            }

            if (!$watermark) {
                imagedestroy($image);
                return $filepath;
            }

            // 缩放水印（最大不超过图片的 1/4）
            $maxWmWidth = $width / 4;
            $maxWmHeight = $height / 4;
            if ($wmWidth > $maxWmWidth || $wmHeight > $maxWmHeight) {
                $scale = min($maxWmWidth / $wmWidth, $maxWmHeight / $wmHeight);
                $newWmWidth = max(1, (int) round($wmWidth * $scale));
                $newWmHeight = max(1, (int) round($wmHeight * $scale));
                $resizedWatermark = imagecreatetruecolor($newWmWidth, $newWmHeight);
                if (!$resizedWatermark) {
                    imagedestroy($watermark);
                    imagedestroy($image);
                    return $filepath;
                }
                imagealphablending($resizedWatermark, false);
                imagesavealpha($resizedWatermark, true);
                imagecopyresampled($resizedWatermark, $watermark, 0, 0, 0, 0, $newWmWidth, $newWmHeight, $wmWidth, $wmHeight);
                imagedestroy($watermark);
                $watermark = $resizedWatermark;
                $wmWidth = $newWmWidth;
                $wmHeight = $newWmHeight;
            }

            // 计算位置
            $margin = 10;
            $coords = self::calculatePosition($position, $width, $height, $wmWidth, $wmHeight, $margin);

            // 合并水印
            imagecopymerge($image, $watermark, $coords['x'], $coords['y'], 0, 0, $wmWidth, $wmHeight, $opacity);
            imagedestroy($watermark);
        }

        // 先写入同目录临时文件，避免编码失败破坏原图。
        $tempFile = tempnam(dirname($filepath), '.wpstow-image-');
        if ($tempFile === false) {
            imagedestroy($image);
            return $filepath;
        }
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($image, $tempFile, 90);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($image, $tempFile, 8);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($image, $tempFile);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagewebp')) {
                    $result = imagewebp($image, $tempFile, 90);
                }
                break;
        }

        imagedestroy($image);

        if ($result && self::replaceFile($tempFile, $filepath)) {
            Utils::writeLog("ImageProcessor: 水印添加成功，位置={$position}，透明度={$opacity}");
            return $filepath;
        }

        @unlink($tempFile);
        return $filepath;
    }

    private static function replaceFile($source, $target)
    {
        $permissions = @fileperms($target);
        if (!is_file($target)) {
            return @rename($source, $target);
        }

        $backup = $target . '.wpstow-backup-' . str_replace('.', '', uniqid('', true));
        if (!@rename($target, $backup)) {
            return false;
        }
        if (!@rename($source, $target)) {
            @rename($backup, $target);
            return false;
        }
        @unlink($backup);
        if ($permissions !== false) {
            @chmod($target, $permissions & 0777);
        }
        return true;
    }

    /**
     * 计算水印位置
     */
    private static function calculatePosition($position, $imageWidth, $imageHeight, $wmWidth, $wmHeight, $margin)
    {
        $positions = [
            'top-left' => ['x' => $margin, 'y' => $margin],
            'top-center' => ['x' => ($imageWidth - $wmWidth) / 2, 'y' => $margin],
            'top-right' => ['x' => $imageWidth - $wmWidth - $margin, 'y' => $margin],
            'center-left' => ['x' => $margin, 'y' => ($imageHeight - $wmHeight) / 2],
            'center' => ['x' => ($imageWidth - $wmWidth) / 2, 'y' => ($imageHeight - $wmHeight) / 2],
            'center-right' => ['x' => $imageWidth - $wmWidth - $margin, 'y' => ($imageHeight - $wmHeight) / 2],
            'bottom-left' => ['x' => $margin, 'y' => $imageHeight - $wmHeight - $margin],
            'bottom-center' => ['x' => ($imageWidth - $wmWidth) / 2, 'y' => $imageHeight - $wmHeight - $margin],
            'bottom-right' => ['x' => $imageWidth - $wmWidth - $margin, 'y' => $imageHeight - $wmHeight - $margin],
        ];

        return $positions[$position] ?? $positions['bottom-right'];
    }

    /**
     * 查找系统字体
     */
    private static function findFont()
    {
        // Windows 字体路径
        $windowsFonts = [
            'C:/Windows/Fonts/msyh.ttc',      // 微软雅黑
            'C:/Windows/Fonts/simsun.ttc',    // 宋体
            'C:/Windows/Fonts/simhei.ttf',    // 黑体
            'C:/Windows/Fonts/arial.ttf',     // Arial
        ];

        // Linux 字体路径
        $linuxFonts = [
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ];

        $fonts = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? $windowsFonts : $linuxFonts;

        foreach ($fonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return null;
    }

    /**
     * 获取 MIME 类型
     */
    private static function getMimeType($filepath)
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($filepath);
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $filepath);
            finfo_close($finfo);
            return $mime;
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];

        return $types[$ext] ?? 'application/octet-stream';
    }

    /**
     * 检查是否是图片
     */
    private static function isImage($mime)
    {
        return strpos($mime, 'image/') === 0;
    }

    /**
     * 在 WordPress 移动上传文件前处理图片，并同步文件名、MIME 与大小。
     */
    public static function handleUploadPrefilter($file)
    {
        // 只处理图片
        if (!isset($file['type']) || strpos($file['type'], 'image/') !== 0) {
            return $file;
        }

        // 获取临时文件路径
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return $file;
        }

        Utils::writeLog('ImageProcessor: handleUploadPrefilter 处理 ' . $file['name']);

        // 先处理文件内容，再根据真实输出类型更新 WordPress 接收的信息。
        $processedFile = self::process($file['tmp_name']);

        // 如果处理成功，更新文件信息
        if ($processedFile && file_exists($processedFile)) {
            $file['tmp_name'] = $processedFile;
            // 更新文件大小
            if (isset($file['size'])) {
                $file['size'] = filesize($processedFile);
            }
            $actualMime = strtolower((string) self::getMimeType($processedFile));
            if (MediaHandler::config('image_format_conversion') === 'yes' && $actualMime === 'image/webp') {
                $stem = pathinfo((string) $file['name'], PATHINFO_FILENAME);
                $file['name'] = ($stem !== '' ? $stem : 'image') . '.webp';
                $file['type'] = 'image/webp';
            }
        }

        return $file;
    }
}
