<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Repository;

use App\Application\Devflow;
use Qubus\EventDispatcher\ActionFilter\Action;
use Qubus\Expressive\Database;
use Qubus\ValueObjects\Identity\Ulid;

use function date;
use function json_decode;
use function json_encode;
use function parse_url;
use function Qubus\Security\Helpers\t__;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_PATH;

final readonly class RouteSeoRepository
{
    public function __construct(private Database $dfdb)
    {
    }

    public function all(): array
    {
        $rows = $this->dfdb->getResults(
            "SELECT * FROM {$this->table()} ORDER BY updated_at DESC"
        );

        return is_array($rows) ? $rows : [];
    }

    public function findById(string $id): object|false
    {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT * FROM {$this->table()} WHERE id = ? LIMIT 1",
                [$id]
            )
        ) ?: false;
    }

    public function findByRoute(string $route): object|false
    {
        return $this->dfdb->getRow(
            $this->dfdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE route_path = ?
                 AND enabled = 1
                 LIMIT 1",
                [$this->normalizeRoute($route)]
            )
        ) ?: false;
    }

    /**
     * @param string $routePath
     * @param string $label
     * @param array $seo
     * @param string|null $id
     * @param bool $enabled
     * @return string
     */
    public function save(
        string $routePath,
        string $label,
        array $seo,
        ?string $id = null,
        bool $enabled = true
    ): string {
        $now = date('Y-m-d H:i:s');
        $id = $id ?: Ulid::generateAsString();

        $existing = $this->findById($id);

        if ($existing !== false) {
            $this->dfdb->query(
                $this->dfdb->prepare(
                    "UPDATE {$this->table()}
                     SET route_path = ?,
                         route_label = ?,
                         seo_data = ?,
                         enabled = ?,
                         updated_at = ?
                     WHERE id = ?",
                    [
                        $this->normalizeRoute($routePath),
                        $label,
                        json_encode($seo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $enabled ? 1 : 0,
                        $now,
                        $id,
                    ]
                )
            );

            return $id;
        }

        $this->dfdb->query(
            $this->dfdb->prepare(
                "INSERT INTO {$this->table()}
                 (id, route_path, route_label, seo_data, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $id,
                    $this->normalizeRoute($routePath),
                    $label,
                    json_encode($seo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $enabled ? 1 : 0,
                    $now,
                    $now,
                ]
            )
        );

        return $id;
    }

    public function delete(string $id): void
    {
        try {
            $this->dfdb->beginTransaction();
            $this->dfdb->query(
                $this->dfdb->prepare(
                    "DELETE FROM {$this->table()} WHERE id = ?",
                    [$id]
                )
            );
            $this->dfdb->commit();

            Action::getInstance()->doAction('delete_seo_route', $id);

            Devflow::$PHP->flash->success(Devflow::$PHP->flash->notice(200));
        } catch (\Exception $e) {
            $this->dfdb->rollBack();
            Devflow::$PHP->flash->error(Devflow::$PHP->flash->notice(204));
        }
    }

    public function seoData(object $row): array
    {
        $decoded = json_decode((string) ($row->seo_data ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function normalizeRoute(string $route): string
    {
        $route = trim($route);

        if ($route === '') {
            return '/';
        }

        $path = parse_url($route, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '/';
        }

        $path = '/' . trim($path, '/') . '/';

        if (!preg_match('#^/[A-Za-z0-9/_\-\.]+/$#', $path)) {
            throw new \InvalidArgumentException(t__('Invalid route path.', 'simple-seo'));
        }

        return $path;
    }

    private function table(): string
    {
        return $this->dfdb->prefix . 'seo_route';
    }
}
