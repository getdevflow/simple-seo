<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Support;

use function App\Shared\Helpers\add_content_attribute;
use function App\Shared\Helpers\add_page_attribute;
use function App\Shared\Helpers\add_product_attribute;
use function App\Shared\Helpers\get_content_attribute;
use function App\Shared\Helpers\get_page_attribute;
use function App\Shared\Helpers\get_product_attribute;
use function App\Shared\Helpers\update_content_attribute;
use function App\Shared\Helpers\update_page_attribute;
use function App\Shared\Helpers\update_product_attribute;

final class AttributeStore
{
    public const string FIELD = 'seo';

    public static function defaults(): array
    {
        return [
            'meta_title' => '',
            'meta_description' => '',
            'focus_keyphrase' => '',
            'canonical_url' => '',
            'robots_index' => true,
            'robots_follow' => true,
            'robots_noarchive' => false,
            'robots_nosnippet' => false,
            'facebook_title' => '',
            'facebook_description' => '',
            'facebook_image' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image' => '',
            'twitter_card' => 'summary_large_image',
            'schema_type' => '',
            'schema_json' => '',
            'sitemap_include' => true,
            'sitemap_priority' => '',
            'sitemap_changefreq' => '',
            'news_enabled' => false,
            'news_keywords' => '',
            'image_urls' => '',
            'video_urls' => '',
            'redirect_enabled' => false,
            'redirect_from' => '',
            'redirect_to' => '',
            'redirect_status' => '301',
        ];
    }

    public static function fromPost(array $post): array
    {
        $input = $post['seo'] ?? [];
        $out = [];
        foreach (self::defaults() as $key => $default) {
            $value = $input[$key] ?? $default;
            if (is_bool($default)) {
                $out[$key] = isset($input[$key]) && (string) $input[$key] !== '0';
            } else {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }

    /**
     * @param string $type
     * @param string|null $id
     * @return array
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function getSeo(string $type, int|string|null $id = null): array
    {
        if ($id === null || $id === '') {
            return self::defaults();
        }
        return match ($type) {
            'content' => get_content_attribute($id, self::FIELD, []),
            'product' => get_product_attribute($id, self::FIELD, []),
            'page', 'pages' => get_page_attribute($id, self::FIELD, []),
            default => [],
        };
    }

    /**
     * @param string $type
     * @param string $id
     * @param array $seo
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \ReflectionException
     */
    public static function saveSeo(string $type, string $id, array $seo): void
    {
        $seo = array_replace(self::defaults(), $seo);

        match ($type) {
            'content' => update_content_attribute($id, self::FIELD, $seo),
            'product' => update_product_attribute($id, self::FIELD, $seo),
            'page', 'pages' => update_page_attribute($id, self::FIELD, $seo),
            default => null,
        };
    }

    /**
     * @param string $type
     * @param string $id
     * @return mixed
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function readAttributes(string $type, string $id): mixed
    {
        return match ($type) {
            'content' => get_content_attribute($id, self::FIELD),
            'product' => get_product_attribute($id, self::FIELD),
            'page', 'pages' => get_page_attribute($id, self::FIELD),
            default => null,
        };
    }

    /**
     * @param string $type
     * @param string $id
     * @param array $attributes
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \ReflectionException
     */
    public static function writeAttributes(string $type, string $id, array $attributes): void
    {
        match ($type) {
            'content' => add_content_attribute($id, self::FIELD, $attributes),
            'product' => add_product_attribute($id, self::FIELD, $attributes),
            'page', 'pages' => add_page_attribute($id, self::FIELD, $attributes),
            default => null,
        };
    }
}
