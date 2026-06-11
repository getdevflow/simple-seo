<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Support;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Exception;
use ReflectionException;

use function App\Shared\Helpers\get_content_by;
use function App\Shared\Helpers\get_page_by;
use function App\Shared\Helpers\get_product_by;

final class AdminFields
{
    /**
     * @param string $html
     * @param string $contentType
     * @param string|null $contentId
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function contentExtended(string $html, string $contentType, ?string $contentId = null): string
    {
        return $html . $this->tabs('content', $contentId, ['content_type' => $contentType]);
    }

    /**
     * @param string $html
     * @param string $contentType
     * @param string|null $contentId
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function contentSide(string $html, string $contentType, ?string $contentId = null): string
    {
        return $html . $this->side('content', $contentId, ['content_type' => $contentType]);
    }

    /**
     * @param string $html
     * @param string|null $productId
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function productExtended(string $html, ?string $productId = null): string
    {
        return $html . $this->tabs('product', $productId);
    }

    /**
     * @param string $html
     * @param string|null $productId
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function productSide(string $html, ?string $productId = null): string
    {
        return $html . $this->side('product', $productId);
    }

    /**
     * @param string $html
     * @param int|string|null $pageId
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function pageExtended(string $html, int|string|null $pageId = null): string
    {
        return $html . $this->tabs('page', $pageId !== null ? (string) $pageId : null);
    }

    /**
     * @param string $html
     * @param int|string|null $pageId
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function pageSide(string $html, int|string|null $pageId = null): string
    {
        return $html . $this->side('page', $pageId !== null ? (string) $pageId : null);
    }

    /**
     * @param string $type
     * @param string|null $id
     * @param array $context
     * @return string
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function tabs(string $type, ?string $id = null, array $context = []): string
    {
        $seo = AttributeStore::getSeo($type, $id);
        $fieldPrefix = $this->fieldPrefix($type);
        $entity = $this->resolveEntity($type, $id);

        ob_start();
        include dirname(__DIR__) . '/view/partial/seo-tabs.phtml';
        return (string) ob_get_clean();
    }

    /**
     * @param string $type
     * @param string|null $id
     * @param array $context
     * @return string
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws InvalidArgumentException
     * @throws Exception
     * @throws ReflectionException
     */
    private function side(string $type, ?string $id = null, array $context = []): string
    {
        $seo = AttributeStore::getSeo($type, $id);
        $fieldPrefix = $this->fieldPrefix($type);
        $entity = $this->resolveEntity($type, $id);

        ob_start();
        include dirname(__DIR__) . '/view/partial/seo-side.phtml';
        return (string) ob_get_clean();
    }

    private function fieldPrefix(string $type): string
    {
        return match ($type) {
            'product' => 'product_field',
            'page', 'pages' => 'page_field',
            default => 'content_field',
        };
    }

    /**
     * @param string $type
     * @param string|null $id
     * @return object|false
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function resolveEntity(string $type, ?string $id = null): object|false
    {
        if ($id === null || $id === '') {
            return false;
        }

        return match ($type) {
            'product' => get_product_by('id', $id),
            'page', 'pages' => get_page_by('id', (int) $id),
            default => get_content_by('id', $id),
        };
    }
}
