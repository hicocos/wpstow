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
     * 处理图片（压缩和水印）
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

        $processed = false;
        $currentFile = $filepath;

        // 图片压缩
        if (MediaHandler::config('image_compress') === 'yes') {
            $compressedFile = self::compress($currentFile);
            if ($compressedFile && $compressedFile !== $currentFile) {
                $currentFile = $compressedFile;
                $processed = true;
                Utils::writeLog('ImageProcessor: 压缩完成');
            }
        }

        // 添加水印
        if (MediaHandler::config('image_watermark') === 'yes') {
            $watermarkedFile = self::addWatermark($currentFile);
            if ($watermarkedFile && $watermarkedFile !== $currentFile) {
                // 如果压缩产生了新文件，删除压缩后的临时文件
                if ($processed && $currentFile !== $filepath) {
                    @unlink($currentFile);
                }
                $currentFile = $watermarkedFile;
                $processed = true;
                Utils::writeLog('ImageProcessor: 水印添加完成');
            }
        }

        // 如果处理产生了新文件，替换原文件
        if ($processed && $currentFile !== $filepath) {
            @rename($currentFile, $filepath);
            return $filepath;
        }

        return $filepath;
    }

    /**
     * 压缩图片
     */
    public static function compress($filepath)
    {
        if (!function_exists('imagecreatefromjpeg')) {
            Utils::writeLog('ImageProcessor: GD 库未安装，无法压缩');
            return $filepath;
        }

        $quality = intval(MediaHandler::config('image_compress_quality') ?: 80);
        $quality = max(10, min(100, $quality));

        $mime = self::getMimeType($filepath);
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
                $image = @imagecreatefromgif($filepath);
                break;
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
            Utils::writeLog('ImageProcessor: 无法创建图像资源');
            return $filepath;
        }

        // 保留 PNG/WebP 的透明度
        $newImage = imagecreatetruecolor($width, $height);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $width, $height, $transparent);
        }

        imagecopy($newImage, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        // 保存压缩后的图片
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($newImage, $filepath, $quality);
                break;
            case IMAGETYPE_PNG:
                // PNG 质量范围是 0-9，9 是最小压缩
                $pngQuality = round((100 - $quality) / 100 * 9);
                $result = imagepng($newImage, $filepath, $pngQuality);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($newImage, $filepath);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagewebp')) {
                    $result = imagewebp($newImage, $filepath, $quality);
                }
                break;
        }

        imagedestroy($newImage);

        if ($result) {
            Utils::writeLog("ImageProcessor: 压缩成功，质量={$quality}");
            return $filepath;
        }

        return $filepath;
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
                $image = @imagecreatefromgif($filepath);
                break;
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
            $color = imagecolorallocatealpha($image, 255, 255, 255, round((100 - $opacity) * 1.27));

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
                $newWmWidth = round($wmWidth * $scale);
                $newWmHeight = round($wmHeight * $scale);
                $resizedWatermark = imagecreatetruecolor($newWmWidth, $newWmHeight);
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

        // 保存图片
        $result = false;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($image, $filepath, 90);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($image, $filepath, 8);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($image, $filepath);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagewebp')) {
                    $result = imagewebp($image, $filepath, 90);
                }
                break;
        }

        imagedestroy($image);

        if ($result) {
            Utils::writeLog("ImageProcessor: 水印添加成功，位置={$position}，透明度={$opacity}");
            return $filepath;
        }

        return $filepath;
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
     * 处理上传前的图片（压缩和水印）
     * 这个方法在 wp_handle_upload_prefilter 钩子中被调用
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

        // 处理图片（压缩和水印）
        $processedFile = self::process($file['tmp_name']);

        // 如果处理成功，更新文件信息
        if ($processedFile && file_exists($processedFile)) {
            $file['tmp_name'] = $processedFile;
            // 更新文件大小
            if (isset($file['size'])) {
                $file['size'] = filesize($processedFile);
            }
        }

        return $file;
    }
}
