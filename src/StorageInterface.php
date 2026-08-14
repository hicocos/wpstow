<?php

namespace WPStow;

abstract class StorageInterface
{
    abstract public static function upload($filepath, $cloudKey = null);
    abstract public static function delete($key);
    abstract public static function testConnection();
    abstract public static function getCloudUrl($key);
    abstract public static function download($key);

    public static function generateFilename($filepath)
    {
        $ext = pathinfo($filepath, PATHINFO_EXTENSION);
        $name = date('YmdHis') . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        return $name . '.' . $ext;
    }

    public static function getCloudKey($filepath)
    {
        $uploadDir = wp_upload_dir();
        $basedir = $uploadDir['basedir'];

        if (strpos($filepath, $basedir) === 0) {
            return ltrim(substr($filepath, strlen($basedir)), '/');
        }

        return basename($filepath);
    }

    public static function getMimeType($filepath)
    {
        $mimetype = 'application/octet-stream';

        if (extension_loaded('fileinfo') && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $result = finfo_file($finfo, $filepath);
                finfo_close($finfo);
                if ($result) {
                    return $result;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $result = mime_content_type($filepath);
            if ($result) {
                return $result;
            }
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime_types = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/x-icon',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            '7z'   => 'application/x-7z-compressed',
            'tar'  => 'application/x-tar',
            'gz'   => 'application/gzip',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'avi'  => 'video/x-msvideo',
            'mov'  => 'video/quicktime',
            'wmv'  => 'video/x-ms-wmv',
            'flv'  => 'video/x-flv',
            'mkv'  => 'video/x-matroska',
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'xml'  => 'application/xml',
        ];

        if (isset($mime_types[$ext])) {
            return $mime_types[$ext];
        }

        return $mimetype;
    }
}
