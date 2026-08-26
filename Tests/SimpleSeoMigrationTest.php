<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Tests;

use App\Shared\Services\Registry;
use PHPUnit\Framework\TestCase;
use Plugin\SimpleSeo\Trait\Migration\SimpleSeoMigrationAware;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\QueryBuilder;
use Qubus\Expressive\Schema\CreateTable;

final class SimpleSeoMigrationTest extends TestCase
{
    private QueryBuilder $database;

    /** @var array<string, mixed> */
    private array $originalRegistry;

    protected function setUp(): void
    {
        $registry = Registry::getInstance();
        $this->originalRegistry = $registry->getAll();
        $registry->set('tblPrefix', 'test_');

        $connection = new Sqlite([
            'dsn' => 'sqlite::memory:',
            'driver' => 'pdo_sqlite',
        ]);

        $this->database = new QueryBuilder($connection);
        $this->database->prefix = 'test_';
        $this->createLegacyTables();
    }

    protected function tearDown(): void
    {
        $registry = Registry::getInstance();
        $registry->clear();
        $registry->add($this->originalRegistry);
    }

    public function testMigratesLegacyTablesWithoutLosingData(): void
    {
        $this->database->query(
            "INSERT INTO test_seo_redirect
             (id, source_url, target_url, status_code, entity_type, entity_id, hits, enabled, created_at, updated_at)
             VALUES ('redirect-1', '/old/', '/new/', 301, NULL, NULL, 7, 1,
                     '2026-08-01 00:00:00', '2026-08-02 00:00:00')"
        );
        $this->database->query(
            "INSERT INTO test_seo_404_log
             (id, request_path, referrer, user_agent, hits, ignored, first_seen, last_seen)
             VALUES ('404-1', '/missing/', NULL, 'Test Agent', 3, 0,
                     '2026-08-01 00:00:00', '2026-08-02 00:00:00')"
        );

        $migration = new class ($this->database) {
            use SimpleSeoMigrationAware;

            public function __construct(protected QueryBuilder $dfdb)
            {
            }

            public function migrate(): void
            {
                $this->migrateRedirectHostRules();
                $this->migrationUpdates();
            }
        };

        $migration->migrate();

        self::assertContains(
            'source_host',
            $this->database->schema()->getColumns('test_seo_redirect', true)
        );
        self::assertContains('match_type', $this->database->schema()->getColumns('test_seo_redirect', true));
        self::assertContains('request_host', $this->database->schema()->getColumns('test_seo_404_log', true));

        $redirect = $this->database->getRow("SELECT * FROM test_seo_redirect WHERE id = 'redirect-1'");
        self::assertNotNull($redirect);
        self::assertNull($redirect->source_host);
        self::assertSame('exact', $redirect->match_type);
        self::assertSame(7, (int) $redirect->hits);

        $notFound = $this->database->getRow("SELECT * FROM test_seo_404_log WHERE id = '404-1'");
        self::assertNotNull($notFound);
        self::assertSame('', $notFound->request_host);
        self::assertSame('/missing/', $notFound->request_path);
        self::assertSame(3, (int) $notFound->hits);
    }

    public function testReplacesLegacySingleColumnUniqueRules(): void
    {
        $migration = new class ($this->database) {
            use SimpleSeoMigrationAware;

            public function __construct(protected QueryBuilder $dfdb)
            {
            }

            public function migrate(): void
            {
                $this->migrateRedirectHostRules();
                $this->migrationUpdates();
            }
        };

        $migration->migrate();

        $this->database->query(
            "INSERT INTO test_seo_redirect
             (id, source_host, source_url, target_url, match_type, preserve_query, status_code, hits, enabled,
              created_at, updated_at)
             VALUES
             ('redirect-a', 'example.com', '/same/', '/a/', 'exact', 1, 301, 0, 1,
              '2026-08-01 00:00:00', '2026-08-01 00:00:00'),
             ('redirect-b', 'example.net', '/same/', '/b/', 'exact', 1, 301, 0, 1,
              '2026-08-01 00:00:00', '2026-08-01 00:00:00')"
        );

        $this->database->query(
            "INSERT INTO test_seo_404_log
             (id, request_host, request_path, hits, ignored, first_seen, last_seen)
             VALUES
             ('404-a', 'example.com', '/same/', 1, 0, '2026-08-01 00:00:00', '2026-08-01 00:00:00'),
             ('404-b', 'example.net', '/same/', 1, 0, '2026-08-01 00:00:00', '2026-08-01 00:00:00')"
        );

        self::assertSame(2, (int) $this->database->getVar('SELECT COUNT(*) FROM test_seo_redirect'));
        self::assertSame(2, (int) $this->database->getVar('SELECT COUNT(*) FROM test_seo_404_log'));
    }

    public function testRepairsADeletedNotFoundTableWhenRedirectSchemaIsAlreadyCurrent(): void
    {
        $migration = new class ($this->database) {
            use SimpleSeoMigrationAware;

            public function __construct(protected QueryBuilder $dfdb)
            {
            }

            public function repair(): void
            {
                if (!$this->redirectSchemaIsCurrent()) {
                    $this->migrateRedirectHostRules();
                }

                if (!$this->notFoundSchemaIsCurrent()) {
                    $tableName = $this->dfdb->prefix . 'seo_404_log';

                    if ($this->dfdb->schema()->hasTable($tableName, true)) {
                        $this->migrationUpdates();
                    } else {
                        $this->createNotFoundTable($tableName);
                    }
                }
            }

            public function schemaIsCurrent(): bool
            {
                return $this->redirectSchemaIsCurrent() && $this->notFoundSchemaIsCurrent();
            }
        };

        $migration->repair();
        self::assertTrue($migration->schemaIsCurrent());

        $this->database->schema()->drop('test_seo_404_log');
        self::assertFalse($migration->schemaIsCurrent());

        $migration->repair();

        self::assertTrue($migration->schemaIsCurrent());
        self::assertTrue($this->database->schema()->hasTable('test_seo_404_log', true));
    }

    private function createLegacyTables(): void
    {
        $this->database->schema()->create('test_seo_redirect', function (CreateTable $table): void {
            $table->string('id', 36)->primary();
            $table->string('source_url', 191)->notNull()->unique();
            $table->string('target_url', 191)->notNull();
            $table->integer('status_code')->notNull()->defaultValue(301);
            $table->string('entity_type', 50);
            $table->string('entity_id', 191);
            $table->integer('hits')->notNull()->defaultValue(0);
            $table->integer('enabled')->notNull()->defaultValue(1);
            $table->dateTime('created_at')->notNull();
            $table->dateTime('updated_at')->notNull();
        });

        $this->database->schema()->create('test_seo_404_log', function (CreateTable $table): void {
            $table->string('id', 36)->primary();
            $table->string('request_path', 191)->notNull()->unique();
            $table->string('referrer', 500);
            $table->string('user_agent', 500);
            $table->integer('hits')->notNull()->defaultValue(1);
            $table->integer('ignored')->notNull()->defaultValue(0);
            $table->dateTime('first_seen')->notNull();
            $table->dateTime('last_seen')->notNull();
        });
    }
}
