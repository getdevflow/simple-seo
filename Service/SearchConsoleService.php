<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function base64_encode;
use function date;
use function json_decode;
use function json_encode;
use function Qubus\Security\Helpers\t__;
use function rawurlencode;
use function str_replace;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const JSON_UNESCAPED_SLASHES;

final readonly class SearchConsoleService
{
    public function __construct(private GoogleOAuthService $oauth)
    {
    }

    public function test(): bool
    {
        $this->summary();

        return true;
    }

    private function base64Url(string $value): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($value)), '=');
    }

    /**
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function isConfigured(): bool
    {
        return $this->oauth->isConnected()
            && SimpleSeoSettings::get('google_search_console_site_url', '') !== '';
    }

    public function summary(): array
    {
        $data = $this->query();

        $row = $data['rows'][0] ?? [];

        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => isset($row['ctr']) ? round(((float) $row['ctr']) * 100, 2) . '%' : '0%',
            'position' => isset($row['position']) ? round((float) $row['position'], 1) : '—',
        ];
    }

    public function topQueries(int $limit = 10): array
    {
        return $this->rows(['query'], $limit);
    }

    public function topPages(int $limit = 10): array
    {
        return $this->rows(['page'], $limit);
    }

    public function rows(array $dimensions, int $limit = 10): array
    {
        $data = $this->query($dimensions, $limit);

        return $data['rows'] ?? [];
    }

    /**
     * @param array $dimensions
     * @param int $limit
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function query(array $dimensions = [], int $limit = 10): array
    {
        $siteUrl = (string) SimpleSeoSettings::get('google_search_console_site_url', '');

        if ($siteUrl === '') {
            throw new \RuntimeException(t__('Missing Search Console site URL.', 'simple-seo'));
        }

        $payload = [
            'startDate' => date('Y-m-d', strtotime('-28 days')),
            'endDate' => date('Y-m-d', strtotime('-2 days')),
            'rowLimit' => $limit,
        ];

        if ($dimensions !== []) {
            $payload['dimensions'] = $dimensions;
        }

        return $this->postJson(
            'https://www.googleapis.com/webmasters/v3/sites/'
                . rawurlencode($siteUrl)
                . '/searchAnalytics/query',
            $payload
        );
    }

    /**
     * @param string $endpoint
     * @param array $payload
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function postJson(string $endpoint, array $payload): array
    {
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->oauth->accessToken(),
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            $details = '';

            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);

                if (is_array($decoded)) {
                    $details = ' ' . ($decoded['error']['message'] ?? json_encode($decoded));
                } else {
                    $details = ' ' . mb_substr($body, 0, 500);
                }
            }

            throw new \RuntimeException(
                t__('Search Console request failed with HTTP ', 'simple-seo') . $status .
                    ($error ? ': ' . $error : '.') .
                    $details
            );
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string $sitemapUrl
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function submitSitemap(string $sitemapUrl): void
    {
        $siteUrl = trim((string) SimpleSeoSettings::get('google_search_console_site_url', ''));

        if ($siteUrl === '') {
            throw new \RuntimeException(t__('Search Console site URL is not configured.', 'simple-seo'));
        }

        $sitemapUrl = trim($sitemapUrl);

        if ($sitemapUrl === '') {
            throw new \RuntimeException(t__('Missing sitemap URL.', 'simple-seo'));
        }

        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/'
                . rawurlencode($siteUrl)
                . '/sitemaps/'
                . rawurlencode($sitemapUrl);

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->oauth->accessToken(),
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                t__('Google sitemap submission failed with HTTP ', 'simple-seo')
                . $status
                . ($error ? ': ' . $error : '.')
            );
        }
    }
}
