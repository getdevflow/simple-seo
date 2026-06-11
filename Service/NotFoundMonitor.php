<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\NotFoundRepository;
use Psr\Http\Message\ServerRequestInterface;

use function parse_url;

use const PHP_URL_PATH;

final readonly class NotFoundMonitor
{
    public function __construct(private NotFoundRepository $notFound, private ServerRequestInterface $request)
    {
    }

    public function recordCurrentRequest(): void
    {
        $psr7 = [
            'path' => empty($this->request->getUri()->getPath()) ? null : $this->request->getUri()->getPath(),
            'referer' => empty($this->request->getHeaderLine('Referer'))
                ? null
                : $this->request->getHeaderLine('Referer'),
            'agent' => empty($this->request->getHeaderLine('User-Agent'))
                ? null
                : $this->request->getHeaderLine('User-Agent'),
        ];
        $path = parse_url($psr7['path'] ?? $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($this->shouldSkip($path)) {
            return;
        }

        $this->notFound->record(
            requestPath: $path,
            referrer: $psr7['referer'] ?? $_SERVER['HTTP_REFERER'] ?? null,
            userAgent: $psr7['agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null
        );
    }

    private function shouldSkip(string $path): bool
    {
        $path = '/' . trim($path, '/') . '/';

        if (str_starts_with($path, '/admin/')) {
            return true;
        }

        if (str_starts_with($path, '/api/')) {
            return true;
        }

        if (
            str_contains($path, '.css')
                || str_contains($path, '.js')
                || str_contains($path, '.png')
                || str_contains($path, '.jpg')
                || str_contains($path, '.jpeg')
                || str_contains($path, '.gif')
                || str_contains($path, '.webp')
                || str_contains($path, '.svg')
                || str_contains($path, '.ico')
        ) {
            return true;
        }

        return false;
    }
}
