<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;

final class TrackingSettingsService
{
    /**
     * @return array[]
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function config(): array
    {
        $gaId = $this->googleAnalyticsId();
        $gtmId = $this->googleTagManagerId();

        return [
            'googleAnalytics' => [
                'enabled' => (bool) SimpleSeoSettings::get('enable_google_analytics', false) && $gaId !== '',
                'id' => $gaId,
            ],
            'googleTagManager' => [
                'enabled' => (bool) SimpleSeoSettings::get('enable_google_tag_manager', false) && $gtmId !== '',
                'id' => $gtmId,
            ],
        ];
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function googleAnalyticsId(): string
    {
        $id = strtoupper(trim((string) SimpleSeoSettings::get('google_analytics_id', '')));

        return preg_match('/^G-[A-Z0-9]+$/', $id) === 1 ? $id : '';
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function googleTagManagerId(): string
    {
        $id = strtoupper(trim((string) SimpleSeoSettings::get('google_tag_manager_id', '')));

        return preg_match('/^GTM-[A-Z0-9-]+$/', $id) === 1 ? $id : '';
    }
}
