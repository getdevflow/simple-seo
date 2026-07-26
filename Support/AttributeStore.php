<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Support;

use Qubus\Exception\Exception;

use function App\Shared\Helpers\add_content_attribute;
use function App\Shared\Helpers\add_page_attribute;
use function App\Shared\Helpers\add_product_attribute;
use function App\Shared\Helpers\get_content_attribute;
use function App\Shared\Helpers\get_page_attribute;
use function App\Shared\Helpers\get_product_attribute;
use function App\Shared\Helpers\update_content_attribute;
use function App\Shared\Helpers\update_page_attribute;
use function App\Shared\Helpers\update_product_attribute;
use function preg_split;
use function trim;

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
            /*
             * Retained temporarily so old saved data can be migrated when read.
             * Do not render an admin field for this property.
             */
            'video_urls' => '',
            /*
             * Structured video sitemap entries.
             */
            'videos' => [],
            'redirect_enabled' => false,
            'redirect_from' => '',
            'redirect_to' => '',
            'redirect_status' => '301',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function fromPost(array $post): array
    {
        $input = $post[self::FIELD] ?? [];

        if (!is_array($input)) {
            $input = [];
        }

        $out = [];

        foreach (self::defaults() as $key => $default) {
            if ($key === 'videos') {
                $out[$key] = self::sanitizeVideos(
                    $input['videos'] ?? []
                );

                continue;
            }

            if ($key === 'video_urls') {
                /*
                 * Legacy field is not accepted from new forms.
                 */
                $out[$key] = '';

                continue;
            }

            $value = $input[$key] ?? $default;

            if (is_bool($default)) {
                $out[$key] = isset($input[$key]) && (string) $input[$key] !== '0';

                continue;
            }

            $out[$key] = trim((string) $value);
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
    public static function getSeo(string $type, ?string $id = null): array
    {
        if ($id === null || $id === '') {
            return self::defaults();
        }

        $stored = match ($type) {
            'content' => get_content_attribute(
                $id,
                self::FIELD,
                []
            ),
            'product' => get_product_attribute(
                $id,
                self::FIELD,
                []
            ),
            'page', 'pages' => get_page_attribute(
                $id,
                self::FIELD,
                []
            ),
            default => [],
        };

        if (!is_array($stored)) {
            $stored = [];
        }

        $seo = array_replace(
            self::defaults(),
            $stored
        );

        return self::migrateLegacyVideos($seo);
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
     * @throws Exception
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
     * @throws Exception
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

    /**
     * @param mixed $videos
     * @return list<array{
     *     source_type: string,
     *     content_url: string,
     *     player_url: string,
     *     thumbnail_url: string,
     *     title: string,
     *     description: string,
     *     duration: int|null,
     *     publication_date: string|null
     * }>
     */
    private static function sanitizeVideos(mixed $videos): array
    {
        if (!is_array($videos)) {
            return [];
        }

        $sanitized = [];

        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }

            $entry = VideoSitemapEntry::fromArray($video);

            /*
             * Preserve incomplete records in the editor so a user does not lose
             * work. Invalid records are excluded from sitemap generation later.
             */
            if (
                $entry->sourceUrl() === ''
                && $entry->thumbnailUrl === ''
                && $entry->title === ''
                && $entry->description === ''
            ) {
                continue;
            }

            $sanitized[] = $entry->toArray();
        }

        return array_values($sanitized);
    }

    /**
     * Converts old newline-separated URLs into editable video records.
     *
     * The migrated entries are intentionally incomplete because the old data
     * did not contain per-video thumbnails, titles, or descriptions.
     *
     * @param array<string, mixed> $seo
     * @return array<string, mixed>
     */
    private static function migrateLegacyVideos(array $seo): array
    {
        if (
            !empty($seo['videos'])
            || empty($seo['video_urls'])
        ) {
            return $seo;
        }

        $lines = preg_split(
            '/\R/',
            (string) $seo['video_urls']
        ) ?: [];

        $videos = [];

        foreach ($lines as $url) {
            $url = trim($url);

            if ($url === '') {
                continue;
            }

            $videos[] = [
                'source_type' => 'content',
                'content_url' => $url,
                'player_url' => '',
                'thumbnail_url' => '',
                'title' => '',
                'description' => '',
                'duration' => null,
                'publication_date' => null,
            ];
        }

        $seo['videos'] = $videos;

        return $seo;
    }
}
