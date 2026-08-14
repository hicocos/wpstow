<?php

namespace WPStow;

use WPStow\MediaHandler;
use WPStow\Utils;

class VideoProcessor
{
    private static $ffmpegPath = null;
    private static $ffprobePath = null;

    /**
     * 检测 FFmpeg 是否可用
     */
    public static function checkFFmpeg()
    {
        if (self::$ffmpegPath !== null) {
            return true;
        }

        // PHP 8 将 disable_functions 中的函数视为未定义；不可用时安全降级。
        if (!function_exists('exec') || !is_callable('exec')) {
            Utils::writeLog('VideoProcessor: exec 函数不可用，无法检测 FFmpeg');
            return false;
        }

        // 尝试查找 FFmpeg 路径
        $possiblePaths = [
            'ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            'C:/ffmpeg/bin/ffmpeg.exe',
            'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
        ];

        foreach ($possiblePaths as $path) {
            $output = [];
            $returnCode = 0;
            @exec($path . ' -version 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                self::$ffmpegPath = $path;
                // 查找 ffprobe
                $probePath = str_replace('ffmpeg', 'ffprobe', $path);
                @exec($probePath . ' -version 2>&1', $output, $returnCode);
                if ($returnCode === 0) {
                    self::$ffprobePath = $probePath;
                } else {
                    continue;
                }
                Utils::writeLog('VideoProcessor: 检测到 FFmpeg: ' . $path);
                return true;
            }
        }

        return false;
    }

    /**
     * 获取 FFmpeg 路径
     */
    private static function getFFmpegPath()
    {
        if (self::$ffmpegPath === null) {
            self::checkFFmpeg();
        }
        return self::$ffmpegPath;
    }

    /**
     * 获取 FFprobe 路径
     */
    private static function getFFprobePath()
    {
        if (self::$ffprobePath === null) {
            self::checkFFmpeg();
        }
        return self::$ffprobePath;
    }

    /**
     * 处理视频（压缩和水印）
     */
    public static function process($filepath, $attachment_id = null)
    {
        if (!file_exists($filepath)) {
            Utils::writeLog('VideoProcessor: 文件不存在 ' . $filepath);
            return $filepath;
        }

        // 检查是否是视频
        if (!self::isVideo($filepath)) {
            Utils::writeLog('VideoProcessor: 非视频文件，跳过处理');
            return $filepath;
        }

        // 检查 FFmpeg
        if (!self::getFFmpegPath()) {
            Utils::writeLog('VideoProcessor: FFmpeg 不可用');
            return $filepath;
        }

        $processed = false;
        $currentFile = $filepath;

        // 视频压缩
        if (MediaHandler::config('video_compress') === 'yes') {
            $compressedFile = self::compress($currentFile);
            if ($compressedFile && $compressedFile !== $currentFile) {
                $currentFile = $compressedFile;
                $processed = true;
                Utils::writeLog('VideoProcessor: 视频压缩完成');
            }
        }

        // 视频水印
        if (MediaHandler::config('video_watermark') === 'yes') {
            $watermarkedFile = self::addWatermark($currentFile);
            if ($watermarkedFile && $watermarkedFile !== $currentFile) {
                if ($processed && $currentFile !== $filepath) {
                    @unlink($currentFile);
                }
                $currentFile = $watermarkedFile;
                $processed = true;
                Utils::writeLog('VideoProcessor: 视频水印添加完成');
            }
        }

        // 如果处理产生了新文件，替换原文件
        if ($processed && $currentFile !== $filepath) {
            if (!@rename($currentFile, $filepath)) {
                Utils::writeLog('VideoProcessor: 无法用处理后的视频替换原文件，本地原文件保持不变');
                @unlink($currentFile);
                return $filepath;
            }
            return $filepath;
        }

        return $filepath;
    }

