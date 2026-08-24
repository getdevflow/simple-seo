<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\NotFoundRepository;
use Psr\Http\Message\ServerRequestInterface;

use function strtolower;
use function trim;

final readonly class NotFoundMonitor
{
    public function __construct(private NotFoundRepository $notFound, private ServerRequestInterface $request)
    {
    }

    public function recordCurrentRequest(): void
    {
        $host = strtolower(trim($this->request->getUri()->getHost()));
        $path = $this->request->getUri()->getPath() ?: '/';

        if ($this->shouldSkip($path)) {
            return;
        }

        $this->notFound->record(
            requestHost: $host,
            requestPath: $path,
            referrer: $this->request->getHeaderLine('Referer') ?: null,
            userAgent: $this->request->getHeaderLine('User-Agent') ?: null
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
