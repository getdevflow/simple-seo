<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Support;

use function App\Shared\Helpers\get_option;
use function App\Shared\Helpers\update_option;

final class SimpleSeoSettings
{
    public const string OPTION_KEY = 'simple_seo_settings';

    public static function defaults(): array
    {
        return [
            'remove_settings' => false,
            'site_name' => '',
            'separator' => '–',
            'default_title_template' => '{title} {separator} {site_name}',
            'default_description' => '',
            'default_social_image' => '',
            'organization_name' => '',
            'organization_logo' => '',
            'organization_phone' => '',
            'organization_schema_type' => 'WebPage',
            'homepage_title' => '',
            'homepage_description' => '',
            'homepage_schema_type' => 'WebSite',
            'homepage_robots_index' => true,
            'homepage_robots_follow' => true,
            'homepage_robots_noarchive' => false,
            'homepage_robots_nosnippet' => false,
            'google_site_verification' => '',
            'bing_site_verification' => '',
            'yandex_site_verification' => '',
            'baidu_site_verification' => '',
            'google_service_account_json' => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_oauth_refresh_token' => '',
            'google_oauth_access_token' => '',
            'google_oauth_token_expires' => '',
            'google_search_console_site_url' => '',
            'enable_google_analytics' => false,
            'google_analytics_id' => '',
            'enable_google_tag_manager' => false,
            'google_tag_manager_id' => '',
            'indexnow_key' => '',
            'indexnow_host' => '',
            'enable_indexnow' => true,
            'enable_google_indexing' => false,
            'enable_sitemap_content' => true,
            'enable_sitemap_products' => true,
            'enable_sitemap_news' => false,
            'enable_sitemap_images' => false,
            'enable_sitemap_videos' => false,
            'enable_sitemap_content_types' => true,
            'enable_sitemap_pages' => false,
            'enable_sitemap_stylesheet' => false,
            'enable_robots_txt' => true,
            'robots_indexing_enabled' => true,
            'robots_custom_rules' => '',
            'robots_include_sitemap' => true,
            'auto_submit_sitemaps' => false,
            'sitemap_changefreq' => 'weekly',
            'sitemap_priority_content' => '0.7',
            'sitemap_priority_products' => '0.8',
            'robots_default' => ['index' => true, 'follow' => true],
        ];
    }

    /**
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function installDefaults(): void
    {
        if (get_option(self::OPTION_KEY) === false) {
            update_option(self::OPTION_KEY, self::defaults());
        }
    }

    /**
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?: [];
        }
        return array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = self::all();
        return $settings[$key] ?? $default;
    }

    /**
     * @param array $input
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function save(array $input): void
    {
        $clean = self::sanitize($input);
        update_option(self::OPTION_KEY, $clean);
    }

    public static function sanitize(array $input): array
    {
        unset($input['_active_tab']);

        $defaults = self::defaults();
        $out = [];
        foreach ($defaults as $key => $default) {
            if (is_bool($default)) {
                $out[$key] = array_key_exists($key, $input)
                    ? filter_var($input[$key], FILTER_VALIDATE_BOOL)
                    : false;

                continue;
            }

            $value = $input[$key] ?? $default;

            if (is_array($default)) {
                $out[$key] = is_array($value) ? $value : $default;
            } else {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }
}
