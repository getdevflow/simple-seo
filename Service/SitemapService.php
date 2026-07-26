<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Codefy\QueryBus\UnresolvableQueryHandlerException;
use Plugin\SimpleSeo\Support\AttributeStore;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Plugin\SimpleSeo\Support\VideoSitemapEntry;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Exception;
use Qubus\Expressive\Database;
use ReflectionException;
use XMLWriter;

use function App\Shared\Helpers\get_all_content_types;
use function App\Shared\Helpers\get_all_content_with_filters;
use function App\Shared\Helpers\get_all_products_with_filters;
use function App\Shared\Helpers\site_url;
use function App\Shared\Helpers\sort_list;
use function array_filter;
use function array_map;
use function array_merge;
use function array_unshift;
use function array_values;
use function Codefy\Framework\Helpers\logger;
use function date;
use function gmdate;
use function in_array;
use function is_array;
use function preg_split;
use function Qubus\Security\Helpers\esc_html;
use function Qubus\Security\Helpers\esc_html__;
use function Qubus\Security\Helpers\esc_url;
use function Qubus\Security\Helpers\purify_html;
use function rtrim;
use function strip_tags;
use function strtotime;
use function trim;

final readonly class SitemapService
{
    public function __construct(private Database $dfdb)
    {
    }

    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnresolvableQueryHandlerException
     */
    public function index(): string
    {
        $base = rtrim($this->baseUrl(), '/');
        $settings = SimpleSeoSettings::all();
        $maps = [];

        if ($this->settingEnabled($settings, 'enable_sitemap_content')) {
            $entry = $this->sitemapEntry(
                'sitemap-content.xml',
                $this->content()
            );

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if ($this->settingEnabled($settings, 'enable_sitemap_products')) {
            $entry = $this->sitemapEntry(
                'sitemap-products.xml',
                $this->products()
            );

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if ($this->settingEnabled($settings, 'enable_sitemap_pages')) {
            $entry = $this->sitemapEntry(
                'sitemap-pages.xml',
                array_merge(
                    $this->rows('page'),
                    $this->routeRows()
                )
            );

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if ($this->settingEnabled($settings, 'enable_sitemap_news')) {
            $rows = array_filter(
                $this->content(),
                static fn(array $row): bool => !empty($row['seo']['news_enabled'])
            );

            $entry = $this->sitemapEntry(
                'sitemap-news.xml',
                $rows
            );

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if ($this->settingEnabled($settings, 'enable_sitemap_images')) {
            $entry = $this->sitemapEntry(
                'sitemap-images.xml',
                $this->imageRows()
            );

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if ($this->settingEnabled($settings, 'enable_sitemap_videos')) {
            $entry = $this->sitemapEntry(
                'sitemap-videos.xml',
                $this->videoRows()
            );

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if (
            $this->settingEnabled(
                $settings,
                'enable_sitemap_content_types'
            )
        ) {
            foreach (get_all_content_types() as $type) {
                $slug = trim((string) ($type['slug'] ?? ''));

                if ($slug === '') {
                    continue;
                }

                $entry = $this->sitemapEntry(
                    'sitemap-' . $slug . '.xml',
                    $this->rows('content', $slug)
                );

                if ($entry !== null) {
                    $maps[] = $entry;
                }
            }
        }

        if ($maps === []) {
            return '';
        }

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $this->stylesheetPi($xml);

        $xml->startElement('sitemapindex');
        $xml->writeAttribute(
            'xmlns',
            'http://www.sitemaps.org/schemas/sitemap/0.9'
        );

        foreach ($maps as $map) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $base . '/' . $map);
            $xml->writeElement('lastmod', gmdate('c'));
            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * @param string $kind
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function urls(string $kind): string
    {
        if (($kind === 'content') && !SimpleSeoSettings::get('enable_sitemap_content', false)) {
            return '';
        }

        if (
            ($kind === 'product' || $kind === 'products')
            && !SimpleSeoSettings::get('enable_sitemap_products', false)
        ) {
            return '';
        }

        $rows = match ($kind) {
            'product', 'products' => $this->products(),
            default => $this->content(),
        };

        return $this->urlset($rows, includeImages: false, news: false);
    }

    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function news(): string
    {
        if (!SimpleSeoSettings::get('enable_sitemap_news', false)) {
            return '';
        }

        return $this->urlset(
            array_filter(
                $this->content(),
                fn($r) => !empty($r['seo']['news_enabled'])
            ),
            false,
            true
        );
    }

    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function images(): string
    {
        if (!SimpleSeoSettings::get('enable_sitemap_images', false)) {
            return '';
        }

        return $this->urlset(
            rows: $this->imageRows(),
            includeImages: true,
            news: false
        );
    }

    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function videos(): string
    {
        if (
            !SimpleSeoSettings::get(
                'enable_sitemap_videos',
                false
            )
        ) {
            return '';
        }

        return $this->videoUrlset(
            $this->videoRows()
        );
    }

    /**
     * @param string $contentType
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function contentType(string $contentType): string
    {
        if (!SimpleSeoSettings::get('enable_sitemap_content_types', false)) {
            return '';
        }

        return $this->urlset(
            $this->rows('content', $contentType),
            false,
            false
        );
    }

    /**
     * @param array $rows
     * @param bool $includeImages
     * @param bool $news
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function urlset(array $rows, bool $includeImages, bool $news): string
    {
        $written = 0;

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $this->stylesheetPi($xml);
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        if ($includeImages) {
            $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
        }
        if ($news) {
            $xml->writeAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
        }

        foreach ($rows as $row) {
            $seo = $row['seo'];
            $sitemapInclude = $seo['sitemap_include'] ?? '1';
            $robotsIndex = $seo['robots_index'] ?? '1';

            if ($sitemapInclude === '0' || $robotsIndex === '0') {
                continue;
            }
            $xml->startElement('url');
            $written++;
            $xml->writeElement('loc', $row['url']);
            if (!empty($row['updated_at'])) {
                $xml->writeElement('lastmod', date('c', strtotime((string)$row['updated_at'])));
            }
            $xml->writeElement(
                'changefreq',
                !empty($seo['sitemap_changefreq'])
                    ? (string) $seo['sitemap_changefreq']
                    : (string) SimpleSeoSettings::get('sitemap_changefreq', 'weekly')
            );
            $defaultPriority = match ($row['kind'] ?? 'content') {
                'product' => SimpleSeoSettings::get('sitemap_priority_products', '0.8'),
                'page', 'route' => SimpleSeoSettings::get('sitemap_priority_pages', '0.6'),
                default => SimpleSeoSettings::get('sitemap_priority_content', '0.7'),
            };
            $xml->writeElement(
                'priority',
                (string) (!empty($seo['sitemap_priority'])
                    ? $seo['sitemap_priority']
                    : $defaultPriority)
            );
            if ($includeImages) {
                $images = $this->lines(
                    (string) ($seo['image_urls'] ?? '')
                );

                $featuredImage = trim((string) ($row['image'] ?? ''));

                if (
                        $featuredImage !== ''
                        && !in_array($featuredImage, $images, true)
                ) {
                    array_unshift($images, $featuredImage);
                }

                foreach ($images as $image) {
                    $xml->startElement('image:image');
                    $xml->writeElement('image:loc', $image);
                    $xml->endElement();
                }
            }
            if ($news) {
                $xml->startElement('news:news');
                $xml->startElement('news:publication');
                $xml->writeElement('news:name', SimpleSeoSettings::get('site_name', 'Devflow'));
                $xml->writeElement('news:language', 'en');
                $xml->endElement();
                $xml->writeElement(
                    'news:publication_date',
                    !empty($row['created_at']) ? date('c', strtotime((string)$row['created_at'])) : gmdate('c')
                );
                $xml->writeElement('news:title', $row['title']);
                if (!empty($seo['news_keywords'])) {
                    $xml->writeElement('news:keywords', $seo['news_keywords']);
                }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        if ($written === 0) {
            return '';
        }
        return $xml->outputMemory();
    }

    /**
     * @param array<string, mixed> $seo
     * @return list<string>
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public static function validateVideos(array $seo): array
    {
        $videos = $seo['videos'] ?? [];

        if (!is_array($videos)) {
            return [];
        }

        $errors = [];

        foreach (array_values($videos) as $index => $video) {
            if (!is_array($video)) {
                $errors[] = sprintf(
                    esc_html__('Video %d has invalid data.', 'simple-seo'),
                    $index + 1
                );

                continue;
            }

            if (self::videoRowIsEmpty($video)) {
                continue;
            }

            $entry = VideoSitemapEntry::fromArray($video);

            if (!$entry->isValid()) {
                $errors[] = sprintf(
                    esc_html__(
                        'Video %d requires a valid source URL, thumbnail URL, title, and description.',
                        'simple-seo'
                    ),
                    $index + 1
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $video
     */
    private static function videoRowIsEmpty(array $video): bool
    {
        return array_all([
            'content_url',
            'player_url',
            'thumbnail_url',
            'title',
            'description',
            'duration',
            'publication_date',
        ], fn($field) => trim((string)($video[$field] ?? '')) === '');
    }

    /**
     * @return array
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function content(): array
    {
        return $this->rows('content');
    }

    /**
     * @return string
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function pages(): string
    {
        if (!SimpleSeoSettings::get('enable_sitemap_pages', false)) {
            return '';
        }

        return $this->urlset(
            array_merge(
                $this->rows('page'),
                $this->routeRows()
            ),
            false,
            false
        );
    }

    public function stylesheet(): string
    {
        return <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
    xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
    xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">

    <xsl:output method="html" encoding="UTF-8" indent="yes"/>

    <xsl:template match="/">
        <html>
            <head>
                <title>XML Sitemap</title>
                <style>
                    body {
                        margin: 0;
                        padding: 0;
                        background: #f5f7fa;
                        color: #222;
                        font-family: Arial, Helvetica, sans-serif;
                        font-size: 14px;
                    }

                    .wrap {
                        max-width: 1100px;
                        margin: 40px auto;
                        background: #fff;
                        border: 1px solid #d9e1ea;
                        border-radius: 4px;
                        box-shadow: 0 2px 8px rgba(0,0,0,.04);
                    }

                    .header {
                        padding: 24px 30px;
                        border-bottom: 1px solid #e5ebf1;
                        background: #fbfcfd;
                    }

                    h1 {
                        margin: 0 0 8px;
                        font-size: 24px;
                        font-weight: 600;
                    }

                    .description {
                        color: #666;
                        margin: 0;
                    }

                    .count {
                        margin-top: 10px;
                        color: #777;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                    }

                    th {
                        text-align: left;
                        background: #f0f3f6;
                        color: #333;
                        padding: 12px 14px;
                        border-bottom: 1px solid #d9e1ea;
                        font-size: 13px;
                        text-transform: uppercase;
                    }

                    td {
                        padding: 12px 14px;
                        border-bottom: 1px solid #edf1f5;
                        vertical-align: top;
                    }

                    tr:hover td {
                        background: #fbfcfd;
                    }

                    a {
                        color: #2271b1;
                        text-decoration: none;
                        word-break: break-all;
                    }

                    a:hover {
                        text-decoration: underline;
                    }

                    .muted {
                        color: #777;
                    }

                    .badge {
                        display: inline-block;
                        padding: 2px 7px;
                        border-radius: 10px;
                        background: #eef5ff;
                        color: #2271b1;
                        font-size: 12px;
                    }

                    .footer {
                        padding: 16px 30px;
                        color: #777;
                        background: #fbfcfd;
                    }
                </style>
            </head>
            <body>
                <div class="wrap">
                    <xsl:choose>
                        <xsl:when test="sitemap:sitemapindex">
                            <div class="header">
                                <h1>Sitemap Index</h1>
                                <p class="description">This sitemap index lists available XML sitemaps.</p>
                                <p class="count">
                                    <xsl:value-of select="count(sitemap:sitemapindex/sitemap:sitemap)"/>
                                    sitemap(s)
                                </p>
                            </div>

                            <table>
                                <thead>
                                    <tr>
                                        <th>Sitemap</th>
                                        <th>Last Modified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
                                        <tr>
                                            <td>
                                                <a href="{sitemap:loc}">
                                                    <xsl:value-of select="sitemap:loc"/>
                                                </a>
                                            </td>
                                            <td class="muted">
                                                <xsl:value-of select="sitemap:lastmod"/>
                                            </td>
                                        </tr>
                                    </xsl:for-each>
                                </tbody>
                            </table>
                        </xsl:when>

                        <xsl:otherwise>
                            <div class="header">
                                <h1>XML Sitemap</h1>
                                <p class="description">This sitemap lists URLs available for search engines.</p>
                                <p class="count">
                                    <xsl:value-of select="count(sitemap:urlset/sitemap:url)"/>
                                    URL(s)
                                </p>
                            </div>

                            <table>
                                <thead>
                                    <tr>
                                        <th>URL</th>
                                        <th>Last Modified</th>
                                        <th>Change Frequency</th>
                                        <th>Priority</th>
                                        <th>Media</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <xsl:for-each select="sitemap:urlset/sitemap:url">
                                        <tr>
                                            <td>
                                                <a href="{sitemap:loc}">
                                                    <xsl:value-of select="sitemap:loc"/>
                                                </a>
                                            </td>
                                            <td class="muted">
                                                <xsl:value-of select="sitemap:lastmod"/>
                                            </td>
                                            <td>
                                                <xsl:value-of select="sitemap:changefreq"/>
                                            </td>
                                            <td>
                                                <xsl:value-of select="sitemap:priority"/>
                                            </td>
                                            <td>
                                                <xsl:if test="count(image:image) &gt; 0">
                                                    <span class="badge">
                                                        <xsl:value-of select="count(image:image)"/>
                                                        image(s)
                                                    </span>
                                                </xsl:if>
                                                <xsl:if test="count(video:video) &gt; 0">
                                                    <span class="badge">
                                                        <xsl:value-of select="count(video:video)"/>
                                                        video(s)
                                                    </span>
                                                </xsl:if>
                                                <xsl:if test="news:news">
                                                    <span class="badge">news</span>
                                                </xsl:if>
                                            </td>
                                        </tr>
                                    </xsl:for-each>
                                </tbody>
                            </table>
                        </xsl:otherwise>
                    </xsl:choose>

                    <div class="footer">
                        Generated by Simple SEO for Devflow CMS.
                    </div>
                </div>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
XSL;
    }

    /**
     * @return array
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function products(): array
    {
        return $this->rows('product');
    }

    /**
     * @param string $kind
     * @param string|null $contentType
     * @return array
     * @throws Exception
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function rows(string $kind, ?string $contentType = null): array
    {
        if ($kind === 'page') {
            return $this->pageRows();
        }

        $items = [];
        $id = 'id';
        $title = 'title';
        $slug = 'slug';
        $body = 'body';
        $image = 'featuredImage';
        $created = 'created';
        $updated = 'modified';

        try {
            if ($kind === 'content') {
                $content = get_all_content_with_filters(
                    contentTypeSlug: $contentType,
                    status: 'published'
                );

                if (!is_array($content)) {
                    return [];
                }

                $items = sort_list(
                    $content,
                    'published',
                    'DESC'
                );
            }

            if ($kind === 'product') {
                $products = get_all_products_with_filters(
                    status: 'published'
                );

                if (!is_array($products)) {
                    return [];
                }

                $items = sort_list(
                    $products,
                    'published',
                    'DESC'
                );
            }
        } catch (\Throwable $exception) {
            logger(
                'error',
                esc_html__('Simple SEO failed to load sitemap rows.', 'simple-seo'),
                [
                    'kind' => $kind,
                    'content_type' => $contentType,
                    'message' => $exception->getMessage(),
                ]
            );

            return [];
        }
        $out = [];
        foreach ($items as $row) {
            $get = static fn($k, $d = '') => is_array($row) ? ($row[$k] ?? $d) : ($row->{$k} ?? $d);
            $seo = AttributeStore::getSeo($kind, esc_html($get($id)));
            $url = rtrim($this->baseUrl(), '/') . '/' . trim($get($slug), '/') . '/';
            $out[] = [
                'kind' => $kind,
                'id' => esc_html($get($id)),
                'title' => purify_html($get($title)),
                'description' => mb_substr(strip_tags(esc_html($get($body))), 0, 160),
                'url' => esc_url($url),
                'image' => esc_html($get($image)),
                'created_at' => esc_html($get($created)),
                'updated_at' => esc_html($get($updated)),
                'seo' => $seo
            ];
        }
        return $out;
    }

    /**
     * @return array
     * @throws Exception
     */
    private function routeRows(): array
    {
        $table = $this->dfdb->prefix . 'seo_route';

        try {
            $items = $this->dfdb->getResults(
                "SELECT id, route_path, route_label, seo_data, enabled, updated_at, created_at
             FROM {$table}
             WHERE enabled = 1
             ORDER BY updated_at DESC"
            );
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($items)) {
            return [];
        }

        $out = [];

        foreach ($items as $row) {
            $seo = json_decode((string) ($row->seo_data ?? ''), true);

            if (!is_array($seo)) {
                $seo = [];
            }

            $routePath = trim((string) ($row->route_path ?? ''), '/');

            $out[] = [
                'kind' => 'route',
                'id' => (string) ($row->id ?? ''),
                'title' => (string) ($row->route_label ?? $routePath),
                'description' => (string) ($seo['meta_description'] ?? ''),
                'url' => $routePath === ''
                    ? rtrim($this->baseUrl(), '/') . '/'
                    : rtrim($this->baseUrl(), '/') . '/' . $routePath . '/',
                'image' => (string) ($seo['facebook_image'] ?? $seo['twitter_image'] ?? ''),
                'created_at' => (string) ($row->created_at ?? ''),
                'updated_at' => (string) ($row->updated_at ?? ''),
                'seo' => $seo,
            ];
        }

        return $out;
    }

    /**
     * @return string
     * @throws \Qubus\Exception\Exception
     */
    private function baseUrl(): string
    {
        return site_url();
    }

    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
    }

    /**
     * @return array
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function pageRows(): array
    {
        $pagesTable = $this->dfdb->prefix . 'pages';
        $translationsTable = $this->dfdb->prefix . 'page_translations';

        $locale = SimpleSeoSettings::get('site_locale', 'en');

        try {
            $items = $this->dfdb->getResults(
                $this->dfdb->prepare(
                    "SELECT 
                    p.id,
                    p.page_attribute,
                    t.locale,
                    t.title,
                    t.meta_title,
                    t.meta_description,
                    t.route
                FROM {$pagesTable} p
                INNER JOIN {$translationsTable} t
                    ON t.page_id = p.id
                WHERE t.locale = ?",
                    [$locale]
                )
            );
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($items)) {
            return [];
        }

        $out = [];

        foreach ($items as $row) {
            $pageId = (string) ($row->id ?? '');

            if ($pageId === '') {
                continue;
            }

            $seo = AttributeStore::getSeo('page', $pageId);

            $title = $row->meta_title !== ''
                ? $row->meta_title
                : ($row->title ?? '');

            $description = $row->meta_description ?? '';

            $out[] = [
                'kind' => 'page',
                'id' => $pageId,
                'title' => $title,
                'description' => $description,
                'url' => $this->pageUrl((string) ($row->route ?? '')),
                'image' => $seo['facebook_image'] ?? $seo['twitter_image'] ?? '',
                'created_at' => '',
                'updated_at' => '',
                'seo' => $seo,
                'locale' => $row->locale ?? $locale,
            ];
        }

        return $out;
    }

    /**
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function hasActiveSitemaps(): bool
    {
        $settings = SimpleSeoSettings::all();

        return
            $this->settingEnabled(
                $settings,
                'enable_sitemap_content'
            )
            || $this->settingEnabled(
                $settings,
                'enable_sitemap_products'
            )
            || $this->settingEnabled(
                $settings,
                'enable_sitemap_pages'
            )
            || $this->settingEnabled(
                $settings,
                'enable_sitemap_news'
            )
            || $this->settingEnabled(
                $settings,
                'enable_sitemap_images'
            )
            || $this->settingEnabled(
                $settings,
                'enable_sitemap_videos'
            )
            || $this->settingEnabled(
                $settings,
                'enable_sitemap_content_types'
            );
    }

    /**
     * @param string $route
     * @return string
     * @throws Exception
     */
    private function pageUrl(string $route): string
    {
        $route = trim($route, '/');

        if ($route === '' || $route === '/') {
            return rtrim($this->baseUrl(), '/') . '/';
        }

        return rtrim($this->baseUrl(), '/') . '/' . $route . '/';
    }

    private function hasRows(array $rows): bool
    {
        foreach ($rows as $row) {
            $seo = $row['seo'] ?? [];

            $sitemapInclude = $seo['sitemap_include'] ?? '1';
            $robotsIndex = $seo['robots_index'] ?? '1';

            if ($sitemapInclude !== '0' && $robotsIndex !== '0') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function imageRows(): array
    {
        $rows = array_merge(
            $this->content(),
            $this->products(),
            $this->rows('page')
        );

        return array_values(
            array_filter(
                $rows,
                static function (array $row): bool {
                    $seo = $row['seo'] ?? [];

                    return !empty($seo['image_urls']) || !empty($row['image']);
                }
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function videoRows(): array
    {
        $rows = array_merge(
            $this->content(),
            $this->products(),
            $this->rows('page')
        );

        return array_values(
            array_filter(
                $rows,
                function (array $row): bool {
                    if (!$this->rowIsIndexable($row)) {
                        return false;
                    }

                    return $this->validVideoEntries($row) !== [];
                }
            )
        );
    }

    private function sitemapEntry(string $filename, array $rows): ?string
    {
        return $this->hasRows($rows) ? $filename : null;
    }

    /**
     * @param XMLWriter $xml
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function stylesheetPi(XMLWriter $xml): void
    {
        if (!SimpleSeoSettings::get('enable_sitemap_stylesheet', false)) {
            return;
        }

        $xml->writePi(
            'xml-stylesheet',
            'type="text/xsl" href="' . rtrim(site_url(), '/') . '/sitemap.xsl"'
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return string
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function videoUrlset(array $rows): string
    {
        $written = 0;

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $this->stylesheetPi($xml);

        $xml->startElement('urlset');
        $xml->writeAttribute(
            'xmlns',
            'http://www.sitemaps.org/schemas/sitemap/0.9'
        );
        $xml->writeAttribute(
            'xmlns:video',
            'http://www.google.com/schemas/sitemap-video/1.1'
        );

        foreach ($rows as $row) {
            if (!$this->rowIsIndexable($row)) {
                continue;
            }

            $videos = $this->validVideoEntries($row);

            if ($videos === []) {
                continue;
            }

            $pageUrl = trim(
                (string) ($row['url'] ?? '')
            );

            if (
                filter_var(
                    $pageUrl,
                    FILTER_VALIDATE_URL
                ) === false
            ) {
                continue;
            }

            $xml->startElement('url');
            $xml->writeElement(
                'loc',
                $pageUrl
            );

            if (!empty($row['updated_at'])) {
                $timestamp = strtotime(
                    (string) $row['updated_at']
                );

                if ($timestamp !== false) {
                    $xml->writeElement(
                        'lastmod',
                        date('c', $timestamp)
                    );
                }
            }

            foreach ($videos as $video) {
                $this->writeVideoEntry(
                    $xml,
                    $video
                );
            }

            $xml->endElement();
            $written++;
        }

        $xml->endElement();

        if ($written === 0) {
            return '';
        }

        return $xml->outputMemory();
    }

    private function writeVideoEntry(XMLWriter $xml, VideoSitemapEntry $video): void
    {
        $xml->startElement('video:video');

        $xml->writeElement(
            'video:thumbnail_loc',
            $video->thumbnailUrl
        );

        $xml->writeElement(
            'video:title',
            $video->title
        );

        $xml->writeElement(
            'video:description',
            $video->description
        );

        if ($video->sourceType === 'player') {
            $xml->startElement('video:player_loc');
            $xml->writeAttribute(
                'allow_embed',
                'yes'
            );
            $xml->text($video->playerUrl);
            $xml->endElement();
        } else {
            $xml->writeElement(
                'video:content_loc',
                $video->contentUrl
            );
        }

        if ($video->duration !== null) {
            $xml->writeElement(
                'video:duration',
                (string) $video->duration
            );
        }

        if ($video->publicationDate !== null) {
            $xml->writeElement(
                'video:publication_date',
                $video->publicationDate
            );
        }

        $xml->endElement();
    }

    /**
     * @param array<string, mixed> $row
     * @return list<VideoSitemapEntry>
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function validVideoEntries(array $row): array
    {
        $seo = $row['seo'] ?? [];

        if (!is_array($seo)) {
            return [];
        }

        $storedVideos = $seo['videos'] ?? [];

        if (!is_array($storedVideos)) {
            return [];
        }

        $videos = [];

        foreach ($storedVideos as $storedVideo) {
            if (!is_array($storedVideo)) {
                continue;
            }

            $video = VideoSitemapEntry::fromArray(
                $storedVideo
            );

            if (!$video->isValid()) {
                continue;
            }

            $videos[] = $video;
        }

        return $videos;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowIsIndexable(array $row): bool
    {
        $seo = $row['seo'] ?? [];

        if (!is_array($seo)) {
            return false;
        }

        $sitemapInclude = $seo['sitemap_include'] ?? '1';
        $robotsIndex = $seo['robots_index'] ?? '1';

        return !in_array(
            $sitemapInclude,
            ['0', 0, false],
            true
        ) && !in_array(
            $robotsIndex,
            ['0', 0, false],
            true
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function settingEnabled(
        array $settings,
        string $key
    ): bool {
        return in_array(
            $settings[$key] ?? false,
            [true, 1, '1', 'true', 'on', 'yes'],
            true
        );
    }
}
