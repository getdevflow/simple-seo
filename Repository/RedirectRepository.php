<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Repository;

use InvalidArgumentException;
use Plugin\SimpleSeo\Routing\RedirectRuleMatcher;
use Qubus\Expressive\Database;
use Qubus\ValueObjects\Identity\Ulid;

use function date;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function min;
use function str_contains;
use function str_starts_with;
use function trim;

use const PHP_URL_HOST;
use const PHP_URL_PASS;
use const PHP_URL_SCHEME;
use const PHP_URL_USER;

final readonly class RedirectRepository
{
    public function __construct(
        private Database $dfdb,
        private RedirectRuleMatcher $matcher
    ) {
    }

    public function findById(int|string $id): object|false
    {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
                 FROM {$this->table()}
                 WHERE id = ?
                 LIMIT 1",
                [$id]
            )
        ) ?: false;
    }

    /**
     * Returns enabled rules that could apply to the request host.
     *
     * Host-specific rules are evaluated before host-less rules, and exact
     * rules before wildcard rules.
     *
     * @return list<object>
     */
    public function candidatesForHost(string $host): array
    {
        $host = $this->matcher->normalizeHost($host);

        $rows = $this->dfdb->getResults(
            $this->dfdb->prepare(
                "SELECT *
                 FROM {$this->table()}
                 WHERE enabled = 1
                 AND (
                    source_host = ?
                    OR source_host IS NULL
                    OR source_host = ''
                 )
                 ORDER BY
                    CASE
                        WHEN source_host = ? THEN 0
                        ELSE 1
                    END ASC,
                    CASE
                        WHEN match_type = 'exact' THEN 0
                        ELSE 1
                    END ASC,
                    LENGTH(source_url) DESC,
                    updated_at DESC",
                [
                    $host,
                    $host,
                ]
            )
        );

        return is_array($rows)
            ? $rows
            : [];
    }

    public function updateById(
        int|string $id,
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        bool $enabled = true,
        ?string $sourceHost = null,
        string|array|null $excludedPaths = null,
        bool $preserveQuery = true
    ): void {
        $rule = $this->normalizeRule(
            sourceUrl: $sourceUrl,
            targetUrl: $targetUrl,
            sourceHost: $sourceHost,
            excludedPaths: $excludedPaths,
            statusCode: $statusCode
        );

        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET source_host = ?,
                     source_url = ?,
                     target_url = ?,
                     match_type = ?,
                     excluded_paths = ?,
                     preserve_query = ?,
                     status_code = ?,
                     enabled = ?,
                     updated_at = ?
                 WHERE id = ?",
                [
                    $rule['source_host'],
                    $rule['source_url'],
                    $rule['target_url'],
                    $rule['match_type'],
                    $rule['excluded_paths'],
                    $preserveQuery ? 1 : 0,
                    $rule['status_code'],
                    $enabled ? 1 : 0,
                    date('Y-m-d H:i:s'),
                    $id,
                ]
            )
        );
    }

    public function createOrUpdate(
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        ?string $entityType = null,
        ?string $entityId = null,
        bool $enabled = true,
        ?string $sourceHost = null,
        string|array|null $excludedPaths = null,
        bool $preserveQuery = true
    ): void {
        $rule = $this->normalizeRule(
            sourceUrl: $sourceUrl,
            targetUrl: $targetUrl,
            sourceHost: $sourceHost,
            excludedPaths: $excludedPaths,
            statusCode: $statusCode
        );

        $now = date('Y-m-d H:i:s');

        $existing = $this->findByRuleIncludingDisabled(
            sourceHost: $rule['source_host'],
            sourceUrl: $rule['source_url'],
            matchType: $rule['match_type']
        );

        if ($existing !== false) {
            $this->dfdb->query(
                $this->dfdb->prepare(
                    "UPDATE {$this->table()}
                     SET target_url = ?,
                         excluded_paths = ?,
                         preserve_query = ?,
                         status_code = ?,
                         entity_type = ?,
                         entity_id = ?,
                         enabled = ?,
                         updated_at = ?
                     WHERE id = ?",
                    [
                        $rule['target_url'],
                        $rule['excluded_paths'],
                        $preserveQuery ? 1 : 0,
                        $rule['status_code'],
                        $entityType,
                        $entityId,
                        $enabled ? 1 : 0,
                        $now,
                        $existing->id,
                    ]
                )
            );

            return;
        }

        $this->dfdb->query(
            $this->dfdb->prepare(
                "INSERT INTO {$this->table()} (
                    id,
                    source_host,
                    source_url,
                    target_url,
                    match_type,
                    excluded_paths,
                    preserve_query,
                    status_code,
                    entity_type,
                    entity_id,
                    hits,
                    enabled,
                    created_at,
                    updated_at
                 ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    0,
                    ?,
                    ?,
                    ?
                 )",
                [
                    Ulid::generateAsString(),
                    $rule['source_host'],
                    $rule['source_url'],
                    $rule['target_url'],
                    $rule['match_type'],
                    $rule['excluded_paths'],
                    $preserveQuery ? 1 : 0,
                    $rule['status_code'],
                    $entityType,
                    $entityId,
                    $enabled ? 1 : 0,
                    $now,
                    $now,
                ]
            )
        );
    }

    public function createOrUpdateForEntity(
        string $entityType,
        string $entityId,
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        bool $enabled = true
    ): void {
        $existing = $this->findByEntity(
            $entityType,
            $entityId
        );

        if ($existing !== false) {
            /*
             * Entity redirects remain host-less, exact rules.
             */
            $this->updateById(
                id: $existing->id,
                sourceUrl: $sourceUrl,
                targetUrl: $targetUrl,
                statusCode: $statusCode,
                enabled: $enabled,
                sourceHost: null,
                excludedPaths: null,
                preserveQuery: true
            );

            return;
        }

        $this->createOrUpdate(
            sourceUrl: $sourceUrl,
            targetUrl: $targetUrl,
            statusCode: $statusCode,
            entityType: $entityType,
            entityId: $entityId,
            enabled: $enabled
        );
    }

    public function findByEntity(
        string $entityType,
        string $entityId
    ): object|false {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
                 FROM {$this->table()}
                 WHERE entity_type = ?
                 AND entity_id = ?
                 LIMIT 1",
                [
                    $entityType,
                    $entityId,
                ]
            )
        ) ?: false;
    }

    public function disableByEntity(
        string $entityType,
        string $entityId
    ): void {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET enabled = 0,
                     updated_at = ?
                 WHERE entity_type = ?
                 AND entity_id = ?",
                [
                    date('Y-m-d H:i:s'),
                    $entityType,
                    $entityId,
                ]
            )
        );
    }

    public function incrementHits(int|string $id): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET hits = hits + 1,
                     updated_at = ?
                 WHERE id = ?",
                [
                    date('Y-m-d H:i:s'),
                    $id,
                ]
            )
        );
    }

    public function setEnabled(
        int|string $id,
        bool $enabled
    ): void {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET enabled = ?,
                     updated_at = ?
                 WHERE id = ?",
                [
                    $enabled ? 1 : 0,
                    date('Y-m-d H:i:s'),
                    $id,
                ]
            )
        );
    }

    public function delete(int|string $id): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "DELETE FROM {$this->table()}
                 WHERE id = ?",
                [$id]
            )
        );
    }

    public function all(): array
    {
        $rows = $this->dfdb->getResults(
            "SELECT *
             FROM {$this->table()}
             ORDER BY updated_at DESC"
        );

        return is_array($rows)
        ? $rows
        : [];
    }

    public function paginated(
        string $filter = 'all',
        string $search = '',
        int $page = 1,
        int $perPage = 25
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($filter === 'enabled') {
            $where[] = 'enabled = 1';
        }

        if ($filter === 'disabled') {
            $where[] = 'enabled = 0';
        }

        if ($filter === 'manual') {
            $where[] = "(entity_type IS NULL OR entity_type = '')";
        }

        if ($filter === 'host') {
            $where[] = "(source_host IS NOT NULL AND source_host <> '')";
        }

        if ($filter === 'wildcard') {
            $where[] = "match_type = 'wildcard'";
        }

        if (
            in_array(
                $filter,
                ['content', 'product', 'page'],
                true
            )
        ) {
            $where[] = 'entity_type = ?';
            $params[] = $filter;
        }

        if ($search !== '') {
            $where[] = '(
                source_host LIKE ?
                OR source_url LIKE ?
                OR target_url LIKE ?
                OR excluded_paths LIKE ?
                OR entity_type LIKE ?
                OR entity_id LIKE ?
            )';

            $like = '%' . $search . '%';

            for ($i = 0; $i < 6; $i++) {
                $params[] = $like;
            }
        }

        $whereSql = $where !== []
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

        $countSql = "SELECT COUNT(*)
                     FROM {$this->table()}
                     {$whereSql}";

        $total = (int) (
        $params !== []
            ? $this->dfdb->getVar(
                $this->dfdb->prepare(
                    $countSql,
                    $params
                )
            )
            : $this->dfdb->getVar($countSql)
        );

        $listSql = "SELECT *
                    FROM {$this->table()}
                    {$whereSql}
                    ORDER BY updated_at DESC
                    LIMIT {$perPage}
                    OFFSET {$offset}";

        $rows = $params !== []
            ? $this->dfdb->getResults(
                $this->dfdb->prepare(
                    $listSql,
                    $params
                )
            )
            : $this->dfdb->getResults($listSql);

        return [
            'items' => is_array($rows)
                ? $rows
                : [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(
                1,
                (int) ceil($total / $perPage)
            ),
        ];
    }

    public function countByFilter(string $filter = 'all'): int
    {
        return match ($filter) {
            'enabled' => (int) $this->dfdb->getVar(
                "SELECT COUNT(*)
                 FROM {$this->table()}
                 WHERE enabled = 1"
            ),

            'disabled' => (int) $this->dfdb->getVar(
                "SELECT COUNT(*)
                 FROM {$this->table()}
                 WHERE enabled = 0"
            ),

            'manual' => (int) $this->dfdb->getVar(
                "SELECT COUNT(*)
                 FROM {$this->table()}
                 WHERE entity_type IS NULL
                 OR entity_type = ''"
            ),

            'host' => (int) $this->dfdb->getVar(
                "SELECT COUNT(*)
                 FROM {$this->table()}
                 WHERE source_host IS NOT NULL
                 AND source_host <> ''"
            ),

            'wildcard' => (int) $this->dfdb->getVar(
                "SELECT COUNT(*)
                 FROM {$this->table()}
                 WHERE match_type = 'wildcard'"
            ),

            'content', 'product', 'page' =>
            (int) $this->dfdb->getVar(
                $this->dfdb->prepare(
                    "SELECT COUNT(*)
                         FROM {$this->table()}
                         WHERE entity_type = ?",
                    [$filter]
                )
            ),

            default => (int) $this->dfdb->getVar(
                "SELECT COUNT(*)
                 FROM {$this->table()}"
            ),
        };
    }

    public function wouldCreateLoop(
        string $sourceUrl,
        string $targetUrl,
        int|string|null $ignoreId = null,
        ?string $sourceHost = null
    ): bool {
        $source = $this->matcher->normalizeSourcePattern(
            $sourceUrl
        );

        $normalizedSourceHost = trim(
            (string) $sourceHost
        );

        if ($normalizedSourceHost !== '') {
            $normalizedSourceHost = $this->matcher->normalizeHost(
                $normalizedSourceHost
            );
        }

        $targetScheme = parse_url(
            $targetUrl,
            PHP_URL_SCHEME
        );

        $targetHost = '';

        if ($targetScheme !== null) {
            $targetHost = $this->matcher->normalizeHost(
                (string) parse_url(
                    $targetUrl,
                    PHP_URL_HOST
                )
            );

            /*
             * A host-specific rule cannot loop into a completely
             * different host.
             */
            if (
                $normalizedSourceHost !== ''
                && $targetHost !== $normalizedSourceHost
            ) {
                return false;
            }

            /*
             * A host-less rule applies to whatever current site host
             * handles the request. An absolute URL to another host cannot
             * be conclusively called a loop at save time.
             *
             * RedirectResolver performs the definitive runtime check.
             */
            if (
                $normalizedSourceHost === ''
                && $targetHost !== ''
            ) {
                return false;
            }
        }

        $targetPath = parse_url(
            $targetUrl,
            PHP_URL_PATH
        );

        $targetPath = $this->matcher->normalizeSourcePattern(
            trim((string) $targetPath) !== ''
                    ? (string) $targetPath
                    : '/'
        );

        /*
         * Exact redirect to the same path.
         */
        if (!str_contains($source, '*')) {
            return $source === $targetPath;
        }

        /*
         * Detect the straightforward wildcard identity case:
         *
         * /*
         *   -> /{path}/
         *
         * /blog/*
         *   -> /blog/{path}/
         */
        $sourceTemplate = str_replace(
            '*',
            '{path}',
            $source
        );

        return trim($sourceTemplate, '/') === trim($targetPath, '/');
    }

    public function sourceExistsForDifferentRedirect(
        string $sourceUrl,
        int|string|null $ignoreId = null,
        ?string $sourceHost = null
    ): bool {
        $sourceUrl = $this->matcher->normalizeSourcePattern(
            $sourceUrl
        );

        $matchType = str_contains($sourceUrl, '*')
        ? 'wildcard'
        : 'exact';

        $sourceHost = $sourceHost !== null
        ? $this->matcher->normalizeHost($sourceHost)
        : '';

        $redirect = $this->findByRuleIncludingDisabled(
            sourceHost: $sourceHost,
            sourceUrl: $sourceUrl,
            matchType: $matchType
        );

        if ($redirect === false) {
            return false;
        }

        return $ignoreId === null || (string) $redirect->id !== (string) $ignoreId;
    }

    public function normalizePath(string $path): string
    {
        return $this->matcher->normalizeSourcePattern($path);
    }

    public function chainWarnings(array $redirects): array
    {
        $warnings = [];

        foreach ($redirects as $redirect) {
            if ((int) ($redirect->enabled ?? 0) !== 1) {
                continue;
            }

            if (
                    strtolower(
                        (string) ($redirect->match_type ?? 'exact')
                    ) !== 'exact'
            ) {
                /*
                 * Wildcard rules cannot reliably participate in static
                 * chain analysis because their final target depends upon
                 * the request path.
                 */
                continue;
            }

            $target = trim(
                (string) ($redirect->target_url ?? '')
            );

            if ($target === '') {
                continue;
            }

            $sourceHost = trim(
                (string) ($redirect->source_host ?? '')
            );

            $next = $this->findRedirectForTarget(
                targetUrl: $target,
                currentHost: $sourceHost
            );

            if (
                    $next === false
                    || (string) $next->id === (string) $redirect->id
            ) {
                continue;
            }

            $warnings[(string) $redirect->id] = [
                    'message' => 'This redirect points to another redirect.',
                    'next_target' => (string) $next->target_url,
            ];
        }

        return $warnings;
    }

    public function normalizeTarget(string $targetUrl): string
    {
        return $this->validateTarget($targetUrl);
    }

    private function findRedirectForTarget(
        string $targetUrl,
        string $currentHost = ''
    ): object|false {
        $targetUrl = trim($targetUrl);

        if ($targetUrl === '') {
            return false;
        }

        $targetHost = $currentHost;
        $targetPath = $targetUrl;

        if (
            str_starts_with($targetUrl, 'http://')
            || str_starts_with($targetUrl, 'https://')
        ) {
            $targetHost = (string) parse_url(
                $targetUrl,
                PHP_URL_HOST
            );

            $targetPath = (string) parse_url(
                $targetUrl,
                PHP_URL_PATH
            );
        }

        $targetHost = $targetHost !== ''
            ? $this->matcher->normalizeHost($targetHost)
            : '';

        $targetPath = $this->matcher->normalizeSourcePattern(
            $targetPath
        );

        if ($targetHost === '') {
            return $this->dfdb->getRow(
                $this->dfdb->prepare(
                    "SELECT *
                 FROM {$this->table()}
                 WHERE COALESCE(source_host, '') = ''
                 AND source_url = ?
                 AND match_type = 'exact'
                 AND enabled = 1
                 LIMIT 1",
                    [$targetPath]
                )
            ) ?: false;
        }

        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
             FROM {$this->table()}
             WHERE source_url = ?
             AND match_type = 'exact'
             AND enabled = 1
             AND (
                 source_host = ?
                 OR source_host IS NULL
                 OR source_host = ''
             )
             ORDER BY
                CASE
                    WHEN source_host = ? THEN 0
                    ELSE 1
                END ASC
             LIMIT 1",
                [
                    $targetPath,
                    $targetHost,
                    $targetHost,
                        ]
            )
        ) ?: false;
    }

    private function findByRuleIncludingDisabled(
        string $sourceHost,
        string $sourceUrl,
        string $matchType
    ): object|false {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
                 FROM {$this->table()}
                 WHERE COALESCE(source_host, '') = ?
                 AND source_url = ?
                 AND match_type = ?
                 LIMIT 1",
                [
                    $sourceHost,
                    $sourceUrl,
                    $matchType,
                ]
            )
        ) ?: false;
    }

    /**
     * @return array{
     *     source_host: string,
     *     source_url: string,
     *     target_url: string,
     *     match_type: string,
     *     excluded_paths: string,
     *     status_code: int
     * }
     */
    private function normalizeRule(
        string $sourceUrl,
        string $targetUrl,
        ?string $sourceHost = null,
        string|array|null $excludedPaths = null,
        int $statusCode = 301
    ): array {
        $sourceUrl = trim($sourceUrl);
        $sourceHost = trim((string) $sourceHost);

        /*
         * Support pasting a complete source URL into the Source URL field.
         */
        if (str_contains($sourceUrl, '://')) {
            if ($sourceHost === '') {
                $sourceHost = (string) parse_url(
                    $sourceUrl,
                    PHP_URL_HOST
                );
            }
        }

        $sourceHost = $sourceHost !== ''
            ? $this->matcher->normalizeHost($sourceHost)
            : '';

        $sourceUrl = $this->matcher->normalizeSourcePattern(
            $sourceUrl
        );

        $matchType = str_contains($sourceUrl, '*')
            ? 'wildcard'
            : 'exact';

        $targetUrl = $this->validateTarget($targetUrl);

        if (
            !in_array(
                $statusCode,
                [301, 302, 307, 308],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported redirect status code.'
            );
        }

        $excludedPaths = implode(
            "\n",
            $this->matcher->normalizeExcludedPaths(
                $excludedPaths
            )
        );

        return [
            'source_host' => $sourceHost,
            'source_url' => $sourceUrl,
            'target_url' => $targetUrl,
            'match_type' => $matchType,
            'excluded_paths' => $excludedPaths,
            'status_code' => $statusCode,
        ];
    }

    private function validateTarget(string $targetUrl): string
    {
        $targetUrl = trim($targetUrl);

        if ($targetUrl === '') {
            throw new InvalidArgumentException(
                'The redirect target cannot be empty.'
            );
        }

        if (
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $targetUrl
            ) === 1
        ) {
            throw new InvalidArgumentException(
                'The redirect target contains invalid characters.'
            );
        }

        if (str_starts_with($targetUrl, '//')) {
            throw new InvalidArgumentException(
                'Scheme-relative redirect targets are not allowed.'
            );
        }

        $scheme = parse_url(
            $targetUrl,
            PHP_URL_SCHEME
        );

        if ($scheme !== null) {
            $scheme = strtolower((string) $scheme);

            if (
                !in_array(
                    $scheme,
                    ['http', 'https'],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid redirect target scheme.'
                );
            }

            $host = parse_url(
                $targetUrl,
                PHP_URL_HOST
            );

            if (
                !is_string($host)
                || trim($host) === ''
            ) {
                throw new InvalidArgumentException(
                    'An absolute redirect target must contain a valid host.'
                );
            }

            /*
             * Prevent misleading URLs such as:
             *
             * https://trusted.example@evil.example/
             */
            if (
                    parse_url($targetUrl, PHP_URL_USER) !== null
                    || parse_url($targetUrl, PHP_URL_PASS) !== null
            ) {
                throw new InvalidArgumentException(
                    'Redirect targets containing URL credentials are not allowed.'
                );
            }

            return $targetUrl;
        }

        return '/' . trim($targetUrl, '/');
    }

    private function table(): string
    {
        return $this->dfdb->prefix . 'seo_redirect';
    }
}
