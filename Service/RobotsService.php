<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function App\Shared\Helpers\site_url;
use function rtrim;
use function trim;

final readonly class RobotsService
{
    public function __construct(
        private SitemapService $sitemaps
    ) {
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function render(): string
    {
        if (!SimpleSeoSettings::get('enable_robots_txt', true)) {
            return '';
        }

        $lines = [];

        $lines[] = 'User-agent: *';

        if (!SimpleSeoSettings::get('robots_indexing_enabled', true)) {
            $lines[] = 'Disallow: /';
        } else {
            $custom = trim((string) SimpleSeoSettings::get('robots_custom_rules', ''));

            if ($custom !== '') {
                $lines[] = $custom;
            } else {
                $lines[] = 'Allow: /';
            }
        }

        if (
                SimpleSeoSettings::get('robots_include_sitemap', true)
                && $this->sitemaps->hasActiveSitemaps()
        ) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . rtrim(site_url(), '/') . '/sitemap.xml';
        }

        return trim(implode("\n", $lines)) . "\n";
    }
}
