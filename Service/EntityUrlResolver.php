<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\RouteSeoRepository;

use function App\Shared\Helpers\get_content_by;
use function App\Shared\Helpers\get_page_by;
use function App\Shared\Helpers\get_product_by;
use function App\Shared\Helpers\site_url;
use function rtrim;
use function trim;

final readonly class EntityUrlResolver
{
    public function __construct(private RouteSeoRepository $routes)
    {
    }

    /**
     * @param string $id
     * @return string|null
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function contentUrl(string $id): ?string
    {
        $content = get_content_by('id', $id);

        if ($content === false) {
            return null;
        }

        if (!empty($content->relativeUrl)) {
            return rtrim(site_url(), '/') . '/' . trim((string) $content->relativeUrl, '/') . '/';
        }

        if (!empty($content->slug)) {
            return rtrim(site_url(), '/') . '/' . trim((string) $content->slug, '/') . '/';
        }

        return null;
    }

    /**
     * @param string $id
     * @return string|null
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function productUrl(string $id): ?string
    {
        $product = get_product_by('id', $id);

        if ($product === false || empty($product->slug)) {
            return null;
        }

        return rtrim(site_url(), '/') . '/product/' . trim((string) $product->slug, '/') . '/';
    }

    /**
     * @param int|string $id
     * @return string|null
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function pageUrl(int|string $id): ?string
    {
        $page = get_page_by('id', (int) $id);

        if ($page === false) {
            return null;
        }

        $route = trim((string) ($page->route ?? ''), '/');

        return $route === ''
            ? rtrim(site_url(), '/') . '/'
            : rtrim(site_url(), '/') . '/' . $route . '/';
    }

    /**
     * @param string $id
     * @return string|null
     * @throws \Qubus\Exception\Exception
     */
    public function customRouteUrl(string $id): ?string
    {
        $route = $this->routes->findById($id);

        if ($route === false || (int) ($route->enabled ?? 0) !== 1) {
            return null;
        }

        return rtrim(site_url(), '/') . $this->routes->normalizeRoute((string) $route->route_path);
    }
}
