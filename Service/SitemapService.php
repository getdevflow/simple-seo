<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Codefy\QueryBus\UnresolvableQueryHandlerException;
use Plugin\SimpleSeo\Support\AttributeStore;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
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
use function array_values;
use function date;
use function gmdate;
use function is_array;
use function preg_split;
use function Qubus\Security\Helpers\esc_html;
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
        $maps = [];

        if (SimpleSeoSettings::get('enable_sitemap_content', false)) {
            $entry = $this->sitemapEntry('sitemap-content.xml', $this->content());

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if (SimpleSeoSettings::get('enable_sitemap_products', false)) {
            $entry = $this->sitemapEntry('sitemap-products.xml', $this->products());

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if (SimpleSeoSettings::get('enable_sitemap_pages', false)) {
            $entry = $this->sitemapEntry('sitemap-pages.xml', $this->rows('page'));

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if (SimpleSeoSettings::get('enable_sitemap_news', false)) {
            $rows = array_filter(
                $this->content(),
                fn($r) => !empty($r['seo']['news_enabled'])
            );

            $entry = $this->sitemapEntry('sitemap-news.xml', $rows);

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if (SimpleSeoSettings::get('enable_sitemap_images', false)) {
            $rows = array_merge($this->content(), $this->products(), $this->rows('page'));

            $rows = array_filter($rows, function ($row) {
                $seo = $row['seo'] ?? [];

                return !empty($seo['image_urls'])
                        || !empty($row['image']);
            });

            $entry = $this->sitemapEntry('sitemap-images.xml', $rows);

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if (SimpleSeoSettings::get('enable_sitemap_videos', false)) {
            $rows = array_merge($this->content(), $this->products(), $this->rows('page'));

            $rows = array_filter($rows, function ($row) {
                return !empty(($row['seo'] ?? [])['video_urls']);
            });

            $entry = $this->sitemapEntry('sitemap-videos.xml', $rows);

            if ($entry !== null) {
                $maps[] = $entry;
            }
        }

        if (SimpleSeoSettings::get('enable_sitemap_content_types', false)) {
            foreach (get_all_content_types() as $type) {
                $slug = $type['slug'] ?? '';

                if ($slug === '') {
                    continue;
                }

                $rows = $this->rows('content', $slug);

                $entry = $this->sitemapEntry('sitemap-' . $slug . '.xml', $rows);

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
        $xml->writeAttribute('xmlns', 'https://www.sitemaps.org/schemas/sitemap/0.9');
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

        return $this->urlset($rows, includeImages: false, includeVideos: false, news: false);
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
            array_merge(
                $this->content(),
                $this->products()
            ),
            true,
            false,
            false
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
        if (!SimpleSeoSettings::get('enable_sitemap_videos', false)) {
            return '';
        }

        return $this->urlset(
            array_merge(
                $this->content(),
                $this->products()
            ),
            false,
            true,
            false
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
            false,
            false
        );
    }

    /**
     * @param array $rows
     * @param bool $includeImages
     * @param bool $includeVideos
     * @param bool $news
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function urlset(array $rows, bool $includeImages, bool $includeVideos, bool $news): string
    {
        $written = 0;

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $this->stylesheetPi($xml);
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'https://www.sitemaps.org/schemas/sitemap/0.9');
        if ($includeImages) {
            $xml->writeAttribute('xmlns:image', 'https://www.google.com/schemas/sitemap-image/1.1');
        }
        if ($includeVideos) {
            $xml->writeAttribute('xmlns:video', 'https://www.google.com/schemas/sitemap-video/1.1');
        }
        if ($news) {
            $xml->writeAttribute('xmlns:news', 'https://www.google.com/schemas/sitemap-news/0.9');
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
                'page' => SimpleSeoSettings::get('sitemap_priority_pages', '0.6'),
                default => SimpleSeoSettings::get('sitemap_priority_content', '0.7'),
            };
            $xml->writeElement(
                'priority',
                (string) (!empty($seo['sitemap_priority'])
                    ? $seo['sitemap_priority']
                    : $defaultPriority)
            );
            if ($includeImages) {
                foreach ($this->lines($seo['image_urls'] ?? '') as $image) {
                    $xml->startElement('image:image');
                    $xml->writeElement('image:loc', $image);
                    $xml->endElement();
                }
            }
            if ($includeVideos) {
                foreach ($this->lines($seo['video_urls'] ?? '') as $video) {
                    $xml->startElement('video:video');
                    $xml->writeElement(
                        'video:thumbnail_loc',
                        $row['image'] ?: SimpleSeoSettings::get('default_social_image', '')
                    );
                    $xml->writeElement('video:title', $row['title']);
                    $xml->writeElement('video:description', $row['description'] ?: $row['title']);
                    $xml->writeElement('video:content_loc', $video);
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
            $this->rows('page'),
            false,
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
    xmlns:sitemap="https://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:image="https://www.google.com/schemas/sitemap-image/1.1"
    xmlns:video="https://www.google.com/schemas/sitemap-video/1.1"
    xmlns:news="https://www.google.com/schemas/sitemap-news/0.9">

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
                $content = get_all_content_with_filters(contentTypeSlug: $contentType, status: 'published');
                $items = sort_list($content, 'published', 'DESC');
            }

            if ($kind === 'product') {
                $product = get_all_products_with_filters(status: 'published');
                $items = sort_list($product, 'published', 'DESC');
            }
        } catch (\Throwable) {
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
            !empty($settings['enable_sitemap_content'])
            || !empty($settings['enable_sitemap_product'])
            || !empty($settings['enable_sitemap_page'])
            || !empty($settings['enable_sitemap_content_types']);
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
}
