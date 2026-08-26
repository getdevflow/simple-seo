<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Trait\Migration;

use Qubus\Exception\Exception;
use Qubus\Expressive\Schema\CreateTable;

use function bin2hex;
use function in_array;
use function random_bytes;

trait SimpleSeoMigrationAware
{
    /**
     * @throws \Exception
     */
    protected function migrateRedirectHostRules(): void
    {
        $seoRedirectTable = $this->dfdb->prefix . 'seo_redirect';

        if (!$this->dfdb->schema()->hasTable(table: $seoRedirectTable)) {
            return;
        }

        $columns = $this->dfdb->schema()->getColumns(table: $seoRedirectTable);
        $migrationTable = $seoRedirectTable . '_migration_' . bin2hex(random_bytes(4));

        $sourceHost = in_array('source_host', $columns, true) ? 'source_host' : 'NULL';
        $matchType = in_array('match_type', $columns, true) ? 'match_type' : "'exact'";
        $excludedPaths = in_array('excluded_paths', $columns, true) ? 'excluded_paths' : 'NULL';
        $preserveQuery = in_array('preserve_query', $columns, true) ? 'preserve_query' : '1';

        $this->dfdb->transactional(function () use (
            $seoRedirectTable,
            $migrationTable,
            $sourceHost,
            $matchType,
            $excludedPaths,
            $preserveQuery
        ): void {
            if ($this->dfdb->schema()->hasTable(table: $migrationTable, clear: true)) {
                $this->dfdb->schema()->drop(table: $migrationTable);
            }

            $this->createRedirectTable($migrationTable);

            $this->dfdb->query(
                "INSERT INTO {$migrationTable}
                 (id, source_host, source_url, target_url, match_type, excluded_paths,
                  preserve_query, status_code, entity_type, entity_id, hits, enabled,
                  created_at, updated_at)
                 SELECT id, {$sourceHost}, source_url, target_url, {$matchType}, {$excludedPaths},
                        {$preserveQuery}, status_code, entity_type, entity_id, hits, enabled,
                        created_at, updated_at
                 FROM {$seoRedirectTable}"
            );

            $this->dfdb->schema()->drop(table: $seoRedirectTable);
            $this->dfdb->schema()->renameTable(table: $migrationTable, name: $seoRedirectTable);
        });
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function migrationUpdates(): void
    {
        $seo404Table = $this->dfdb->prefix . 'seo_404_log';
        if (!$this->dfdb->schema()->hasTable(table: $seo404Table)) {
            return;
        }

        $seo404Columns = $this->dfdb->schema()->getColumns(table: $seo404Table);
        $migrationTable = $seo404Table . '_migration_' . bin2hex(random_bytes(4));
        $requestHost = in_array('request_host', $seo404Columns, true) ? 'request_host' : "''";

        $this->dfdb->transactional(function () use ($seo404Table, $migrationTable, $requestHost): void {
            if ($this->dfdb->schema()->hasTable(table: $migrationTable, clear: true)) {
                $this->dfdb->schema()->drop(table: $migrationTable);
            }

            $this->createNotFoundTable($migrationTable);

            $this->dfdb->query(
                "INSERT INTO {$migrationTable}
                 (id, request_host, request_path, referrer, user_agent, hits, ignored, first_seen, last_seen)
                 SELECT id, {$requestHost}, request_path, referrer, user_agent, hits, ignored, first_seen, last_seen
                 FROM {$seo404Table}"
            );

            $this->dfdb->schema()->drop(table: $seo404Table);
            $this->dfdb->schema()->renameTable(table: $migrationTable, name: $seo404Table);
        });
    }

    /**
     * @throws Exception
     */
    protected function createRedirectTable(string $tableName): void
    {
        $this->dfdb->schema()->create(table: $tableName, callback: function (CreateTable $table): void {
            $table->string(name: 'id', length: 36)->primary();
            $table->string(name: 'source_host', length: 191)->index();
            $table->string(name: 'source_url', length: 191)->notNull()->index();
            $table->string(name: 'target_url', length: 191)->notNull();
            $table->string(name: 'match_type', length: 20)
                ->notNull()
                ->defaultValue('exact')
                ->index();
            $table->text(name: 'excluded_paths')->size('big');
            $table->integer(name: 'preserve_query')
                ->size('small')
                ->notNull()
                ->defaultValue(1);
            $table->integer(name: 'status_code')
                ->size('small')
                ->notNull()
                ->defaultValue(301);
            $table->string(name: 'entity_type', length: 50);
            $table->string(name: 'entity_id', length: 191);
            $table->integer(name: 'hits')
                ->size('small')
                ->notNull()
                ->defaultValue(0);
            $table->integer(name: 'enabled')
                ->size('small')
                ->notNull()
                ->defaultValue(1)
                ->index();
            $table->dateTime(name: 'created_at')->notNull();
            $table->dateTime(name: 'updated_at')->notNull();
            $table->unique(
                ['source_host', 'source_url', 'match_type'],
                $this->dfdb->prefix . 'seoRedirectSourceRule'
            );
        });
    }

    /**
     * @throws Exception
     */
    protected function createNotFoundTable(string $tableName): void
    {
        $this->dfdb->schema()->create(table: $tableName, callback: function (CreateTable $table): void {
            $table->string(name: 'id', length: 36)->primary();
            $table->string(name: 'request_host', length: 191)->notNull()->index();
            $table->string(name: 'request_path', length: 191)->notNull();
            $table->string(name: 'referrer', length: 500);
            $table->string(name: 'user_agent', length: 500);
            $table->integer(name: 'hits')
                ->size('small')
                ->notNull()
                ->defaultValue(1);
            $table->integer(name: 'ignored')
                ->size('small')
                ->notNull()
                ->defaultValue(0);
            $table->dateTime(name: 'first_seen')->notNull();
            $table->dateTime(name: 'last_seen')->notNull();
            $table->unique(
                ['request_host', 'request_path'],
                $this->dfdb->prefix . 'seo404LogHostPath'
            );
        });
    }

    protected function redirectSchemaIsCurrent(): bool
    {
        return $this->tableHasColumns(
            $this->dfdb->prefix . 'seo_redirect',
            ['source_host', 'source_url', 'match_type', 'excluded_paths', 'preserve_query']
        );
    }

    protected function notFoundSchemaIsCurrent(): bool
    {
        return $this->tableHasColumns(
            $this->dfdb->prefix . 'seo_404_log',
            ['request_host', 'request_path', 'hits', 'ignored', 'first_seen', 'last_seen']
        );
    }

    /**
     * @param list<string> $requiredColumns
     */
    private function tableHasColumns(string $tableName, array $requiredColumns): bool
    {
        if (!$this->dfdb->schema()->hasTable(table: $tableName, clear: true)) {
            return false;
        }

        $columns = $this->dfdb->schema()->getColumns(table: $tableName, clear: true);

        return array_all($requiredColumns, fn($requiredColumn) => in_array($requiredColumn, $columns, true));
    }
}
