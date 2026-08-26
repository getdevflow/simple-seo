<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Tests;

use App\Shared\Services\Registry;
use Codefy\Framework\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Plugin\SimpleSeo\Repository\NotFoundRepository;
use Plugin\SimpleSeo\Service\NotFoundMonitor;
use Qubus\Expressive\Connection\Pdo\Sqlite;
use Qubus\Expressive\QueryBuilder;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Http\ServerRequest;

final class NotFoundMonitorTest extends TestCase
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
        $this->createNotFoundTable();
    }

    protected function tearDown(): void
    {
        $registry = Registry::getInstance();
        $registry->clear();
        $registry->add($this->originalRegistry);
    }

    public function testRecordsTheRequestBoundByTheHttpMiddleware(): void
    {
        $request = new ServerRequest(
            uri: 'https://devflow.ddev.site/blog/what-s-new-in-devflow-cms-2-4-0',
            method: 'GET',
            headers: [
                'Referer' => 'https://devflow.ddev.site/blog/',
                'User-Agent' => 'SimpleSeo test',
            ]
        );
        RequestContext::set($request);

        $repository = new NotFoundRepository($this->database);
        new NotFoundMonitor($repository)->recordCurrentRequest();

        $row = $this->database->getRow('SELECT * FROM test_seo_404_log LIMIT 1');

        self::assertNotNull($row);
        self::assertSame('devflow.ddev.site', $row->request_host);
        self::assertSame('/blog/what-s-new-in-devflow-cms-2-4-0/', $row->request_path);
        self::assertSame('https://devflow.ddev.site/blog/', $row->referrer);
        self::assertSame('SimpleSeo test', $row->user_agent);
    }

    private function createNotFoundTable(): void
    {
        $this->database->schema()->create('test_seo_404_log', function (CreateTable $table): void {
            $table->string('id', 36)->primary();
            $table->string('request_host', 191)->notNull()->index();
            $table->string('request_path', 191)->notNull();
            $table->string('referrer', 500);
            $table->string('user_agent', 500);
            $table->integer('hits')->notNull()->defaultValue(1);
            $table->integer('ignored')->notNull()->defaultValue(0);
            $table->dateTime('first_seen')->notNull();
            $table->dateTime('last_seen')->notNull();
            $table->unique(['request_host', 'request_path']);
        });
    }
}