    /**
     * 压缩视频
     */
    public static function compress($filepath)
    {
        $ffmpeg = self::getFFmpegPath();
        if (!$ffmpeg) {
            return $filepath;
        }

        // 获取视频信息
        $videoInfo = self::getVideoInfo($filepath);
        if (!$videoInfo) {
            Utils::writeLog('VideoProcessor: 无法获取视频信息');
            return $filepath;
        }

        // 压缩参数
        $quality = MediaHandler::config('video_compress_quality') ?: 'medium';
        $maxRes = MediaHandler::config('video_max_resolution') ?: '1080p';

        // 质量参数映射
        $qualityParams = [
            'low' => ['crf' => 28, 'preset' => 'faster', 'bitrate' => '1M'],
            'medium' => ['crf' => 23, 'preset' => 'medium', 'bitrate' => '2M'],
            'high' => ['crf' => 18, 'preset' => 'slow', 'bitrate' => '5M'],
        ];

        $params = $qualityParams[$quality] ?? $qualityParams['medium'];

        // 分辨率映射
        $resolutions = [
            '480p' => ['width' => 854, 'height' => 480],
            '720p' => ['width' => 1280, 'height' => 720],
            '1080p' => ['width' => 1920, 'height' => 1080],
            '2160p' => ['width' => 3840, 'height' => 2160],
        ];

        // 生成输出文件名
        $pathInfo = pathinfo($filepath);
        $outputFile = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_compressed.mp4';

        // 构建 FFmpeg 命令
        $cmd = escapeshellarg($ffmpeg) . ' -i ' . escapeshellarg($filepath) . ' -y';

        // 视频编码设置
        $cmd .= ' -c:v libx264 -preset ' . $params['preset'] . ' -crf ' . $params['crf'];

        // 如果指定了最大分辨率且不是原始
        if ($maxRes !== 'original' && isset($resolutions[$maxRes])) {
            $maxWidth = $resolutions[$maxRes]['width'];
            $maxHeight = $resolutions[$maxRes]['height'];
            $originalWidth = $videoInfo['width'] ?? 0;
            $originalHeight = $videoInfo['height'] ?? 0;

            // 如果原始分辨率超过最大分辨率，则缩放
            if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
                $cmd .= ' -vf "scale=\'min(' . $maxWidth . ',iw)\':min(' . $maxHeight . ',ih):force_original_aspect_ratio=decrease"';
            }
        }

        // 音频编码
        $cmd .= ' -c:a aac -b:a 128k';

        // 输出文件
        $cmd .= ' ' . escapeshellarg($outputFile);

        Utils::writeLog('VideoProcessor: 开始执行视频压缩');

