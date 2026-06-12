<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\NotFoundRepository;
use Plugin\SimpleSeo\Repository\RedirectRepository;
use Plugin\SimpleSeo\Repository\SubmissionQueueRepository;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function App\Shared\Helpers\get_content;
use function App\Shared\Helpers\get_pages;
use function App\Shared\Helpers\get_products;
use function App\Shared\Helpers\site_url;
use function array_filter;
use function count;
use function curl_close;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_array;
use function Qubus\Security\Helpers\t__;
use function rtrim;
use function str_contains;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

final readonly class CrawlDiagnosticsService
{
    public function __construct(
        private RedirectRepository $redirects,
        private GoogleOAuthService $googleOAuth,
        private SitemapService $sitemaps,
        private NotFoundRepository $notFound,
        private SubmissionQueueRepository $submissionQueue,
    ) {
    }

    /**
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function report(): array
    {
        $siteUrl = rtrim(site_url(), '/');
        $hasSitemaps = $this->hasActiveSitemaps();

        return [
                $this->check(
                    'robots_txt',
                    t__('robots.txt is reachable.', 'simple-seo'),
                    $this->isReachable($siteUrl . '/robots.txt')
                ),

                $hasSitemaps
                    ? $this->check(
                        'sitemap_xml',
                        t__('sitemap.xml is reachable.', 'simple-seo'),
                        $this->isSitemapReachable($siteUrl . '/sitemap.xml')
                    )
                    : [
                    'key' => 'sitemap_xml',
                    'label' => 'sitemap.xml',
                    'pass' => true,
                    'value' => 'Disabled',
                ],

                $this->check(
                    'indexnow',
                    t__('IndexNow is configured.', 'simple-seo'),
                    SimpleSeoSettings::get('enable_indexnow', false)
                        && SimpleSeoSettings::get('indexnow_key', '') !== ''
                ),

                $this->check(
                    'google_oauth',
                    t__('Google OAuth is connected.', 'simple-seo'),
                    $this->googleOAuth->isConnected()
                ),

                $this->check(
                    'search_console_site_url',
                    t__('Search Console Site URL is configured.', 'simple-seo'),
                    SimpleSeoSettings::get('google_search_console_site_url', '') !== ''
                ),

                $this->metric(
                    'active_sitemaps',
                    t__('Active sitemap count', 'simple-seo'),
                    $this->activeSitemapCount()
                ),
                $this->metric(
                    'published_content',
                    t__('Published content count', 'simple-seo'),
                    $this->publishedContentCount()
                ),
                $this->metric(
                    'published_pages',
                    t__('Published page count', 'simple-seo'),
                    $this->publishedPageCount()
                ),
                $this->metric(
                    'published_products',
                    t__('Published product count', 'simple-seo'),
                    $this->publishedProductCount()
                ),
                $this->metric(
                    'submission_queue_pending',
                    t__('Pending URL submissions', 'simple-seo'),
                    $this->submissionQueue->countPending()
                ),
                $this->metric('redirect_hits', t__('Redirect hit count', 'simple-seo'), $this->redirectHitCount()),
                $this->metric('not_found_hits', t__('404 count', 'simple-seo'), $this->notFoundCount()),
                $this->metric('redirects_total', t__('Redirects total', 'simple-seo'), $this->redirectCount(false)),
                $this->metric('redirects_active', t__('Active redirects', 'simple-seo'), $this->redirectCount(true)),
        ];
    }

    private function check(string $key, string $label, bool $pass): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'pass' => $pass,
            'value' => $pass ? 'OK' : t__('Needs attention', 'simple-seo'),
        ];
    }

    private function metric(string $key, string $label, int|string $value): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'pass' => true,
            'value' => (string) $value,
        ];
    }

    private function isReachable(string $url): bool
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        curl_close($ch);

        return $status >= 200 && $status < 400 && is_string($body) && $body !== '';
    }

    private function isSitemapReachable(string $url): bool
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        curl_close($ch);

        return $status >= 200
            && $status < 400
            && is_string($body)
            && (
                    str_contains($body, '<sitemapindex')
                    || str_contains($body, '<urlset')
            );
    }

    private function hasActiveSitemaps(): bool
    {
        return $this->activeSitemapCount() > 0;
    }

    private function activeSitemapCount(): int
    {
        try {
            $xml = $this->sitemaps->index();

            if ($xml === '') {
                return 0;
            }

            return substr_count($xml, '<sitemap>');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function publishedContentCount(): int
    {
        try {
            $items = get_content();

            if (!$items) {
                return 0;
            }

            return count(array_filter((array) $items, function ($item): bool {
                $status = is_array($item)
                        ? ($item['status'] ?? $item['content_status'] ?? '')
                        : ($item->status ?? $item->content_status ?? '');

                return $status === 'published';
            }));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function publishedPageCount(): int
    {
        try {
            $items = get_pages();

            if (!$items) {
                return 0;
            }

            return count($items);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function publishedProductCount(): int
    {
        try {
            $items = get_products();

            if (!$items) {
                return 0;
            }

            return count(array_filter((array) $items, function ($item): bool {
                $status = is_array($item)
                        ? ($item['status'] ?? $item['product_status'] ?? '')
                        : ($item->status ?? $item->product_status ?? '');

                return $status === 'published';
            }));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function redirectCount(bool $activeOnly): int
    {
        $rows = $this->redirects->all();

        if (!$activeOnly) {
            return count($rows);
        }

        return count(array_filter($rows, static function ($row): bool {
            return (int) ($row->enabled ?? 0) === 1;
        }));
    }

    private function redirectHitCount(): int
    {
        $hits = 0;

        foreach ($this->redirects->all() as $row) {
            $hits += (int) ($row->hits ?? 0);
        }

        return $hits;
    }

    private function notFoundCount(): int
    {
        return $this->notFound->count();
    }
}
