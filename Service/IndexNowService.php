<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function App\Shared\Helpers\site_url;
use function array_values;
use function Codefy\Framework\Helpers\env;
use function json_encode;
use function parse_url;
use function Qubus\Security\Helpers\t__;
use function rawurlencode;
use function rtrim;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const JSON_UNESCAPED_SLASHES;
use const PHP_URL_HOST;

final class IndexNowService
{
    /**
     * @param string $url
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function submitUrl(string $url): void
    {
        $this->submitUrls([$url]);
    }

    /**
     * @param array $urls
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function submitUrls(array $urls): void
    {
        if (!SimpleSeoSettings::get('enable_indexnow', false)) {
            throw new \RuntimeException(t__('IndexNow is disabled.', 'simple-seo'));
        }

        $key = $this->key();

        if ($key === '') {
            throw new \RuntimeException(t__('Missing IndexNow key.', 'simple-seo'));
        }

        $urls = array_values(array_filter(array_map(
            static fn($url) => trim((string) $url),
            $urls
        )));

        if ($urls === []) {
            throw new \RuntimeException(t__('No URLs provided.', 'simple-seo'));
        }

        $siteUrl = rtrim(site_url(), '/');

        $host = SimpleSeoSettings::get('indexnow_host', '');

        if ($host === '') {
            $host = parse_url($siteUrl, PHP_URL_HOST) ?: '';
        }

        if ($host === '') {
            throw new \RuntimeException(t__('Unable to determine IndexNow host.', 'simple-seo'));
        }

        $payload = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => $siteUrl . '/' . rawurlencode($key) . '.txt',
            'urlList' => $urls,
        ], JSON_UNESCAPED_SLASHES);

        $this->postJson('https://api.indexnow.org/indexnow', $payload ?: '{}');
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function key(): string
    {
        return trim((string) SimpleSeoSettings::get(
            'indexnow_key',
            env('INDEXNOW_KEY', '')
        ));
    }

    private function postJson(string $endpoint, string $payload): void
    {
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                t__('IndexNow request failed with HTTP ', 'simple-seo') . $status . ($error ? ': ' . $error : '.')
            );
        }
    }
}
