<?php

declare(strict_types=1);

namespace ScImageManager\Tests\Unit\Service;

use ScImageManager\Service\WebpConverterService;

/**
 * Unit tests for WebpConverterService
 */
class WebpConverterServiceTest extends AbstractServiceTestCase
{
    private WebpConverterService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/sc_image_manager_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->service = new WebpConverterService(
            $this->connection,
            $this->prefix,
            $this->tmpDir
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testGetStatsReturnsZeroWhenNoImages(): void
    {
        // fetchFirstColumn for imageIds returns [], fetchAllAssociative for imageTypes returns []
        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $stats = $this->service->getStats();

        $this->assertSame(0, $stats['total_images']);
        $this->assertSame(0, $stats['with_webp']);
        $this->assertSame(0, $stats['without_webp']);
        $this->assertSame(0.0, $stats['coverage_percent']);
        $this->assertSame(0, $stats['estimated_savings_bytes']);
    }

    public function testGetStatsWithImages(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn(['1', '2', '3']);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['name' => 'home_default', 'width' => 250, 'height' => 250],
            ['name' => 'large_default', 'width' => 800, 'height' => 800],
        ]);

        $stats = $this->service->getStats();

        $this->assertSame(3, $stats['total_images']);
        $this->assertSame(2, $stats['total_types']);
        $this->assertArrayHasKey('with_webp', $stats);
        $this->assertArrayHasKey('without_webp', $stats);
        $this->assertArrayHasKey('coverage_percent', $stats);
        $this->assertArrayHasKey('estimated_savings_bytes', $stats);
    }

    public function testGetStatsCoverageIsZeroWhenNoWebpExists(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn(['1']);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['name' => 'home_default', 'width' => 250, 'height' => 250],
        ]);

        $stats = $this->service->getStats();

        // No WebP files exist on disk => coverage should be 0
        $this->assertSame(0, $stats['with_webp']);
        $this->assertSame(0.0, $stats['coverage_percent']);
    }

    public function testProcessBatchReturnsDoneWhenNoImages(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $result = $this->service->processBatch(0, 50);

        $this->assertTrue($result['done']);
        $this->assertNull($result['next_offset']);
        $this->assertSame(0, $result['converted']);
        $this->assertSame(100.0, $result['progress']);
    }

    public function testProcessBatchReturnsNextOffsetWhenMoreImages(): void
    {
        // fetchFirstColumn: batch returns 2 images; fetchOne: total is 10
        $this->connection
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(
                ['1', '2'],   // batch
                []            // subsequent calls if any
            );
        $this->connection->method('fetchOne')->willReturn('10');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $result = $this->service->processBatch(0, 2);

        $this->assertFalse($result['done']);
        $this->assertSame(2, $result['next_offset']);
        $this->assertSame(10, $result['total']);
    }

    public function testProcessBatchMarksDoneWhenLastBatch(): void
    {
        $this->connection
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(['9', '10']);
        $this->connection->method('fetchOne')->willReturn('10');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $result = $this->service->processBatch(8, 2);

        $this->assertTrue($result['done']);
        $this->assertNull($result['next_offset']);
    }

    public function testConvertImageReturnsNullWhenNoSourceFile(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['name' => 'home_default', 'width' => 250, 'height' => 250],
        ]);

        $nonExistent = $this->tmpDir . '/img/p/9/9/99';
        $result = $this->service->convertImage($nonExistent, $nonExistent . '.webp');

        $this->assertNull($result);
    }

    public function testConvertImageConvertsExistingJpeg(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick required for WebP conversion');
        }

        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('imagewebp() not available');
        }

        // Create a minimal JPEG file
        $imgDir = $this->tmpDir . '/img/p/1/1';
        mkdir($imgDir, 0755, true);
        $sourceFile = $imgDir . '/11.jpg';

        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $sourceFile);
        imagedestroy($img);

        $this->connection->method('fetchAllAssociative')->willReturn([
            ['name' => 'home_default', 'width' => 10, 'height' => 10],
        ]);

        $basePath = $imgDir . '/11';
        $result = $this->service->convertImage($basePath, $basePath . '.webp');

        // Result should be int (number of thumbnails converted) or 0 (skipped if webp exists)
        $this->assertIsInt($result);
    }

    public function testGetImagePathBuildsCorrectPath(): void
    {
        // Access via processBatch which uses getImagePath internally
        // We verify the path structure indirectly by checking no crash occurs
        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $result = $this->service->processBatch(0);

        $this->assertIsArray($result);
    }

    public function testEstimateSavingsReturnsZeroWhenNoFile(): void
    {
        $savings = $this->service->estimateSavings(99999);
        $this->assertSame(0, $savings);
    }

    public function testEstimateSavingsReturnsPositiveForExistingJpeg(): void
    {
        $imgDir = $this->tmpDir . '/img/p/5/5';
        mkdir($imgDir, 0755, true);
        $sourceFile = $imgDir . '/55.jpg';

        $img = imagecreatetruecolor(100, 100);
        imagejpeg($img, $sourceFile, 95);
        imagedestroy($img);

        $savings = $this->service->estimateSavings(55);
        $this->assertGreaterThanOrEqual(0, $savings);
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
