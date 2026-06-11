<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use function preg_match;
use function sprintf;
use function strtoupper;

final class TrackingCodeRendererService
{
    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function renderHead(): string
    {
        $html = '';

        if (SimpleSeoSettings::get('enable_google_tag_manager', false)) {
            $gtmId = $this->googleTagManagerId();

            if ($gtmId !== '') {
                $html .= $this->googleTagManagerHead($gtmId);
            }
        }

        return $html;
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function renderFooter(): string
    {
        $html = '';

        if (SimpleSeoSettings::get('enable_google_analytics', false)) {
            $gaId = $this->googleAnalyticsId();

            if ($gaId !== '') {
                $html .= $this->googleAnalyticsFooter($gaId);
            }
        }

        return $html;
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function renderBodyOpen(): string
    {
        if (!SimpleSeoSettings::get('enable_google_tag_manager', false)) {
            return '';
        }

        $gtmId = $this->googleTagManagerId();

        if ($gtmId === '') {
            return '';
        }

        return sprintf(
            "\n<!-- Google Tag Manager (noscript) -->\n" .
                "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=%s\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n" .
                "<!-- End Google Tag Manager (noscript) -->\n",
            $gtmId
        );
    }

    private function googleAnalyticsFooter(string $id): string
    {
        return sprintf(
            "\n<!-- Google Analytics -->\n" .
                "<script async src=\"https://www.googletagmanager.com/gtag/js?id=%s\"></script>\n" .
                "<script>\n" .
                "window.dataLayer = window.dataLayer || [];\n" .
                "function gtag(){dataLayer.push(arguments);}\n" .
                "gtag('js', new Date());\n" .
                "gtag('config', '%s');\n" .
                "</script>\n" .
                "<!-- End Google Analytics -->\n",
            $id,
            $id
        );
    }

    private function googleTagManagerHead(string $id): string
    {
        return sprintf(
            "\n<!-- Google Tag Manager -->\n" .
                "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n" .
                "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n" .
                "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n" .
                "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n" .
                "})(window,document,'script','dataLayer','%s');</script>\n" .
                "<!-- End Google Tag Manager -->\n",
            $id
        );
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function googleAnalyticsId(): string
    {
        $id = trim((string) SimpleSeoSettings::get('google_analytics_id', ''));
        $id = strtoupper($id);

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
        $id = trim((string) SimpleSeoSettings::get('google_tag_manager_id', ''));
        $id = strtoupper($id);

        return preg_match('/^GTM-[A-Z0-9-]+$/', $id) === 1 ? $id : '';
    }
}
