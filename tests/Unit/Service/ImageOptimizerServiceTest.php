<?php

declare(strict_types=1);

namespace ScImageManager\Tests\Unit\Service;

use ScImageManager\Service\ImageOptimizerService;

/**
 * Unit tests for ImageOptimizerService
 */
class ImageOptimizerServiceTest extends AbstractServiceTestCase
{
    private ImageOptimizerService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/sc_image_optimizer_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->service = new ImageOptimizerService(
            $this->connection,
            $this->prefix,
            $this->tmpDir
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testGetCapabilitiesReturnsExpectedKeys(): void
    {
        $capabilities = $this->service->getCapabilities();

        $this->assertArrayHasKey('imagick', $capabilities);
        $this->assertArrayHasKey('imagick_version', $capabilities);
        $this->assertArrayHasKey('gd', $capabilities);
        $this->assertArrayHasKey('gd_version', $capabilities);
        $this->assertArrayHasKey('webp_support', $capabilities);
    }

    public function testGetCapabilitiesReturnsBoolForImagick(): void
    {
        $capabilities = $this->service->getCapabilities();

        $this->assertIsBool($capabilities['imagick']);
    }

    public function testGetCapabilitiesReturnsBoolForGd(): void
    {
        $capabilities = $this->service->getCapabilities();

        $this->assertIsBool($capabilities['gd']);
    }

    public function testGetCapabilitiesGdMatchesExtensionLoaded(): void
    {
        $capabilities = $this->service->getCapabilities();

        $this->assertSame(extension_loaded('gd'), $capabilities['gd']);
    }

    public function testGetCapabilitiesImagickMatchesExtensionLoaded(): void
    {
        $capabilities = $this->service->getCapabilities();

        $this->assertSame(extension_loaded('imagick'), $capabilities['imagick']);
    }

    public function testOptimizeReturnsFailureForNonExistentFile(): void
    {
        $result = $this->service->optimize('/non/existent/image.jpg');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['original_size']);
        $this->assertSame(0, $result['new_size']);
        $this->assertSame(0, $result['saved_bytes']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testOptimizeReturnsSizeDataForExistingJpeg(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick required for optimization');
        }

        $imgPath = $this->tmpDir . '/test_optimize.jpg';
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $imgPath, 100);
        imagedestroy($img);

        $result = $this->service->optimize($imgPath);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('original_size', $result);
        $this->assertArrayHasKey('new_size', $result);
        $this->assertArrayHasKey('saved_bytes', $result);
        $this->assertGreaterThan(0, $result['original_size']);
    }

    public function testOptimizeSavedBytesIsNonNegative(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick required for optimization');
        }

        $imgPath = $this->tmpDir . '/test_savings.jpg';
        $img = imagecreatetruecolor(50, 50);
        imagejpeg($img, $imgPath, 90);
        imagedestroy($img);

        $result = $this->service->optimize($imgPath);

        $this->assertGreaterThanOrEqual(0, $result['saved_bytes']);
    }

    public function testOptimizeBatchReturnsDoneWhenNoImages(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn('0');

        $result = $this->service->optimizeBatch(0, 20);

        $this->assertTrue($result['done']);
        $this->assertNull($result['next_offset']);
        $this->assertSame(0, $result['optimized']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(100.0, $result['progress']);
    }

    public function testOptimizeBatchReturnsNextOffsetWhenMoreImages(): void
    {
        $this->connection
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(['1', '2']);
        $this->connection->method('fetchOne')->willReturn('10');

        $result = $this->service->optimizeBatch(0, 2);

        $this->assertFalse($result['done']);
        $this->assertSame(2, $result['next_offset']);
        $this->assertSame(10, $result['total']);
    }

    public function testOptimizeBatchMarksDoneWhenLastBatch(): void
    {
        $this->connection
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(['9', '10']);
        $this->connection->method('fetchOne')->willReturn('10');

        $result = $this->service->optimizeBatch(8, 2);

        $this->assertTrue($result['done']);
        $this->assertNull($result['next_offset']);
    }

    public function testOptimizeBatchSkipsImagesWithoutSourceFile(): void
    {
        $this->connection
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(['99999']);
        $this->connection->method('fetchOne')->willReturn('1');

        $result = $this->service->optimizeBatch(0, 1);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['optimized']);
    }

    public function testOptimizeBatchAccumulatesSavedBytes(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick required');
        }

        // Create a test image with a known ID
        $imgDir = $this->tmpDir . '/img/p/4/4';
        mkdir($imgDir, 0755, true);
        $imgPath = $imgDir . '/44.jpg';
        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $imgPath, 100);
        imagedestroy($img);

        $this->connection->method('fetchFirstColumn')->willReturn(['44']);
        $this->connection->method('fetchOne')->willReturn('1');

        $result = $this->service->optimizeBatch(0, 1);

        $this->assertArrayHasKey('total_saved_bytes', $result);
        $this->assertGreaterThanOrEqual(0, $result['total_saved_bytes']);
    }

    public function testOptimizeBatchProgressCalculation(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn(['1', '2', '3']);
        $this->connection->method('fetchOne')->willReturn('10');

        $result = $this->service->optimizeBatch(0, 3);

        // 3 out of 10 = 30% progress
        $this->assertEqualsWithDelta(30.0, $result['progress'], 0.1);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
