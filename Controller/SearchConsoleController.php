<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Exception;
use Plugin\SimpleSeo\Service\GoogleOAuthService;
use Plugin\SimpleSeo\Service\SearchConsoleService;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\JsonResponseFactory;

use function Codefy\Framework\Helpers\view;
use function getenv;
use function Qubus\Security\Helpers\t__;

final readonly class SearchConsoleController
{
    public function __construct(
        private SearchConsoleService $searchConsole,
        private GoogleOAuthService $oauth
    ) {
    }

    /**
     * @return ResponseInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     * @throws Exception
     */
    public function index(): ResponseInterface
    {
        $stats = [
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => '0%',
            'position' => '—',
        ];

        $topQueries = [];
        $topPages = [];
        $error = '';

        $isConnected = $this->oauth->isConnected();
        $isConfigured = $this->searchConsole->isConfigured();

        if ($isConfigured) {
            try {
                $stats = $this->searchConsole->summary();
                $topQueries = $this->searchConsole->topQueries();
                $topPages = $this->searchConsole->topPages();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('plugin::SimpleSeo/view/search-console', [
            'title' => t__('Search Statistics', 'simple-seo'),
            'settings' => SimpleSeoSettings::all(),
            'stats' => $stats,
            'topQueries' => $topQueries,
            'topPages' => $topPages,
            'isConnected' => $isConnected,
            'isConfigured' => $isConfigured,
            'error' => $error,
        ]);
    }

    /**
     * @return ResponseInterface
     * @throws Exception
     */
    public function test(): ResponseInterface
    {
        try {
            $this->searchConsole->summary();

            return JsonResponseFactory::create([
                'ok' => true,
                'message' => t__('Google Search Console connected successfully.', 'simple-seo'),
            ]);
        } catch (\Throwable $e) {
            return JsonResponseFactory::create([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
