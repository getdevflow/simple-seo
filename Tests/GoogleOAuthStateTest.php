<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Tests;

use PHPUnit\Framework\TestCase;
use Plugin\SimpleSeo\Service\GoogleOAuthService;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;

final class GoogleOAuthStateTest extends TestCase
{
    public function testAuthorizationStateSurvivesSettingsPersistenceAndCanBeCleared(): void
    {
        $original = SimpleSeoSettings::all();
        $originalHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'cms.test';

        try {
            SimpleSeoSettings::save(array_replace($original, ['google_client_id' => 'test-client']));

            $url = (new GoogleOAuthService())->authUrl();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $query['state']);
            self::assertSame($query['state'], SimpleSeoSettings::get('google_oauth_state', ''));

            // The successful code exchange clears state by saving without this key.
            $settings = SimpleSeoSettings::all();
            unset($settings['google_oauth_state']);
            SimpleSeoSettings::save($settings);

            self::assertSame('', SimpleSeoSettings::get('google_oauth_state', ''));
        } finally {
            SimpleSeoSettings::save($original);
            if ($originalHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $originalHost;
            }
        }
    }
}
