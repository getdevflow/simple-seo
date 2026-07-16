<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function Qubus\Security\Helpers\t__;

final class SettingsImportExportService
{
    private const array EXCLUDED_KEYS = [];

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function export(): string
    {
        $settings = SimpleSeoSettings::all();

        foreach (self::EXCLUDED_KEYS as $key) {
            unset($settings[$key]);
        }

        return json_encode([
            'plugin' => 'simple-seo',
            'version' => '1.2.0',
            'exported_at' => date('c'),
            'settings' => $settings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param string $json
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function import(string $json): void
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(t__('Invalid JSON file.', 'simple-seo'));
        }

        $settings = $decoded['settings'] ?? null;

        if (!is_array($settings)) {
            throw new \RuntimeException(t__('Import file does not contain settings.', 'simple-seo'));
        }

        foreach (self::EXCLUDED_KEYS as $key) {
            unset($settings[$key]);
        }

        $allowed = array_keys(SimpleSeoSettings::defaults());

        $settings = array_intersect_key(
            $settings,
            array_flip($allowed)
        );

        $current = SimpleSeoSettings::all();

        SimpleSeoSettings::save(array_replace($current, $settings));
    }
}
