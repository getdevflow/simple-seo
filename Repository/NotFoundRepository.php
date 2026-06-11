<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Repository;

use Qubus\Expressive\Database;
use Qubus\ValueObjects\Identity\Ulid;

use function date;
use function parse_url;
use function trim;

use const PHP_URL_PATH;

final readonly class NotFoundRepository
{
    public function __construct(private Database $dfdb)
    {
    }

    public function record(
        string $requestPath,
        ?string $referrer = null,
        ?string $userAgent = null
    ): void {
        $requestPath = $this->normalizePath($requestPath);

        if ($requestPath === '/') {
            return;
        }

        $existing = $this->findByPath($requestPath);
        $now = date('Y-m-d H:i:s');

        if ($existing !== false) {
            $this->dfdb->query(
                $this->dfdb->prepare(
                    "UPDATE {$this->table()}
                     SET hits = hits + 1,
                         referrer = ?,
                         user_agent = ?,
                         last_seen = ?
                     WHERE id = ?",
                    [
                        $referrer,
                        $userAgent,
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
                 (id, request_path, referrer, user_agent, hits, ignored, first_seen, last_seen)
                 VALUES (?, ?, ?, ?, 1, 0, ?, ?)",
                [
                    Ulid::generateAsString(),
                    $requestPath,
                    $referrer,
                    $userAgent,
                    $now,
                    $now,
                ]
            )
        );
    }

    public function findByPath(string $requestPath): object|false
    {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT *
                 FROM {$this->table()}
                 WHERE request_path = ?
                 LIMIT 1",
                [$this->normalizePath($requestPath)]
            )
        ) ?: false;
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

    public function all(bool $includeIgnored = false): array
    {
        $sql = "SELECT * FROM {$this->table()}";

        if (!$includeIgnored) {
            $sql .= " WHERE ignored = 0";
        }

        $sql .= " ORDER BY last_seen DESC";

        $rows = $this->dfdb->getResults($sql);

        return is_array($rows) ? $rows : [];
    }

    public function ignore(int|string $id): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET ignored = 1
                 WHERE id = ?",
                [$id]
            )
        );
    }

    public function unignore(int|string $id): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET ignored = 0
                 WHERE id = ?",
                [$id]
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

    public function deleteByPath(string $requestPath): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "DELETE FROM {$this->table()} WHERE request_path = ?",
                [$this->normalizePath($requestPath)]
            )
        );
    }

    public function count(bool $includeIgnored = false): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table()}";

        if (!$includeIgnored) {
            $sql .= " WHERE ignored = 0";
        }

        return (int) $this->dfdb->getVar($sql);
    }

    public function hitCount(): int
    {
        return (int) $this->dfdb->getVar(
            "SELECT COALESCE(SUM(hits), 0) FROM {$this->table()}"
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

    public function active(): array
    {
        return $this->all(false);
    }

    public function ignored(): array
    {
        $rows = $this->dfdb->getResults(
            "SELECT *
         FROM {$this->table()}
         WHERE ignored = 1
         ORDER BY last_seen DESC"
        );

        return is_array($rows) ? $rows : [];
    }

    public function activeCount(): int
    {
        return (int) $this->dfdb->getVar(
            "SELECT COUNT(*) FROM {$this->table()} WHERE ignored = 0"
        );
    }

    public function ignoredCount(): int
    {
        return (int) $this->dfdb->getVar(
            "SELECT COUNT(*) FROM {$this->table()} WHERE ignored = 1"
        );
    }

    public function paginated(
        string $filter = 'active',
        string $search = '',
        int $page = 1,
        int $perPage = 25
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($filter === 'active') {
            $where[] = 'ignored = 0';
        }

        if ($filter === 'ignored') {
            $where[] = 'ignored = 1';
        }

        if ($search !== '') {
            $where[] = '(request_path LIKE ? OR referrer LIKE ? OR user_agent LIKE ?)';
            $like = '%' . $search . '%';
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
        ORDER BY last_seen DESC
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

    private function table(): string
    {
        return $this->dfdb->prefix . 'seo_404_log';
    }
}
