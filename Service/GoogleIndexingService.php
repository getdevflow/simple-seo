<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Exception;
use ReflectionException;

use function Codefy\Framework\Helpers\env;
use function json_encode;
use function Qubus\Security\Helpers\t__;
use function rawurlencode;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const JSON_UNESCAPED_SLASHES;

final class GoogleIndexingService
{
    /**
     * @param string $url
     * @param string $type
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function submitUrl(string $url, string $type = 'URL_UPDATED'): void
    {
        $url = trim($url);

        if ($url === '') {
            throw new \RuntimeException(t__('No URL provided.', 'simple-seo'));
        }

        if (!SimpleSeoSettings::get('enable_google_indexing', false)) {
            throw new \RuntimeException(t__('Google Indexing API is disabled.', 'simple-seo'));
        }

        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            throw new \RuntimeException(
                t__(
                    'Missing Google Indexing API access token. Configure google_indexing_access_token or GOOGLE_INDEXING_ACCESS_TOKEN.',
                    'simple-seo'
                )
            );
        }

        $this->verifyToken($accessToken);

        $payload = json_encode([
            'url' => $url,
            'type' => $type,
        ], JSON_UNESCAPED_SLASHES);

        $this->postJson(
            'https://indexing.googleapis.com/v3/urlNotifications:publish',
            $payload ?: '{}',
            ['Authorization: Bearer ' . $accessToken]
        );
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function accessToken(): string
    {
        return trim((string) SimpleSeoSettings::get(
            'google_indexing_access_token',
            env('GOOGLE_INDEXING_ACCESS_TOKEN')
        ));
    }

    private function verifyToken(string $accessToken): void
    {
        $ch = curl_init(
            'https://www.googleapis.com/oauth2/v1/tokeninfo?access_token=' . rawurlencode($accessToken)
        );

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                t__('Google token verification failed', 'simple-seo') . ($error ? ': ' . $error : '.')
            );
        }
    }

    private function postJson(string $endpoint, string $payload, array $headers = []): void
    {
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                t__(
                    'Google Indexing request failed with HTTP ',
                    'simple-seo'
                ) . $status . ($error ? ': ' . $error : '.')
            );
        }
    }
}
