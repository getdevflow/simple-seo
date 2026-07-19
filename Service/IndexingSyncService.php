<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use App\Domain\Content\Model\Content;
use App\Domain\Page\Model\Page;
use App\Domain\Product\Model\Product;
use Plugin\SimpleSeo\Support\AttributeStore;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function App\Shared\Helpers\get_content_by;
use function App\Shared\Helpers\get_page_by;
use function App\Shared\Helpers\get_product_by;
use function App\Shared\Helpers\site_url;
use function Codefy\Framework\Helpers\logger;
use function Qubus\Security\Helpers\esc_html__;
use function rtrim;
use function trim;

final readonly class IndexingSyncService
{
    public function __construct(
        private IndexNowService $indexNow,
        private GoogleIndexingService $googleIndexing
    ) {
    }

    /**
     * @param string $entityType
     * @param int|string|null $entityId
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function sync(string $entityType, int|string|null $entityId): void
    {
        if ($entityId === null || (string) $entityId === '') {
            return;
        }

        $url = $this->resolveUrl($entityType, $entityId);

        if ($url === '') {
            return;
        }

        $seo = AttributeStore::getSeo($entityType, (string) $entityId);

        $robotsIndex = $seo['robots_index'] ?? true;

        if ($robotsIndex === '0' || $robotsIndex === false) {
            return;
        }

        if (SimpleSeoSettings::get('enable_indexnow', false)) {
            try {
                $this->indexNow->submitUrl($url);
            } catch (\Throwable $e) {
                logger(
                    'error',
                    esc_html__('IndexNow synchronization failed.', 'simple-seo'),
                    [
                        'entity_type' => $entityType,
                        'entity_id' => (string) $entityId,
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        if (SimpleSeoSettings::get('enable_google_indexing', false)) {
            try {
                $this->googleIndexing->submitUrl($url);
            } catch (\Throwable $e) {
                logger(
                    'error',
                    esc_html__('IndexNow synchronization failed.', 'simple-seo'),
                    [
                        'entity_type' => $entityType,
                        'entity_id' => (string) $entityId,
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /**
     * @param int|string|null $contentId
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function syncContent(int|string|null $contentId): void
    {
        $this->sync('content', $contentId);
    }

    /**
     * @param int|string|null $productId
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function syncProduct(int|string|null $productId): void
    {
        $this->sync('product', $productId);
    }

    /**
     * @param int|string|null $pageId
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function syncPage(int|string|null $pageId): void
    {
        $this->sync('page', $pageId);
    }

    /**
     * @param string $entityType
     * @param int|string $entityId
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function resolveUrl(string $entityType, int|string $entityId): string
    {
        return match ($entityType) {
            'content' => $this->contentUrl((string) $entityId),
            'product' => $this->productUrl((string) $entityId),
            'page', 'pages' => $this->pageUrl((int) $entityId),
            default => '',
        };
    }

    /**
     * @param string $contentId
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function contentUrl(string $contentId): string
    {
        $content = get_content_by('id', $contentId);

        if (!$content instanceof Content) {
            return '';
        }

        if (!empty($content->relativeUrl)) {
            return site_url($content->relativeUrl);
        }

        return rtrim(site_url(), '/') . '/' . trim((string) $content->slug, '/') . '/';
    }

    /**
     * @param string $productId
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function productUrl(string $productId): string
    {
        $product = get_product_by('id', $productId);

        if (!$product instanceof Product) {
            return '';
        }

        if (!empty($product->relativeUrl)) {
            return site_url($product->relativeUrl);
        }

        return rtrim(site_url(), '/') . '/product/' . trim((string) $product->slug, '/') . '/';
    }

    /**
     * @param int $pageId
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function pageUrl(int $pageId): string
    {
        $page = get_page_by('id', $pageId);

        if (!$page instanceof Page) {
            return '';
        }

        $route = trim((string) $page->route, '/');

        return $route === ''
            ? site_url()
            : rtrim(site_url(), '/') . '/' . $route . '/';
    }
}
