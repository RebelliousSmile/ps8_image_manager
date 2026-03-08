<?php
/**
 * SC Image Manager - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScImageManager\Service;

use Doctrine\DBAL\Connection;

/**
 * Service for in-place lossless/near-lossless image optimization.
 *
 * Detects Imagick or GD availability and applies compression without
 * visible quality loss (default quality 85 for JPEG, lossless for PNG).
 */
class ImageOptimizerService
{
    private Connection $connection;
    private string $prefix;
    private string $imgPath;

    public function __construct(
        Connection $connection,
        string $prefix,
        string $projectDir
    ) {
        $this->connection = $connection;
        $this->prefix = $prefix;
        $this->imgPath = rtrim($projectDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'img'
            . DIRECTORY_SEPARATOR . 'p'
            . DIRECTORY_SEPARATOR;
    }

    /**
     * Detect available image processing capabilities.
     *
     * @return array{
     *     imagick: bool,
     *     imagick_version: string|null,
     *     gd: bool,
     *     gd_version: string|null,
     *     webp_support: bool
     * }
     */
    public function getCapabilities(): array
    {
        $imagickAvailable = extension_loaded('imagick');
        $gdAvailable = extension_loaded('gd');

        $imagickVersion = null;
        if ($imagickAvailable) {
            try {
                $imagick = new \Imagick();
                $version = $imagick->getVersion();
                $imagickVersion = $version['versionString'] ?? null;
            } catch (\Exception $e) {
                $imagickVersion = 'unknown';
            }
        }

        $gdVersion = null;
        $webpSupport = false;
        if ($gdAvailable) {
            $gdInfo = gd_info();
            $gdVersion = $gdInfo['GD Version'] ?? 'unknown';
            $webpSupport = !empty($gdInfo['WebP Support']);
        }

        if ($imagickAvailable) {
            $webpSupport = true;
        }

        return [
            'imagick' => $imagickAvailable,
            'imagick_version' => $imagickVersion,
            'gd' => $gdAvailable,
            'gd_version' => $gdVersion,
            'webp_support' => $webpSupport,
        ];
    }

    /**
     * Optimize a single image in place (rewrite with compression).
     *
     * @return array{
     *     success: bool,
     *     original_size: int,
     *     new_size: int,
     *     saved_bytes: int,
     *     error?: string
     * }
     */
    public function optimize(string $imagePath, int $quality = 85): array
    {
        if (!file_exists($imagePath)) {
            return [
                'success' => false,
                'original_size' => 0,
                'new_size' => 0,
                'saved_bytes' => 0,
                'error' => 'File not found: ' . $imagePath,
            ];
        }

        $originalSize = (int) filesize($imagePath);

        if (extension_loaded('imagick')) {
            $result = $this->optimizeWithImagick($imagePath, $quality);
        } else {
            $result = $this->optimizeWithGd($imagePath, $quality);
        }

        if (!$result['success']) {
            return array_merge([
                'original_size' => $originalSize,
                'new_size' => $originalSize,
                'saved_bytes' => 0,
            ], $result);
        }

        $newSize = (int) filesize($imagePath);

        return [
            'success' => true,
            'original_size' => $originalSize,
            'new_size' => $newSize,
            'saved_bytes' => max(0, $originalSize - $newSize),
        ];
    }

    /**
     * Optimize a batch of product images in place.
     *
     * @return array{
     *     optimized: int,
     *     skipped: int,
     *     errors: array<string>,
     *     total_saved_bytes: int,
     *     next_offset: int|null,
     *     done: bool,
     *     total: int,
     *     progress: float
     * }
     */
    public function optimizeBatch(int $offset, int $limit = 20): array
    {
        $imageIds = $this->connection->fetchFirstColumn("
            SELECT DISTINCT i.id_image
            FROM {$this->prefix}image i
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 1
            ORDER BY i.id_image
            LIMIT {$limit} OFFSET {$offset}
        ");

        $totalImages = (int) $this->connection->fetchOne("
            SELECT COUNT(DISTINCT i.id_image)
            FROM {$this->prefix}image i
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 1
        ");

        $results = [
            'optimized' => 0,
            'skipped' => 0,
            'errors' => [],
            'total_saved_bytes' => 0,
            'total' => $totalImages,
        ];

        foreach ($imageIds as $imageId) {
            $basePath = $this->getImagePath((int) $imageId);
            $sourceFile = null;

            foreach (['jpg', 'jpeg', 'png'] as $ext) {
                $candidate = $basePath . '.' . $ext;
                if (file_exists($candidate)) {
                    $sourceFile = $candidate;
                    break;
                }
            }

            if ($sourceFile === null) {
                ++$results['skipped'];
                continue;
            }

            $result = $this->optimize($sourceFile);

            if (!$result['success']) {
                $results['errors'][] = "Image {$imageId}: " . ($result['error'] ?? 'optimization failed');
            } else {
                ++$results['optimized'];
                $results['total_saved_bytes'] += $result['saved_bytes'];
            }
        }

        $processed = $offset + count($imageIds);
        $done = $processed >= $totalImages || count($imageIds) === 0;

        $results['next_offset'] = $done ? null : $offset + $limit;
        $results['done'] = $done;
        $results['progress'] = $totalImages > 0
            ? min(100.0, round($processed / $totalImages * 100, 1))
            : 100.0;

        return $results;
    }

    /**
     * Get image base path from ID (without extension).
     */
    private function getImagePath(int $imageId): string
    {
        $subPath = implode(DIRECTORY_SEPARATOR, str_split((string) $imageId));

        return $this->imgPath . $subPath . DIRECTORY_SEPARATOR . $imageId;
    }

    /**
     * Optimize using Imagick (strips metadata, re-encodes at given quality)
     *
     * @return array{success: bool, error?: string}
     */
    private function optimizeWithImagick(string $imagePath, int $quality): array
    {
        try {
            $imagick = new \Imagick($imagePath);
            $imagick->stripImage();
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($imagePath);
            $imagick->destroy();

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Optimize using GD (re-encode to strip metadata and apply compression)
     *
     * @return array{success: bool, error?: string}
     */
    private function optimizeWithGd(string $imagePath, int $quality): array
    {
        if (!extension_loaded('gd')) {
            return ['success' => false, 'error' => 'GD extension not available'];
        }

        $mimeType = @mime_content_type($imagePath);
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($imagePath),
            'image/png' => @imagecreatefrompng($imagePath),
            'image/gif' => @imagecreatefromgif($imagePath),
            default => false,
        };

        if ($image === false) {
            return ['success' => false, 'error' => 'Cannot read image: ' . $imagePath];
        }

        $success = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $imagePath, $quality),
            'image/png' => imagepng($image, $imagePath, (int) round(($quality - 100) / 11.111111)),
            'image/gif' => imagegif($image, $imagePath),
            default => false,
        };

        imagedestroy($image);

        if (!$success) {
            return ['success' => false, 'error' => 'GD failed to write: ' . $imagePath];
        }

        return ['success' => true];
    }
}
