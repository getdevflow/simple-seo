<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\RouteSeoRepository;

use function App\Shared\Helpers\get_content_by;
use function App\Shared\Helpers\get_page_by;
use function App\Shared\Helpers\get_product_by;
use function App\Shared\Helpers\home_url;
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
            return $this->normalizePublicUrl(
                (string) $content->relativeUrl
            );
        }

        return $this->normalizePublicUrl(
            '/' . trim((string) $content->slug, '/') . '/'
        );
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

        return $this->normalizePublicUrl(
            '/product/' . trim((string) $product->slug, '/') . '/'
        );
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

        return $this->normalizePublicUrl($route);
    }

    /**
     * @param string $id
     * @return string|null
     */
    public function customRouteUrl(string $id): ?string
    {
        $route = $this->routes->findById($id);

        if ($route === false || (int) ($route->enabled ?? 0) !== 1) {
            return null;
        }

        return $this->normalizePublicUrl((string) $route->route_path);
    }

    private function normalizePublicUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        return home_url('/' . ltrim($url, '/'));
    }
}
