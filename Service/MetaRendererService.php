<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use App\Application\Devflow;
use App\Domain\Content\Model\Content;
use App\Domain\Page\Model\Page;
use App\Domain\Product\Model\Product;
use Melbahja\Seo\MetaTags;
use Plugin\SimpleSeo\Repository\RouteSeoRepository;
use Plugin\SimpleSeo\Support\AttributeStore;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Exception;
use ReflectionException;

use function App\Shared\Helpers\get_content_by;
use function App\Shared\Helpers\get_page_by;
use function App\Shared\Helpers\get_product_by;
use function App\Shared\Helpers\home_url;
use function App\Shared\Helpers\site_url;
use function implode;
use function json_encode;
use function mb_substr;
use function Qubus\Security\Helpers\esc_html;
use function Qubus\Security\Helpers\esc_url;
use function rtrim;
use function strip_tags;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class MetaRendererService
{
    /**
     * Render SEO meta tags for content, product, or homepage.
     *
     * Examples:
     * echo (new MetaRendererService())->renderContent($contentId);
     * echo (new MetaRendererService())->renderProduct($productId);
     * echo (new MetaRendererService())->renderHome();
     *
     * @param string $contentId
     * @return string
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function renderContent(string $contentId): string
    {
        /** @var Content $content */
        $content = get_content_by('id', $contentId);

        if ($content === false) {
            return $this->renderHome();
        }

        return $this->renderItem($this->normalizeContent($content));
    }

    /**
     * @param string $productId
     * @return string
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function renderProduct(string $productId): string
    {
        /** @var Product $product */
        $product = get_product_by('id', $productId);

        if ($product === false) {
            return $this->renderHome();
        }

        return $this->renderItem($this->normalizeProduct($product));
    }

    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function renderHome(): string
    {
        return $this->renderItem([
            'kind' => 'home',
            'title' => SimpleSeoSettings::get(
                'homepage_title',
                SimpleSeoSettings::get('site_name', '')
            ),
            'description' => SimpleSeoSettings::get(
                'homepage_description',
                SimpleSeoSettings::get('default_description', '')
            ),
            'url' => site_url(),
            'image' => SimpleSeoSettings::get('default_social_image', ''),
            'seo' => [
                'meta_title' => SimpleSeoSettings::get('homepage_title', ''),
                'meta_description' => SimpleSeoSettings::get('homepage_description', ''),
                'canonical_url' => site_url(),
                'robots_index' => SimpleSeoSettings::get('homepage_robots_index', true),
                'robots_follow' => SimpleSeoSettings::get('homepage_robots_follow', true),
                'robots_noarchive' => SimpleSeoSettings::get('homepage_robots_noarchive', false),
                'robots_nosnippet' => SimpleSeoSettings::get('homepage_robots_nosnippet', false),
                'schema_type' => SimpleSeoSettings::get('homepage_schema_type', 'WebSite'),
            ],
        ]);
    }

    /**
     * @param int|string $pageId
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function renderPage(int|string $pageId): string
    {
        $page = get_page_by('id', (int) $pageId);

        if ($page === false) {
            return $this->renderHome();
        }

        return $this->renderItem($this->normalizePage($page));
    }

    /**
     * Optional resolver for frontend routes where you only know kind + id.
     *
     * @param string $kind
     * @param string|null $id
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function render(string $kind, ?string $id = null): string
    {
        return match ($kind) {
            'content' => $id ? $this->renderContent($id) : $this->renderHome(),
            'product' => $id ? $this->renderProduct($id) : $this->renderHome(),
            'page', 'pages' => $id ? $this->renderPage($id) : $this->renderHome(),
            default => $this->renderHome(),
        };
    }

    /**
     * @param object $route
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function renderCustomRoute(object $route): string
    {
        $repo = Devflow::$PHP->make(name: RouteSeoRepository::class);

        $seo = $repo->seoData($route);

        $url = rtrim(site_url(), '/') . $repo->normalizeRoute((string) $route->route_path);

        return $this->renderItem([
            'kind' => 'route',
            'id' => (string) $route->id,
            'title' => (string) $route->route_label,
            'description' => $seo['meta_description'] ?? '',
            'url' => $url,
            'image' => $seo['facebook_image'] ?? $seo['twitter_image'] ?? '',
            'seo' => $seo,
        ]);
    }

    /**
     * @param array $item
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function renderItem(array $item): string
    {
        $seo = $item['seo'] ?? [];

        if (!in_array($item['kind'], ['home', 'route'], true) && !empty($item['id'])) {
            $seo = AttributeStore::getSeo($item['kind'], (string) $item['id']);

            if (!is_array($seo)) {
                $seo = [];
            }
        }

        $title = $this->formatTitle($seo['meta_title'] ?? $item['title'] ?? '');
        $description = $seo['meta_description']
            ?? $item['description']
            ?? SimpleSeoSettings::get('default_description', '');
        $url = trim((string) ($item['url'] ?? ''));

        $canonical = trim((string) ($seo['canonical_url'] ?? ''));

        if ($canonical === '') {
            $canonical = $url;
        }
        $image = $seo['facebook_image']
            ?? $seo['twitter_image']
            ?? $item['image']
            ?? SimpleSeoSettings::get('default_social_image', '');

        $robots = [
            empty($seo['robots_index']) ? 'noindex' : 'index',
            empty($seo['robots_follow']) ? 'nofollow' : 'follow',
        ];

        if (!empty($seo['robots_noarchive'])) {
            $robots[] = 'noarchive';
        }

        if (!empty($seo['robots_nosnippet'])) {
            $robots[] = 'nosnippet';
        }

        $tags = new MetaTags();

        $tags
            ->title($title)
            ->description($description)
            ->canonical(esc_url($canonical))
            ->robots(implode(',', $robots))
            ->og('type', match ($item['kind']) {
                'product' => 'product',
                'home', 'page', 'route' => 'website',
                default => 'article',
            })
            ->og('url', esc_url($canonical))
            ->twitter('url', esc_url($canonical));

        if ($image !== '') {
            $tags->image($image);
        }

        foreach ($this->verificationTags() as $name => $token) {
            if ($token !== '') {
                $tags->meta(esc_html($name), $token);
            }
        }

        $schema = new SchemaFactoryService()->make($item, $seo, $item['kind']);

        $schemaData = json_decode(json_encode($schema), true);

        if (is_array($schemaData)) {
            $schemaData = $this->cleanSchema($schemaData);
        }

        $jsonLd = json_encode(
            $schemaData,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        $schemaTag = $jsonLd
            ? "\n<script type=\"application/ld+json\">{$jsonLd}</script>"
            : '';

        return "\n" . (string) $tags . $schemaTag . "\n";
    }

    private function cleanSchema(array $schema): array
    {
        $schema = $this->removeNestedContext($schema, true);

        if (!isset($schema['@context'])) {
            $schema = ['@context' => 'https://schema.org'] + $schema;
        }

        return $schema;
    }

    private function removeNestedContext(array $data, bool $isRoot = false): array
    {
        if (!$isRoot) {
            unset($data['@context']);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->removeNestedContext($value, false);
            }
        }

        return $data;
    }

    /**
     * @param Content $content
     * @return array
     * @throws Exception
     */
    private function normalizeContent(Content $content): array
    {
        $url = $content->relativeUrl
            ? home_url($content->relativeUrl)
            : rtrim(site_url(), '/') . '/' . trim((string) $content->slug, '/') . '/';

        return [
            'kind' => 'content',
            'id' => $content->id,
            'title' => $content->title ?? '',
            'description' => $this->excerpt($content->body ?? ''),
            'body' => $content->body ?? '',
            'slug' => $content->slug ?? '',
            'type' => $content->type ?? '',
            'url' => $url,
            'image' => $content->featuredImage ?? '',
            'created_at' => $content->created ?? null,
            'updated_at' => $content->modified ?? null,
            'published_at' => $content->published ?? null,
        ];
    }

    /**
     * @param Product $product
     * @return array
     * @throws Exception
     */
    private function normalizeProduct(Product $product): array
    {
        $url = rtrim(site_url(), '/') . '/product/' . trim((string) $product->slug, '/') . '/';

        return [
            'kind' => 'product',
            'id' => $product->id,
            'title' => $product->title ?? '',
            'description' => $this->excerpt($product->body ?? ''),
            'body' => $product->body ?? '',
            'slug' => $product->slug ?? '',
            'url' => $url,
            'image' => $product->featuredImage ?? '',
            'sku' => $product->sku ?? '',
            'price' => $product->price ?? '0',
            'currency' => $product->currency ?? 'USD',
            'created_at' => $product->created ?? null,
            'updated_at' => $product->modified ?? null,
            'published_at' => $product->published ?? null,
        ];
    }

    /**
     * @param Page $page
     * @return array
     * @throws Exception
     */
    private function normalizePage(Page $page): array
    {
        $route = trim((string) $page->route, '/');

        $url = $route === ''
            ? site_url()
            : rtrim(site_url(), '/') . '/' . $route . '/';

        return [
            'kind' => 'page',
            'id' => (string) $page->id,
            'title' => $page->title ?? $page->name ?? '',
            'description' => $page->description ?? '',
            'body' => $page->data ?? '',
            'slug' => $route,
            'url' => $url,
            'image' => '',
            'created_at' => null,
            'updated_at' => null,
            'published_at' => null,
        ];
    }

    /**
     * @param string $title
     * @return string
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function formatTitle(string $title): string
    {
        $settings = SimpleSeoSettings::all();

        $template = $settings['default_title_template'] ?? '{title} {separator} {site_name}';

        return trim(strtr($template, [
            '{title}' => $title,
            '{separator}' => $settings['separator'] ?? '-',
            '{site_name}' => $settings['site_name'] ?? '',
        ]));
    }

    private function excerpt(string $body): string
    {
        return trim(mb_substr(strip_tags($body), 0, 160));
    }

    /**
     * @return array
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function verificationTags(): array
    {
        return [
            'google-site-verification' => SimpleSeoSettings::get('google_site_verification', ''),
            'msvalidate.01' => SimpleSeoSettings::get('bing_site_verification', ''),
            'yandex-verification' => SimpleSeoSettings::get('yandex_site_verification', ''),
        ];
    }
}
