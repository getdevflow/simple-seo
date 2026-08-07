<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Routing;

use InvalidArgumentException;

use function array_unique;
use function array_values;
use function explode;
use function parse_url;
use function preg_match;
use function preg_quote;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strpos;
use function strtolower;
use function substr_count;
use function trim;

use const PHP_URL_HOST;

final class RedirectRuleMatcher
{
    public function matchesHost(
        object $redirect,
        string $requestHost
    ): bool {
        $sourceHost = $this->normalizeHost(
            (string) ($redirect->source_host ?? '')
        );

        if ($sourceHost === '') {
            return true;
        }

        return $sourceHost === $this->normalizeHost($requestHost);
    }

    public function matchPath(
        object $redirect,
        string $requestPath
    ): ?string {
        $requestPath = $this->normalizeRequestPath($requestPath);

        if ($this->isExcluded($redirect, $requestPath)) {
            return null;
        }

        $source = $this->normalizeSourcePattern(
            (string) ($redirect->source_url ?? '/')
        );

        $matchType = strtolower(
            trim((string) ($redirect->match_type ?? 'exact'))
        );

        if (
            $matchType !== 'wildcard'
            && !str_contains($source, '*')
        ) {
            return $requestPath === $source
                ? ''
                : null;
        }

        return $this->matchWildcard(
            pattern: $source,
            requestPath: $requestPath
        );
    }

    public function buildTarget(
        object $redirect,
        string $capturedPath,
        string $queryString = ''
    ): string {
        $target = trim((string) ($redirect->target_url ?? ''));

        if ($target === '') {
            throw new InvalidArgumentException(
                'Redirect target cannot be empty.'
            );
        }

        $capturedPath = trim($capturedPath, '/');

        $target = str_replace(
            ['{path}', '$1'],
            $capturedPath,
            $target
        );

        /*
         * If the rule contains a wildcard but the target has no explicit
         * placeholder, append the captured path for convenience.
         */
        $source = (string) ($redirect->source_url ?? '');

        if (
            str_contains($source, '*')
            && !str_contains(
                (string) ($redirect->target_url ?? ''),
                '{path}'
            )
            && !str_contains(
                (string) ($redirect->target_url ?? ''),
                '$1'
            )
            && $capturedPath !== ''
        ) {
            $target = rtrim($target, '/')
                . '/'
                . $capturedPath;
        }

        if (
            (int) ($redirect->preserve_query ?? 1) === 1
            && $queryString !== ''
        ) {
            $target = $this->appendQueryString(
                target: $target,
                queryString: $queryString
            );
        }

        if (
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $target
            ) === 1
        ) {
            throw new InvalidArgumentException(
                'The resolved redirect URL contains invalid characters.'
            );
        }

        return $target;
    }

    public function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return '';
        }

        if (str_contains($host, '://')) {
            $host = strtolower(
                (string) parse_url($host, PHP_URL_HOST)
            );
        }

        $host = rtrim($host, '.');

        /*
         * IPv6 and host:port handling can be added separately if Devflow
         * allows those values in the admin form.
         */
        if (
            preg_match(
                '/^(?=.{1,191}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
                $host
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The redirect source host is invalid.'
            );
        }

        return $host;
    }

    public function normalizeSourcePattern(string $path): string
    {
        $path = trim($path);

        if (
            str_contains($path, '*')
            && (
                !str_ends_with($path, '*')
                || substr_count($path, '*') !== 1
            )
        ) {
            throw new InvalidArgumentException(
                'A wildcard may only appear once at the end of a source path.'
            );
        }

        if ($path === '') {
            return '/';
        }

        /*
         * Permit a full source URL in the UI, but persist only its path.
         * The host should be stored in source_host.
         */
        if (str_contains($path, '://')) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsedPath)
                ? $parsedPath
                : '/';
        }

        $wildcard = str_ends_with($path, '*');

        if ($wildcard) {
            $path = rtrim(
                substr($path, 0, -1),
                '/'
            );
        }

        $path = '/' . trim($path, '/');

        if ($path === '') {
            $path = '/';
        }

        if ($wildcard) {
            return $path === '/'
                ? '/*'
                : $path . '/*';
        }

        return $path === '/'
            ? '/'
            : $path . '/';
    }

    /**
     * @return list<string>
     */
    public function normalizeExcludedPaths(string|array|null $excludedPaths): array
    {
        if (is_array($excludedPaths)) {
            $lines = $excludedPaths;
        } else {
            $lines = explode(
                "\n",
                str_replace(
                    ["\r\n", "\r"],
                    "\n",
                    (string) $excludedPaths
                )
            );
        }

        $normalized = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            $normalized[] = $this->normalizeSourcePattern(
                $line
            );
        }

        return array_values(
            array_unique($normalized)
        );
    }

    private function isExcluded(
        object $redirect,
        string $requestPath
    ): bool {
        $excludedPaths = $this->normalizeExcludedPaths(
            (string) ($redirect->excluded_paths ?? '')
        );

        foreach ($excludedPaths as $pattern) {
            if (str_contains($pattern, '*')) {
                if (
                    $this->matchWildcard(
                        pattern: $pattern,
                        requestPath: $requestPath
                    ) !== null
                ) {
                    return true;
                }

                continue;
            }

            if ($requestPath === $pattern) {
                return true;
            }
        }

        return false;
    }

    private function matchWildcard(
        string $pattern,
        string $requestPath
    ): ?string {
        $quoted = preg_quote($pattern, '#');

        /*
         * The wildcard captures the remainder of the path, without its
         * leading slash.
         */
        $regex = '#^'
            . str_replace(
                '\*',
                '(?P<path>.*)',
                $quoted
            )
            . '$#u';

        if (
            preg_match(
                $regex,
                $requestPath,
                $matches
            ) !== 1
        ) {
            return null;
        }

        return trim(
            (string) ($matches['path'] ?? ''),
            '/'
        );
    }

    private function normalizeRequestPath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        if (
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $path
            ) === 1
        ) {
            throw new InvalidArgumentException(
                'The request path contains invalid characters.'
            );
        }

        $path = '/' . trim($path, '/');

        return $path === '/'
            ? '/'
            : $path . '/';
    }

    private function appendQueryString(
        string $target,
        string $queryString
    ): string {
        if ($queryString === '') {
            return $target;
        }

        $fragment = '';

        $fragmentPosition = strpos(
            $target,
            '#'
        );

        if ($fragmentPosition !== false) {
            $fragment = substr(
                $target,
                $fragmentPosition
            );

            $target = substr(
                $target,
                0,
                $fragmentPosition
            );
        }

        $separator = str_contains($target, '?')
            ? '&'
            : '?';

        return $target
            . $separator
            . $queryString
            . $fragment;
    }
}
