<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Exception;
use Plugin\SimpleSeo\Service\GoogleIndexingService;
use Plugin\SimpleSeo\Service\IndexNowService;
use Plugin\SimpleSeo\Service\SearchConsoleService;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\JsonResponseFactory;
use Qubus\Http\ServerRequest;

use function App\Shared\Helpers\site_url;
use function array_filter;
use function array_map;
use function array_values;
use function preg_split;
use function Qubus\Security\Helpers\t__;
use function rtrim;
use function sprintf;
use function trim;

final readonly class ManualIndexingController
{
    public function __construct(
        private IndexNowService $indexNow,
        private GoogleIndexingService $googleIndexing,
        private SearchConsoleService $searchConsole
    ) {
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Exception
     */
    public function submitUrl(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $url = trim((string) ($post['url'] ?? ''));
        $engine = trim((string) ($post['engine'] ?? 'both'));

        if ($url === '') {
            return $this->json(false, t__('Missing URL.', 'simple-seo'));
        }

        return $this->submitUrlsToEngines([$url], $engine);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Exception
     */
    public function submitUrls(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();

        $rawUrls = trim((string) ($post['urls'] ?? ''));
        $engine = trim((string) ($post['engine'] ?? 'both'));

        $urls = array_values(array_filter(array_map(
            static fn(string $url): string => trim($url),
            preg_split('/\R/', $rawUrls) ?: []
        )));

        if ($urls === []) {
            return $this->json(false, t__('No URLs were provided.', 'simple-seo'));
        }

        return $this->submitUrlsToEngines($urls, $engine);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function submitSitemap(ServerRequest $request): ResponseInterface
    {
        $post = $request->getParsedBody();
        $ok = false;

        $sitemapUrl = trim((string) ($post['sitemap_url'] ?? ''));

        if ($sitemapUrl === '') {
            $sitemapUrl = rtrim(site_url(), '/') . '/sitemap.xml';
        }

        $engine = trim((string) ($post['engine'] ?? 'both'));

        $results = [];

        if ($engine === 'indexnow' || $engine === 'both') {
            try {
                $this->indexNow->submitUrl($sitemapUrl);
                $results[] = t__('Submitted sitemap to IndexNow.', 'simple-seo');
                $ok = true;
            } catch (\Throwable $e) {
                $results[] = t__(sprintf('IndexNow failed: %s', $e->getMessage()), 'simple-seo');
            }
        }

        if ($engine === 'google' || $engine === 'both') {
            try {
                $this->searchConsole->submitSitemap($sitemapUrl);
                $results[] = t__('Submitted sitemap to Google Search Console.', 'simple-seo');
                $ok = true;
            } catch (\Throwable $e) {
                $results[] = t__(sprintf('Google failed: %s', $e->getMessage()), 'simple-seo');
            }
        }

        return $this->json($ok, implode(' ', $results));
    }

    /**
     * @param array $urls
     * @param string $engine
     * @return ResponseInterface
     * @throws \Exception
     */
    private function submitUrlsToEngines(array $urls, string $engine): ResponseInterface
    {
        $results = [];
        $ok = false;

        if ($engine === 'indexnow' || $engine === 'both') {
            try {
                $this->indexNow->submitUrls($urls);
                $results[] = t__(sprintf('Submitted %s URL(s) to IndexNow.', count($urls)), 'simple-seo');
                $ok = true;
            } catch (\Throwable $e) {
                $results[] = t__(sprintf('IndexNow failed: %s', $e->getMessage()), 'simple-seo');
            }
        }

        if ($engine === 'google' || $engine === 'both') {
            $submitted = 0;
            $failed = 0;

            $errors = [];

            foreach ($urls as $url) {
                try {
                    $this->googleIndexing->submitUrl($url);
                    $submitted++;
                    $ok = true;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $e->getMessage();
                }
            }

            if ($submitted === 0 && $errors !== []) {
                $results[] = t__(sprintf('Google failed: %s', $errors[0]), 'simple-seo');
            } else {
                $results[] = t__(
                    sprintf('Google Indexing submitted: %s. Failed: %s.', $submitted, $failed),
                    'simple-seo'
                );
            }
        }

        return $this->json($ok, implode(' ', $results));
    }

    /**
     * @param bool $ok
     * @param string $message
     * @return ResponseInterface
     * @throws \Exception
     */
    private function json(bool $ok, string $message): ResponseInterface
    {
        return JsonResponseFactory::create([
            'ok' => $ok,
            'message' => $message,
        ]);
    }
}
