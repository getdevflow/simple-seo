<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\RedirectRepository;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\RedirectResponseFactory;

use function App\Shared\Helpers\site_url;
use function parse_url;
use function rtrim;
use function str_starts_with;
use function trim;

use const PHP_URL_PATH;

final readonly class UriRedirectService
{
    public function __construct(private RedirectRepository $redirects)
    {
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface|null
     * @throws \Qubus\Exception\Exception
     */
    public function handle(RequestInterface $request): ?ResponseInterface
    {
        $path = $this->requestPath($request);

        $redirect = $this->redirects->findBySourceUrl($path);

        if ($redirect === false) {
            return null;
        }

        $targetUrl = trim((string) $redirect->target_url);

        if ($targetUrl === '') {
            return null;
        }

        $targetUrl = $this->absoluteTargetUrl($targetUrl);

        if ($this->samePath($path, $targetUrl)) {
            return null;
        }

        $statusCode = (int) ($redirect->status_code ?? 301);

        if (!in_array($statusCode, [301, 302, 307, 308], true)) {
            $statusCode = 301;
        }

        $this->redirects->incrementHits($redirect->id);

        return RedirectResponseFactory::create($targetUrl, $statusCode);
    }

    private function requestPath(RequestInterface $request): string
    {
        $path = '';

        if (method_exists($request, 'getUri')) {
            $path = (string) $request->getUri()->getPath();
        }

        if ($path === '') {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        }

        return '/' . trim($path, '/') . '/';
    }

    /**
     * @param string $targetUrl
     * @return string
     * @throws \Qubus\Exception\Exception
     */
    private function absoluteTargetUrl(string $targetUrl): string
    {
        if (str_starts_with($targetUrl, 'http://') || str_starts_with($targetUrl, 'https://')) {
            return $targetUrl;
        }

        return rtrim(site_url(), '/') . '/' . trim($targetUrl, '/') . '/';
    }

    private function samePath(string $currentPath, string $targetUrl): bool
    {
        $targetPath = parse_url($targetUrl, PHP_URL_PATH) ?: '/';

        return trim($currentPath, '/') === trim($targetPath, '/');
    }
}
