<?php
namespace App\Services;

class ImageUploader
{
    private const MAX_WIDTH   = 1600;
    private const THUMB_WIDTH = 400;

    public static function upload(array $file, string $dir): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }

        $mime = mime_content_type($file['tmp_name']);
        $ext  = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => throw new \RuntimeException('Unsupported image type: ' . $mime),
        };

        $filename  = bin2hex(random_bytes(16)) . '.' . $ext;
        $thumbName = 'thumb_' . $filename;

        $destDir = rtrim($dir, '/');
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $src  = self::loadImage($file['tmp_name'], $mime);
        $orig = self::resize($src, self::MAX_WIDTH);
        self::saveImage($orig, $destDir . '/' . $filename, $mime);
        imagedestroy($orig);

        $src   = self::loadImage($file['tmp_name'], $mime);
        $thumb = self::resize($src, self::THUMB_WIDTH);
        self::saveImage($thumb, $destDir . '/' . $thumbName, $mime);
        imagedestroy($thumb);

        return $filename;
    }

    private static function loadImage(string $path, string $mime): \GdImage
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png'  => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            'image/gif'  => imagecreatefromgif($path),
            default      => throw new \RuntimeException('Unsupported type'),
        };
    }

    private static function resize(\GdImage $src, int $maxWidth): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= $maxWidth) {
            $dst = imagecreatetruecolor($w, $h);
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
            imagedestroy($src);
            return $dst;
        }
        $newW = $maxWidth;
        $newH = (int) round($h * $maxWidth / $w);
        $dst  = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);
        return $dst;
    }

    private static function saveImage(\GdImage $img, string $path, string $mime): void
    {
        match ($mime) {
            'image/jpeg' => imagejpeg($img, $path, 85),
            'image/png'  => imagepng($img, $path, 6),
            'image/webp' => imagewebp($img, $path, 85),
            'image/gif'  => imagegif($img, $path),
            default      => throw new \RuntimeException('Unsupported type'),
        };
    }
}
