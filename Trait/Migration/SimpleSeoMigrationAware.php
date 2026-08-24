<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Trait\Migration;

use Qubus\Exception\Exception;
use Qubus\Expressive\Schema\AlterTable;
use Qubus\Expressive\Schema\CreateTable;

use function in_array;

trait SimpleSeoMigrationAware
{
    /**
     * @throws Exception
     */
    protected function migrateRedirectHostRules(): void
    {
        $seoRedirectTable = $this->dfdb->prefix . 'seo_redirect';

        if (!$this->dfdb->schema()->hasTable(table: $seoRedirectTable)) {
            return;
        }

        $columns = $this->dfdb->schema()->getColumns(table: $seoRedirectTable);
        if (!in_array('source_host', $columns)) {
            $this->dfdb->schema()->alter(table: $seoRedirectTable, callback: function (AlterTable $table): void {
                $table->string(name: 'source_host', length: 191);
                $table->string(name: 'match_type', length: 20)
                        ->notNull()
                        ->defaultValue('exact');
                $table->text(name: 'excluded_paths')
                        ->size(value: 'big');
                $table->integer(name: 'preserve_query')
                        ->size(value: 'small')
                        ->notNull()
                        ->defaultValue(1);

                $table->dropUnique('source_url');
                $table->index(columns: 'source_host');
                $table->index(columns: 'match_type');
                $table->index(columns: 'source_url');

                $table->unique(
                    [
                        'source_host',
                        'source_url',
                        'match_type',
                    ],
                    $this->dfdb->prefix . 'seoRedirectSourceRule'
                );
            });
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function migrationUpdates(): void
    {
        $seo404Table = $this->dfdb->prefix . 'seo_404_log';
        if (!$this->dfdb->schema()->hasTable(table: $seo404Table)) {
            return;
        }
        $seo404Columns = $this->dfdb->schema()->getColumns(table: $seo404Table);
        if (!in_array('request_host', $seo404Columns)) {
            $this->dfdb->schema()->drop(table: $this->dfdb->prefix . 'seo_404_log');

            $this->dfdb->schema()
                ->create(
                    table: $this->dfdb->prefix . 'seo_404_log',
                    callback: function (CreateTable $table) {
                        $table->string(name: 'id', length: 36)
                            ->primary();
                        $table->string('request_host', length: 191)->notNull()->index();
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
                    }
                );
        }
    }
}
