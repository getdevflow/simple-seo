<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\RedirectRepository;
use Plugin\SimpleSeo\Support\AttributeStore;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Qubus\Exception\Exception;
use ReflectionException;

final readonly class RedirectSyncService
{
    public function __construct(private RedirectRepository $redirects)
    {
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws Exception
     */
    public function sync(string $entityType, int|string|null $entityId = null): void
    {
        if ($entityId === null || (string) $entityId === '') {
            return;
        }

        $entityId = (string) $entityId;

        $seo = AttributeStore::getSeo($entityType, $entityId);

        if (!is_array($seo)) {
            $seo = [];
        }

        $enabled = !empty($seo['redirect_enabled']);
        $from = trim((string) ($seo['redirect_from'] ?? ''));
        $to = trim((string) ($seo['redirect_to'] ?? ''));
        $status = (int) ($seo['redirect_status'] ?? 301);

        if (!$enabled || $from === '' || $to === '') {
            $this->redirects->disableByEntity($entityType, $entityId);
            return;
        }

        if (!in_array($status, [301, 302, 307, 308], true)) {
            $status = 301;
        }

        $this->redirects->createOrUpdateForEntity(
            entityType: $entityType,
            entityId: $entityId,
            sourceUrl: $from,
            targetUrl: $to,
            statusCode: $status,
            enabled: true
        );
    }

    /**
     * @param int|string|null $contentId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function syncContent(int|string|null $contentId = null): void
    {
        $this->sync('content', $contentId);
    }

    /**
     * @param int|string|null $productId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function syncProduct(int|string|null $productId = null): void
    {
        $this->sync('product', $productId);
    }

    /**
     * @param int|string|null $pageId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function syncPage(int|string|null $pageId = null): void
    {
        $this->sync('page', $pageId);
    }

    /**
     * @param object $redirect
     * @return void
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws \Qubus\Exception\Data\TypeException
     */
    public function syncEntityFromRedirect(object $redirect): void
    {
        $entityType = (string) ($redirect->entity_type ?? '');
        $entityId = (string) ($redirect->entity_id ?? '');

        if ($entityType === '' || $entityId === '') {
            return;
        }

        $seo = AttributeStore::getSeo($entityType, $entityId);

        if (!is_array($seo)) {
            $seo = [];
        }

        $seo['redirect_enabled'] = (int) $redirect->enabled === 1;
        $seo['redirect_from'] = (string) $redirect->source_url;
        $seo['redirect_to'] = (string) $redirect->target_url;
        $seo['redirect_status'] = (string) $redirect->status_code;

        AttributeStore::saveSeo($entityType, $entityId, $seo);
    }
}
