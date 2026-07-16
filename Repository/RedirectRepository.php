<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Repository;

use App\Application\Devflow;
use Qubus\Expressive\Database;
use Qubus\ValueObjects\Identity\Ulid;

use function date;
use function Qubus\Security\Helpers\t__;
use function sprintf;
use function trim;

final readonly class RedirectRepository
{
    public function __construct(private Database $dfdb)
    {
    }

    public function findById(int|string $id): object|false
    {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT * FROM {$this->table()} WHERE id = ? LIMIT 1",
                [$id]
            )
        ) ?: false;
    }

    public function updateById(
        int|string $id,
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        bool $enabled = true
    ): void {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                SET source_url = ?,
                target_url = ?,
                status_code = ?,
                enabled = ?,
                updated_at = ?
                WHERE id = ?",
                [
                    $this->normalizePath($sourceUrl),
                    $this->normalizeTarget($targetUrl),
                    $statusCode,
                    $enabled ? 1 : 0,
                    date('Y-m-d H:i:s'),
                    $id,
                ]
            )
        );
    }

    public function findBySourceUrl(string $sourceUrl): object|false
    {
        $sourceUrl = $this->normalizePath($sourceUrl);

        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
                 FROM {$this->table()}
                 WHERE source_url = ?
                 AND enabled = 1
                 LIMIT 1",
                [$sourceUrl]
            )
        ) ?: false;
    }

    public function createOrUpdate(
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        ?string $entityType = null,
        ?string $entityId = null,
        bool $enabled = true
    ): void {
        $sourceUrl = $this->normalizePath($sourceUrl);
        $targetUrl = $this->normalizeTarget($targetUrl);
        $now = date('Y-m-d H:i:s');

        $existing = $this->findBySourceUrlIncludingDisabled($sourceUrl);

        if ($existing !== false) {
            $this->dfdb->query(
                $this->dfdb->prepare(
                    "UPDATE {$this->table()}
                     SET target_url = ?,
                         status_code = ?,
                         entity_type = ?,
                         entity_id = ?,
                         enabled = ?,
                         updated_at = ?
                     WHERE id = ?",
                    [
                        $targetUrl,
                        $statusCode,
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
                "INSERT INTO {$this->table()}
                 (id, source_url, target_url, status_code, entity_type, entity_id, hits, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)",
                [
                    Ulid::generateAsString(),
                    $sourceUrl,
                    $targetUrl,
                    $statusCode,
                    $entityType,
                    $entityId,
                    $enabled ? 1 : 0,
                    $now,
                    $now,
                ]
            )
        );
    }

    public function disableByEntity(string $entityType, string $entityId): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET enabled = 0,
                     updated_at = ?
                 WHERE entity_type = ?
                 AND entity_id = ?",
                [date('Y-m-d H:i:s'), $entityType, $entityId]
            )
        );
    }

    public function delete(int|string $id): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "DELETE FROM {$this->table()} WHERE id = ?",
                [$id]
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
                [date('Y-m-d H:i:s'), $id]
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

        return is_array($rows) ? $rows : [];
    }

    private function findBySourceUrlIncludingDisabled(string $sourceUrl): object|false
    {
        $sourceUrl = $this->normalizePath($sourceUrl);

        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
                 FROM {$this->table()}
                 WHERE source_url = ?
                 LIMIT 1",
                [$sourceUrl]
            )
        ) ?: false;
    }

    public function findByEntity(string $entityType, string $entityId): object|false
    {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
             FROM {$this->table()}
             WHERE entity_type = ?
             AND entity_id = ?
             LIMIT 1",
                [$entityType, $entityId]
            )
        ) ?: false;
    }

    public function createOrUpdateForEntity(
        string $entityType,
        string $entityId,
        string $sourceUrl,
        string $targetUrl,
        int $statusCode = 301,
        bool $enabled = true
    ): void {
        $existing = $this->findByEntity($entityType, $entityId);
        $ignoreId = $existing !== false ? $existing->id : null;

        if ($this->wouldCreateLoop($sourceUrl, $targetUrl, $ignoreId)) {
            Devflow::$PHP->flash->error(
                t__(
                    'Redirect loop detected. Source and target cannot redirect back to each other or to themselves.',
                    'simple-seo'
                )
            );
            return;
        }

        if ($this->sourceExistsForDifferentRedirect($sourceUrl, $ignoreId)) {
            Devflow::$PHP->flash->error(
                t__('A redirect already exists for this source URL.', 'simple-seo')
            );
            return;
        }

        if ($existing !== false) {
            $this->updateById(
                id: $existing->id,
                sourceUrl: $sourceUrl,
                targetUrl: $targetUrl,
                statusCode: $statusCode,
                enabled: $enabled
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

    public function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        $path = parse_url($path, PHP_URL_PATH) ?: $path;

        return '/' . trim($path, '/') . '/';
    }

    public function setEnabled(int|string $id, bool $enabled): void
    {
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

    public function normalizeTarget(string $targetUrl): string
    {
        $targetUrl = trim($targetUrl);

        if ($targetUrl === '') {
            return '/';
        }

        $scheme = parse_url($targetUrl, PHP_URL_SCHEME);

        if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new \InvalidArgumentException(
                t__(
                    'Invalid redirect URL scheme.',
                    'simple-seo'
                )
            );
        }

        if (str_starts_with($targetUrl, 'http://') || str_starts_with($targetUrl, 'https://')) {
            return $targetUrl;
        }

        if (str_starts_with($targetUrl, '//')) {
            throw new \InvalidArgumentException(
                t__(
                    'Protocol-relative redirects are not allowed.',
                    'simple-seo'
                )
            );
        }

        return '/' . trim($targetUrl, '/') . '/';
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

        if (in_array($filter, ['content', 'product', 'page'], true)) {
            $where[] = 'entity_type = ?';
            $params[] = $filter;
        }

        if ($search !== '') {
            $where[] = '(source_url LIKE ? OR target_url LIKE ? OR entity_type LIKE ? OR entity_id LIKE ?)';
            $like = '%' . $search . '%';

            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = $where !== []
            ? ' WHERE ' . implode(' AND ', $where)
            : '';

        $countSql = "SELECT COUNT(*) FROM {$this->table()}{$whereSql}";

        $total = (int) (
        $params !== []
            ? $this->dfdb->getVar($this->dfdb->prepare($countSql, $params))
            : $this->dfdb->getVar($countSql)
        );

        $listSql = "SELECT *
        FROM {$this->table()}
        {$whereSql}
        ORDER BY updated_at DESC
        LIMIT {$perPage} OFFSET {$offset}";

        $rows = $params !== []
            ? $this->dfdb->getResults($this->dfdb->prepare($listSql, $params))
            : $this->dfdb->getResults($listSql);

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function countByFilter(string $filter = 'all'): int
    {
        return match ($filter) {
            'enabled' => (int) $this->dfdb->getVar("SELECT COUNT(*) FROM {$this->table()} WHERE enabled = 1"),
            'disabled' => (int) $this->dfdb->getVar("SELECT COUNT(*) FROM {$this->table()} WHERE enabled = 0"),
            'manual' => (int) $this->dfdb->getVar("SELECT COUNT(*) FROM {$this->table()} WHERE entity_type IS NULL OR entity_type = ''"),
            'content', 'product', 'page' => (int) $this->dfdb->getVar(
                $this->dfdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table()} WHERE entity_type = ?",
                    [$filter]
                )
            ),
            default => (int) $this->dfdb->getVar("SELECT COUNT(*) FROM {$this->table()}"),
        };
    }

    public function wouldCreateLoop(
        string $sourceUrl,
        string $targetUrl,
        int|string|null $ignoreId = null
    ): bool {
        $source = $this->normalizePath($sourceUrl);
        $target = $this->normalizePath($targetUrl);

        if ($source === $target) {
            return true;
        }

        $seen = [$source];
        $current = $target;

        for ($i = 0; $i < 10; $i++) {
            if (in_array($current, $seen, true)) {
                return true;
            }

            $seen[] = $current;

            $redirect = $this->findBySourceUrlIncludingDisabled($current);

            if ($redirect === false) {
                return false;
            }

            if ($ignoreId !== null && (string) $redirect->id === (string) $ignoreId) {
                return false;
            }

            $current = $this->normalizePath((string) $redirect->target_url);
        }

        return true;
    }

    public function sourceExistsForDifferentRedirect(string $sourceUrl, int|string|null $ignoreId = null): bool
    {
        $redirect = $this->findBySourceUrlIncludingDisabled($sourceUrl);

        if ($redirect === false) {
            return false;
        }

        return $ignoreId === null || (string) $redirect->id !== (string) $ignoreId;
    }

    public function findChainTarget(string $targetUrl, int|string|null $ignoreId = null): object|false
    {
        $target = $this->normalizePath($targetUrl);

        $redirect = $this->findBySourceUrlIncludingDisabled($target);

        if ($redirect === false) {
            return false;
        }

        if ($ignoreId !== null && (string) $redirect->id === (string) $ignoreId) {
            return false;
        }

        return $redirect;
    }

    public function chainWarningFor(object $redirect): ?array
    {
        $target = (string) ($redirect->target_url ?? '');

        if ($target === '') {
            return null;
        }

        $chain = $this->findChainTarget(
            targetUrl: $target,
            ignoreId: $redirect->id ?? null
        );

        if ($chain === false) {
            return null;
        }

        return [
            'next_source' => (string) $chain->source_url,
            'next_target' => (string) $chain->target_url,
            'message' => t__(
                sprintf(
                    'Redirect chain detected. Consider redirecting directly to %s.',
                    (string) $chain->target_url
                ),
                'simple-seo'
            ),
        ];
    }

    public function chainWarnings(array $redirects): array
    {
        $warnings = [];

        foreach ($redirects as $redirect) {
            $id = (string) ($redirect->id ?? '');

            if ($id === '') {
                continue;
            }

            $warning = $this->chainWarningFor($redirect);

            if ($warning !== null) {
                $warnings[$id] = $warning;
            }
        }

        return $warnings;
    }

    private function table(): string
    {
        return $this->dfdb->prefix . 'seo_redirect';
    }
}
