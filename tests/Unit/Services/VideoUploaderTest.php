<?php
namespace Tests\Unit\Services;

use App\Services\VideoUploader;
use PHPUnit\Framework\TestCase;

class VideoUploaderTest extends TestCase
{
    private string $destDir;

    protected function setUp(): void
    {
        $this->destDir = sys_get_temp_dir() . '/video_uploader_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->destDir)) {
            array_map('unlink', glob($this->destDir . '/*'));
            rmdir($this->destDir);
        }
    }

    private function fakeMp4Path(): string
    {
        $path  = sys_get_temp_dir() . '/fake_upload_' . uniqid() . '.mp4';
        // Minimal ISO-BMFF 'ftyp' box header — enough for fileinfo to detect video/mp4.
        $bytes = hex2bin('00000018667479706d703432000000006d703432') . str_repeat("\x00", 100);
        file_put_contents($path, $bytes);
        return $path;
    }

    public function test_upload_stores_mp4_and_returns_filename(): void
    {
        $tmp = $this->fakeMp4Path();

        $filename = VideoUploader::upload(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], $this->destDir);

        $this->assertStringEndsWith('.mp4', $filename);
        $this->assertFileExists($this->destDir . '/' . $filename);
        unlink($tmp);
    }

    public function test_upload_rejects_non_mp4_file(): void
    {
        $path = sys_get_temp_dir() . '/fake_upload_' . uniqid() . '.txt';
        file_put_contents($path, 'not a video');

        $this->expectException(\RuntimeException::class);
        try {
            VideoUploader::upload(['tmp_name' => $path, 'error' => UPLOAD_ERR_OK], $this->destDir);
        } finally {
            unlink($path);
        }
    }

    public function test_upload_rejects_upload_error(): void
    {
        $this->expectException(\RuntimeException::class);
        VideoUploader::upload(['tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_INI_SIZE], $this->destDir);
    }
}