        $output = [];
        $returnCode = 0;
        @exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputFile)) {
            $originalSize = filesize($filepath);
            $compressedSize = filesize($outputFile);
            $savedPercent = round((1 - $compressedSize / $originalSize) * 100, 1);
            Utils::writeLog("VideoProcessor: 压缩成功，原大小: " . self::formatSize($originalSize) .
                          ", 压缩后: " . self::formatSize($compressedSize) .
                          ", 节省: {$savedPercent}%");
            return $outputFile;
        }

        Utils::writeLog('VideoProcessor: 压缩失败，返回码: ' . $returnCode);
        if (file_exists($outputFile)) {
            @unlink($outputFile);
        }
        return $filepath;
    }

    /**
     * 添加视频水印
     */
    public static function addWatermark($filepath)
    {
        $ffmpeg = self::getFFmpegPath();
        if (!$ffmpeg) {
            return $filepath;
        }

        // 获取水印图片
        $watermarkImageId = MediaHandler::config('watermark_image');
        if (empty($watermarkImageId)) {
            Utils::writeLog('VideoProcessor: 未设置水印图片');
            return $filepath;
        }

        $watermarkPath = get_attached_file($watermarkImageId);
        if (!$watermarkPath || !file_exists($watermarkPath)) {
            Utils::writeLog('VideoProcessor: 水印图片不存在');
            return $filepath;
        }

        // 获取视频信息
        $videoInfo = self::getVideoInfo($filepath);
        if (!$videoInfo) {
            return $filepath;
        }

        // 水印位置
        $position = MediaHandler::config('watermark_position') ?: 'bottom-right';
        $opacity = intval(MediaHandler::config('watermark_opacity') ?: 50) / 100;

        // 计算水印大小（视频宽度的 1/6）
        $watermarkWidth = round($videoInfo['width'] / 6);
        $watermarkHeight = -1; // 保持比例

        // 位置映射
        $overlayPositions = [
            'top-left' => '10:10',
            'top-center' => '(w-overlay_w)/2:10',
            'top-right' => 'main_w-overlay_w-10:10',
            'center-left' => '10:(h-overlay_h)/2',
            'center' => '(w-overlay_w)/2:(h-overlay_h)/2',
            'center-right' => 'main_w-overlay_w-10:(h-overlay_h)/2',
            'bottom-left' => '10:main_h-overlay_h-10',
            'bottom-center' => '(w-overlay_w)/2:main_h-overlay_h-10',
            'bottom-right' => 'main_w-overlay_w-10:main_h-overlay_h-10',
        ];

        $overlay = $overlayPositions[$position] ?? $overlayPositions['bottom-right'];

        // 生成输出文件名
        $pathInfo = pathinfo($filepath);
        $outputFile = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_watermarked.mp4';

        // 构建 FFmpeg 命令
        $cmd = escapeshellarg($ffmpeg) . ' -i ' . escapeshellarg($filepath) . ' -i ' . escapeshellarg($watermarkPath) . ' -y';
        $cmd .= ' -filter_complex "[1:v]scale=' . $watermarkWidth . ':' . $watermarkHeight . ',format=rgba,colorchannelmixer=aa=' . $opacity . ' [watermark];';
        $cmd .= '[0:v][watermark]overlay=' . $overlay . '"';
        $cmd .= ' -c:v libx264 -preset medium -crf 23';
        $cmd .= ' -c:a copy';
        $cmd .= ' ' . escapeshellarg($outputFile);

        Utils::writeLog('VideoProcessor: 开始执行视频水印');

        $output = [];
        $returnCode = 0;
        @exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputFile)) {
            Utils::writeLog('VideoProcessor: 水印添加成功');
            return $outputFile;
        }

        Utils::writeLog('VideoProcessor: 水印添加失败，返回码: ' . $returnCode);
        if (file_exists($outputFile)) {
            @unlink($outputFile);
        }
        return $filepath;
    }

    /**
     * 获取视频信息
     */
    private static function getVideoInfo($filepath)
    {
        $ffprobe = self::getFFprobePath();
        if (!$ffprobe) {
            return null;
        }

        $cmd = escapeshellarg($ffprobe) . ' -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($filepath) . ' 2>&1';
        $lines = [];
        $returnCode = 1;
        @exec($cmd, $lines, $returnCode);
        $output = implode("\n", $lines);

        if ($returnCode !== 0 || !$output) {
            return null;
        }

        $info = json_decode($output, true);
        if (!$info) {
            return null;
        }

        $result = [
            'duration' => $info['format']['duration'] ?? 0,
            'size' => $info['format']['size'] ?? 0,
            'width' => 0,
            'height' => 0,
        ];

        // 查找视频流
        foreach ($info['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video') {
                $result['width'] = $stream['width'] ?? 0;
                $result['height'] = $stream['height'] ?? 0;
                $result['codec'] = $stream['codec_name'] ?? '';
                break;
            }
        }

        return $result;
    }

    /**
     * 检查是否是视频文件
     */
    private static function isVideo($filepath)
    {
        $mime = self::getMimeType($filepath);
        return strpos($mime, 'video/') === 0;
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
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            'm4v' => 'video/mp4',
            '3gp' => 'video/3gpp',
            'mpeg' => 'video/mpeg',
            'mpg' => 'video/mpeg',
        ];

        return $types[$ext] ?? 'application/octet-stream';
    }

    /**
     * 格式化文件大小
     */
    private static function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 处理上传前的视频（压缩和水印）
     */
    public static function handleUploadPrefilter($file)
    {
        // 只处理视频
        if (!isset($file['type']) || strpos($file['type'], 'video/') !== 0) {
            return $file;
        }

        // 获取临时文件路径
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return $file;
        }

        // 检查是否启用视频处理
        if (MediaHandler::config('video_compress') !== 'yes' && MediaHandler::config('video_watermark') !== 'yes') {
            return $file;
        }

        Utils::writeLog('VideoProcessor: handleUploadPrefilter 处理 ' . $file['name']);

        // 处理视频（压缩和水印）
        $processedFile = self::process($file['tmp_name']);

        if ($processedFile && file_exists($processedFile)) {
            $file['tmp_name'] = $processedFile;
            if (isset($file['size'])) {
                $file['size'] = filesize($processedFile);
            }
        }

        return $file;
    }
}
