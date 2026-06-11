<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function App\Shared\Helpers\admin_url;
use function http_build_query;
use function json_decode;
use function Qubus\Security\Helpers\t__;
use function time;
use function trim;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;

final class GoogleOAuthService
{
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function authUrl(): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', [
                'https://www.googleapis.com/auth/webmasters.readonly',
            ]),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * @param string $code
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->postToken([
            'code' => trim($code),
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (empty($response['refresh_token'])) {
            throw new \RuntimeException(t__('Google did not return a refresh token.', 'simple-seo'));
        }

        $settings = SimpleSeoSettings::all();
        $settings['google_oauth_access_token'] = $response['access_token'] ?? '';
        $settings['google_oauth_refresh_token'] = $response['refresh_token'];
        $settings['google_oauth_token_expires'] = (string) (time() + (int) ($response['expires_in'] ?? 3600));

        SimpleSeoSettings::save($settings);

        return $response;
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function accessToken(): string
    {
        $accessToken = trim((string) SimpleSeoSettings::get('google_oauth_access_token', ''));
        $expires = (int) SimpleSeoSettings::get('google_oauth_token_expires', 0);

        if ($accessToken !== '' && $expires > time() + 60) {
            return $accessToken;
        }

        return $this->refreshAccessToken();
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function refreshAccessToken(): string
    {
        $refreshToken = trim((string) SimpleSeoSettings::get('google_oauth_refresh_token', ''));

        if ($refreshToken === '') {
            throw new \RuntimeException(t__('Google account is not connected.', 'simple-seo'));
        }

        $response = $this->postToken([
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $accessToken = $response['access_token'] ?? '';

        if ($accessToken === '') {
            throw new \RuntimeException(t__('Google did not return an access token.', 'simple-seo'));
        }

        $settings = SimpleSeoSettings::all();
        $settings['google_oauth_access_token'] = $accessToken;
        $settings['google_oauth_token_expires'] = (string) (time() + (int) ($response['expires_in'] ?? 3600));

        SimpleSeoSettings::save($settings);

        return $accessToken;
    }

    /**
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function disconnect(): void
    {
        $settings = SimpleSeoSettings::all();
        $settings['google_oauth_access_token'] = '';
        $settings['google_oauth_refresh_token'] = '';
        $settings['google_oauth_token_expires'] = '';

        SimpleSeoSettings::save($settings);
    }

    /**
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function isConnected(): bool
    {
        return trim((string) SimpleSeoSettings::get('google_oauth_refresh_token', '')) !== '';
    }

    /**
     * @return string
     * @throws \Qubus\Exception\Exception
     */
    public function redirectUri(): string
    {
        return admin_url('plugin/simple-seo/google/callback/');
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function clientId(): string
    {
        $value = trim((string) SimpleSeoSettings::get('google_client_id', ''));

        if ($value === '') {
            throw new \RuntimeException(t__('Missing Google Client ID.', 'simple-seo'));
        }

        return $value;
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function clientSecret(): string
    {
        $value = trim((string) SimpleSeoSettings::get('google_client_secret', ''));

        if ($value === '') {
            throw new \RuntimeException(t__('Missing Google Client Secret.', 'simple-seo'));
        }

        return $value;
    }

    private function postToken(array $payload): array
    {
        $ch = curl_init(self::TOKEN_URL);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                t__('Google OAuth request failed with HTTP ', 'simple-seo') . $status . ($error ? ': ' . $error : '.')
            );
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
