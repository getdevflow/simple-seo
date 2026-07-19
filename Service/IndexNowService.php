<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use JsonException;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Exception;
use ReflectionException;

use function App\Shared\Helpers\is_ssl;
use function array_filter;
use function array_map;
use function array_values;
use function Codefy\Framework\Helpers\env;
use function filter_var;
use function json_encode;
use function parse_url;
use function Qubus\Security\Helpers\t__;
use function rawurlencode;
use function sprintf;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const FILTER_VALIDATE_URL;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_URL_HOST;

final class IndexNowService
{
    /**
     * @param string $url
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function submitUrl(string $url): void
    {
        $this->submitUrls([$url]);
    }

    /**
     * @param array<string> $urls
     * @throws JsonException
     * @throws InvalidArgumentException
     * @throws Exception
     * @throws ReflectionException
     */
    public function submitUrls(array $urls): void
    {
        if (!SimpleSeoSettings::get('enable_indexnow', false)) {
            throw new \RuntimeException(
                t__('IndexNow is disabled.', 'simple-seo')
            );
        }

        $key = $this->key();

        if ($key === '') {
            throw new \RuntimeException(
                t__('Missing IndexNow key.', 'simple-seo')
            );
        }

        $urls = array_values(
            array_filter(
                array_map(
                    static fn(mixed $url): string => trim((string) $url),
                    $urls
                )
            )
        );

        if ($urls === []) {
            throw new \RuntimeException(
                t__('No URLs provided.', 'simple-seo')
            );
        }

        foreach ($urls as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new \RuntimeException(
                    sprintf(
                        t__('Invalid URL provided: %s', 'simple-seo'),
                        $url
                    )
                );
            }
        }

        $firstUrl = $urls[0];

        $host = trim(
            (string) SimpleSeoSettings::get('indexnow_host', '')
        );

        if (str_contains($host, '://')) {
            $host = (string) parse_url(
                $host,
                PHP_URL_HOST
            );
        }

        if ($host === '') {
            $host = (string) parse_url(
                $firstUrl,
                PHP_URL_HOST
            );
        }

        if ($host === '') {
            throw new \RuntimeException(
                sprintf(
                    t__(
                        'Unable to determine the IndexNow host for URL: %s',
                        'simple-seo'
                    ),
                    $firstUrl
                )
            );
        }

        $scheme = is_ssl() ? 'https://' : 'http://';

        $keyLocation = $scheme
            . $host
            . '/'
            . rawurlencode($key)
            . '.txt';

        $payload = json_encode(
            [
                'host' => $host,
                'key' => $key,
                'keyLocation' => $keyLocation,
                'urlList' => $urls,
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $this->postJson(
            endpoint: 'https://api.indexnow.org/indexnow',
            payload: $payload
        );
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

    private function postJson(
        string $endpoint,
        string $payload
    ): void {
        $ch = curl_init($endpoint);

        if ($ch === false) {
            throw new \RuntimeException(
                t__(
                    'Unable to initialize the IndexNow request.',
                    'simple-seo'
                )
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo(
            $ch,
            CURLINFO_RESPONSE_CODE
        );
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException(
                t__(
                    'The IndexNow request could not be completed.',
                    'simple-seo'
                )
                    . ($error !== '' ? ' ' . $error : '')
            );
        }

        if ($status >= 200 && $status < 300) {
            return;
        }

        $responseBody = trim((string) $body);

        throw new \RuntimeException(
            sprintf(
                t__(
                    'IndexNow request failed with HTTP %d%s',
                    'simple-seo'
                ),
                $status,
                $responseBody !== '' ? ': ' . $responseBody : '.'
            )
        );
    }
}
