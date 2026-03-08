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
 * Service for converting product images to WebP format.
 *
 * Supports Imagick (preferred) and GD (fallback) for WebP generation.
 */
class WebpConverterService
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
     * Get global WebP conversion statistics.
     *
     * Analyses a sample of up to 100 images and extrapolates to the full dataset.
     *
     * @return array{
     *     total_images: int,
     *     total_types: int,
     *     with_webp: int,
     *     without_webp: int,
     *     coverage_percent: float,
     *     estimated_savings_bytes: int,
     *     image_types: array<int, array<string, mixed>>,
     *     is_estimate?: bool,
     *     sample_size?: int
     * }
     */
    public function getStats(): array
    {
        $imageIds = $this->connection->fetchFirstColumn("
            SELECT DISTINCT i.id_image
            FROM {$this->prefix}image i
            INNER JOIN {$this->prefix}product p ON i.id_product = p.id_product
            WHERE p.active = 1
            ORDER BY i.id_image
        ");

        $imageTypes = $this->connection->fetchAllAssociative("
            SELECT name, width, height FROM {$this->prefix}image_type WHERE products = 1
        ");

        $totalImages = count($imageIds);

        $stats = [
            'total_images' => $totalImages,
            'total_types' => count($imageTypes),
            'with_webp' => 0,
            'without_webp' => 0,
            'coverage_percent' => 0.0,
            'estimated_savings_bytes' => 0,
            'image_types' => $imageTypes,
        ];

        if ($totalImages === 0) {
            return $stats;
        }

        $sampleSize = min(100, $totalImages);
        $sampleIds = array_slice($imageIds, 0, $sampleSize);
        $withWebp = 0;
        $estimatedSavings = 0;

        foreach ($sampleIds as $imageId) {
            $imagePath = $this->getImagePath((int) $imageId);
            $hasWebp = false;

            foreach ($imageTypes as $type) {
                $webpThumb = $imagePath . '-' . $type['name'] . '.webp';
                if (file_exists($webpThumb)) {
                    $hasWebp = true;
                    break;
                }
            }

            if ($hasWebp) {
                ++$withWebp;
                $estimatedSavings += $this->estimateSavings((int) $imageId);
            }
        }

        // Extrapolate to full dataset when working with a sample
        if ($sampleSize < $totalImages) {
            $ratio = $totalImages / $sampleSize;
            $withWebp = (int) round($withWebp * $ratio);
            $estimatedSavings = (int) round($estimatedSavings * $ratio);
            $stats['is_estimate'] = true;
            $stats['sample_size'] = $sampleSize;
        }

        $stats['with_webp'] = $withWebp;
        $stats['without_webp'] = $totalImages - $withWebp;
        $stats['coverage_percent'] = round($withWebp / $totalImages * 100, 1);
        $stats['estimated_savings_bytes'] = $estimatedSavings;

        return $stats;
    }

    /**
     * Process a batch of images: convert each to WebP for all product image types.
     *
     * @return array{
     *     converted: int,
     *     skipped: int,
     *     errors: array<string>,
     *     next_offset: int|null,
     *     done: bool,
     *     total: int,
     *     progress: float
     * }
     */
    public function processBatch(int $offset, int $limit = 50): array
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
            'converted' => 0,
            'skipped' => 0,
            'errors' => [],
            'total' => $totalImages,
        ];

        foreach ($imageIds as $imageId) {
            $result = $this->convertImage($this->getImagePath((int) $imageId), $this->getImagePath((int) $imageId) . '.webp');

            if ($result === null) {
                // No source file found
                $results['errors'][] = "Image {$imageId}: no source file found";
            } elseif ($result === false) {
                ++$results['skipped'];
            } else {
                $results['converted'] += $result;
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
     * Convert a single image to WebP for all product image types.
     *
     * Returns the number of thumbnails converted, false if skipped (no source),
     * or null on a hard error.
     *
     * @return int|false|null
     */
    public function convertImage(string $sourcePath, string $targetPath): int|false|null
    {
        // Resolve the actual source file (original image)
        $sourceFile = $this->resolveSourceFile(dirname($sourcePath), basename($sourcePath));
        if ($sourceFile === null) {
            return null;
        }

        $imageTypes = $this->connection->fetchAllAssociative("
            SELECT name, width, height FROM {$this->prefix}image_type WHERE products = 1
        ");

        $converted = 0;
        $basePath = rtrim($sourcePath, '.webp');

        foreach ($imageTypes as $type) {
            $destFile = $basePath . '-' . $type['name'] . '.webp';

            // Skip already converted thumbnails
            if (file_exists($destFile)) {
                continue;
            }

            $success = $this->writeWebp($sourceFile, $destFile);
            if ($success) {
                ++$converted;
            }
        }

        return $converted;
    }

    /**
     * Get the filesystem base path for an image ID (without extension).
     *
     * For image ID 12345, returns: /path/to/img/p/1/2/3/4/5/12345
     */
    public function getImagePath(int $imageId): string
    {
        $subPath = implode(DIRECTORY_SEPARATOR, str_split((string) $imageId));

        return $this->imgPath . $subPath . DIRECTORY_SEPARATOR . $imageId;
    }

    /**
     * Estimate bytes saved for a given image by comparing original size vs WebP.
     *
     * Returns 0 when files cannot be found or compared.
     */
    public function estimateSavings(int $imageId): int
    {
        $basePath = $this->getImagePath($imageId);

        // Find original file
        $originalFile = null;
        foreach (['jpg', 'jpeg', 'png', 'gif'] as $ext) {
            $candidate = $basePath . '.' . $ext;
            if (file_exists($candidate)) {
                $originalFile = $candidate;
                break;
            }
        }

        if ($originalFile === null) {
            return 0;
        }

        $originalSize = filesize($originalFile);
        if ($originalSize === false || $originalSize === 0) {
            return 0;
        }

        // WebP is typically 25-35% smaller than JPEG; use 30% as a conservative estimate
        // when no actual WebP file exists yet
        $webpEstimate = (int) round($originalSize * 0.70);
        $savedEstimate = $originalSize - $webpEstimate;

        // If we already have a real WebP, use actual sizes
        $webpPath = $basePath . '.webp';
        if (file_exists($webpPath)) {
            $webpSize = filesize($webpPath);
            if ($webpSize !== false) {
                return max(0, $originalSize - $webpSize);
            }
        }

        return max(0, $savedEstimate);
    }

    /**
     * Resolve the source file for an image from its base path.
     *
     * Looks for jpg/jpeg/png/gif in that directory. Returns null if nothing found.
     */
    private function resolveSourceFile(string $dirPath, string $baseName): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            $candidate = $dirPath . DIRECTORY_SEPARATOR . $baseName . '.' . $ext;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // Fallback: largest thumbnail
        if (is_dir($dirPath)) {
            $thumbs = glob($dirPath . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,gif}', GLOB_BRACE);
            if (!empty($thumbs)) {
                usort($thumbs, static fn ($a, $b) => filesize($b) - filesize($a));

                return $thumbs[0];
            }
        }

        return null;
    }

    /**
     * Write a WebP file from a source image using Imagick (preferred) or GD (fallback).
     */
    private function writeWebp(string $source, string $destination, int $quality = 85): bool
    {
        if (!file_exists($source)) {
            return false;
        }

        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        if (extension_loaded('imagick')) {
            return $this->writeWebpWithImagick($source, $destination, $quality);
        }

        return $this->writeWebpWithGd($source, $destination, $quality);
    }

    /**
     * Convert using Imagick extension
     */
    private function writeWebpWithImagick(string $source, string $destination, int $quality): bool
    {
        try {
            $imagick = new \Imagick($source);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($destination);
            $imagick->destroy();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Convert using GD extension (fallback)
     */
    private function writeWebpWithGd(string $source, string $destination, int $quality): bool
    {
        if (!function_exists('imagewebp')) {
            return false;
        }

        $mimeType = @mime_content_type($source);
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/gif' => @imagecreatefromgif($source),
            default => false,
        };

        if ($image === false) {
            return false;
        }

        $result = imagewebp($image, $destination, $quality);
        imagedestroy($image);

        return $result;
    }
}
