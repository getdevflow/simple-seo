<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use InvalidArgumentException;
use Plugin\SimpleSeo\Repository\RedirectRepository;
use Plugin\SimpleSeo\Routing\RedirectMatch;
use Plugin\SimpleSeo\Routing\RedirectRuleMatcher;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

use function parse_url;
use function str_starts_with;
use function strtolower;
use function trim;

use const PHP_URL_HOST;
use const PHP_URL_PATH;

final readonly class RedirectResolver
{
    public function __construct(
        private RedirectRepository $redirects,
        private RedirectRuleMatcher $matcher
    ) {
    }

    public function resolve(RequestInterface $request): ?RedirectMatch
    {
        $uri = $request->getUri();

        $host = strtolower($uri->getHost());
        $path = $uri->getPath();
        $query = $uri->getQuery();

        /*
         * The Host header and URI are request-controlled input.
         * An invalid value should simply prevent redirect handling
         * rather than turn into a 500 response.
         */
        try {
            $redirects = $this->redirects->candidatesForHost($host);
        } catch (InvalidArgumentException) {
            return null;
        }

        foreach ($redirects as $redirect) {
            try {
                if (
                    !$this->matcher->matchesHost(
                        $redirect,
                        $host
                    )
                ) {
                    continue;
                }

                $capturedPath = $this->matcher->matchPath(
                    redirect: $redirect,
                    requestPath: $path
                );

                if ($capturedPath === null) {
                    continue;
                }

                $target = $this->matcher->buildTarget(
                    redirect: $redirect,
                    capturedPath: $capturedPath,
                    queryString: $query
                );

                /*
                 * Rules match by host and path, not query string.
                 *
                 * Therefore redirecting back to the same host/path
                 * creates a loop even if the destination changes
                 * scheme, query string, fragment, or port.
                 */
                if (
                    $this->isSameDestination(
                        currentUri: $uri,
                        targetUrl: $target
                    )
                ) {
                    continue;
                }

                return new RedirectMatch(
                    redirect: $redirect,
                    targetUrl: $target
                );
            } catch (InvalidArgumentException) {
                /*
                 * A malformed persisted rule should not prevent normal
                 * routing for the entire site.
                 */
                continue;
            }
        }

        return null;
    }

    private function isSameDestination(UriInterface $currentUri, string $targetUrl): bool
    {
        $currentHost = $this->matcher->normalizeHost($currentUri->getHost());

        $targetHost = $currentHost;

        if (
            str_starts_with(
                strtolower($targetUrl),
                'http://'
            )
            || str_starts_with(
                strtolower($targetUrl),
                'https://'
            )
        ) {
            $targetHost = $this->matcher->normalizeHost(
                (string) parse_url(
                    $targetUrl,
                    PHP_URL_HOST
                )
            );
        }

        if ($targetHost !== $currentHost) {
            return false;
        }

        $currentPath = $this->matcher->normalizeSourcePattern(
            $currentUri->getPath()
        );

        $targetPath = parse_url(
            $targetUrl,
            PHP_URL_PATH
        );

        $targetPath = $this->matcher->normalizeSourcePattern(
            trim((string) $targetPath) !== ''
                ? (string) $targetPath
                : '/'
        );

        return $currentPath === $targetPath;
    }
}
