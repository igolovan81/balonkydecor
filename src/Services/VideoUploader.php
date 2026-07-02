<?php
namespace App\Services;

class VideoUploader
{
    public static function upload(array $file, string $dir): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }

        $mime = mime_content_type($file['tmp_name']);
        if ($mime !== 'video/mp4') {
            throw new \RuntimeException('Unsupported video type: ' . $mime);
        }

        $filename = bin2hex(random_bytes(16)) . '.mp4';
        $destDir  = rtrim($dir, '/');
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        copy($file['tmp_name'], $destDir . '/' . $filename);

        return $filename;
    }
}
