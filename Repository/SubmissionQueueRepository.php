<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Repository;

use Qubus\Expressive\Database;
use Qubus\ValueObjects\Identity\Ulid;

use function date;

final readonly class SubmissionQueueRepository
{
    public function __construct(private Database $dfdb)
    {
    }

    public function enqueue(
        string $url,
        string $engine = 'indexnow',
        ?string $entityType = null,
        ?string $entityId = null
    ): void {
        $url = trim($url);
        $entityType = $this->normalizeNullableString($entityType);
        $entityId = $this->normalizeNullableString($entityId);

        if ($url === '') {
            return;
        }

        $engine = in_array($engine, ['indexnow', 'google', 'both'], true)
            ? $engine
            : 'indexnow';

        $existing = $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT id FROM {$this->table()}
                 WHERE url = ?
                 AND engine = ?
                 AND status IN('pending','processing')
                 LIMIT 1",
                [$url, $engine]
            )
        );

        if ($existing !== false && $existing !== null) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->dfdb->query(
            $this->dfdb->prepare(
                "INSERT INTO {$this->table()}
                 (id, url, engine, entity_type, entity_id, status, attempts, last_error, created_at, updated_at, processed_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', 0, NULL, ?, ?, NULL)",
                [
                    Ulid::generateAsString(),
                    $url,
                    $engine,
                    $entityType,
                    $entityId,
                    $now,
                    $now,
                ]
            )
        );
    }

    /**
     * @return list<object>
     */
    public function pending(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        $rows = $this->dfdb->getResults(
            "SELECT *
             FROM {$this->table()}
             WHERE status = 'pending'
             ORDER BY created_at ASC
             LIMIT {$limit}"
        );

        return is_array($rows) ? $rows : [];
    }

    public function markProcessing(string $id): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET status = 'processing',
                     attempts = attempts + 1,
                     updated_at = ?
                 WHERE id = ? 
                 AND status = 'pending'",
                [date('Y-m-d H:i:s'), $id]
            )
        );
    }

    public function markDone(string $id): void
    {
        $now = date('Y-m-d H:i:s');

        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET status = 'done',
                     last_error = NULL,
                     updated_at = ?,
                     processed_at = ?
                 WHERE id = ?",
                [$now, $now, $id]
            )
        );
    }

    public function markFailed(string $id, string $error): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "UPDATE {$this->table()}
                 SET status = CASE
                        WHEN attempts >= 3 THEN 'failed'
                        ELSE 'pending'
                     END,
                     last_error = ?,
                     updated_at = ?
                 WHERE id = ?",
                [
                    mb_substr($error, 0, 1000),
                    date('Y-m-d H:i:s'),
                    $id,
                ]
            )
        );
    }

    public function delete(string $id): void
    {
        $this->dfdb->query(
            $this->dfdb->prepare(
                "DELETE FROM {$this->table()}
                WHERE id = ?",
                [$id]
            )
        );
    }

    public function deletePendingForEntity(
        string $entityType,
        string $entityId
    ): void {
        $entityType = trim($entityType);
        $entityId = trim($entityId);

        if ($entityType === '' || $entityId === '') {
            return;
        }

        $this->dfdb->query(
            $this->dfdb->prepare(
                "DELETE FROM {$this->table()}
                WHERE entity_type = ?
                AND entity_id = ?
                AND status IN ('pending', 'processing')",
                [
                    $entityType,
                    $entityId,
                ]
            )
        );
    }

    public function countPending(): int
    {
        return (int) $this->dfdb->getVar(
            "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'pending'"
        );
    }

    private function normalizeNullableString(?string $value = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function table(): string
    {
        return $this->dfdb->prefix . 'seo_submission_queue';
    }
}
