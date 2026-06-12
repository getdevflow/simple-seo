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

    public function enqueue(string $url, string $engine = 'indexnow'): void
    {
        $url = trim($url);

        if ($url === '') {
            return;
        }

        $engine = in_array($engine, ['indexnow', 'google', 'both'], true)
            ? $engine
            : 'indexnow';

        $existing = $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE url = ?
                 AND engine = ?
                 AND status = 'pending'
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
                 (id, url, engine, status, attempts, last_error, created_at, updated_at, processed_at)
                 VALUES (?, ?, ?, 'pending', 0, NULL, ?, ?, NULL)",
                [
                    Ulid::generateAsString(),
                    $url,
                    $engine,
                    $now,
                    $now,
                ]
            )
        );
    }

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
                 WHERE id = ?",
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

    public function countPending(): int
    {
        return (int) $this->dfdb->getVar(
            "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'pending'"
        );
    }

    private function table(): string
    {
        return $this->dfdb->prefix . 'seo_submission_queue';
    }
}
