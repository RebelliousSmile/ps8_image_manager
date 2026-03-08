<?php
/**
 * SC Image Manager - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScImageManager\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use ScImageManager\Service\ImageOptimizerService;
use ScImageManager\Service\WebpConverterService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ImageManagerController extends FrameworkBundleAdminController
{
    private WebpConverterService $webpConverter;
    private ImageOptimizerService $imageOptimizer;

    public function __construct(
        WebpConverterService $webpConverter,
        ImageOptimizerService $imageOptimizer
    ) {
        $this->webpConverter = $webpConverter;
        $this->imageOptimizer = $imageOptimizer;
    }

    /**
     * Dashboard: WebP stats + capabilities overview
     */
    public function indexAction(): Response
    {
        $stats = $this->webpConverter->getStats();
        $capabilities = $this->imageOptimizer->getCapabilities();

        return $this->render(
            '@Modules/sc_image_manager/views/templates/admin/index.html.twig',
            [
                'stats' => $stats,
                'capabilities' => $capabilities,
                'layoutTitle' => 'SC Image Manager',
            ]
        );
    }

    /**
     * AJAX endpoint: convert a batch of images to WebP
     */
    public function webpBatchAction(Request $request): JsonResponse
    {
        $offset = (int) $request->query->get('offset', 0);
        $limit = (int) $request->query->get('limit', 50);

        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }

        try {
            $result = $this->webpConverter->processBatch($offset, $limit);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(array_merge(['success' => true], $result));
    }

    /**
     * AJAX endpoint: optimize a batch of images in place
     */
    public function optimizeBatchAction(Request $request): JsonResponse
    {
        $offset = (int) $request->query->get('offset', 0);
        $limit = (int) $request->query->get('limit', 20);

        if ($limit < 1 || $limit > 100) {
            $limit = 20;
        }

        try {
            $result = $this->imageOptimizer->optimizeBatch($offset, $limit);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(array_merge(['success' => true], $result));
    }

    /**
     * AJAX endpoint: return up-to-date stats as JSON (for dashboard refresh)
     */
    public function statsAction(): JsonResponse
    {
        try {
            $stats = $this->webpConverter->getStats();
            $capabilities = $this->imageOptimizer->getCapabilities();
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success' => true,
            'stats' => $stats,
            'capabilities' => $capabilities,
        ]);
    }
}
