<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Console;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Plugin\SimpleSeo\Service\SubmissionQueueProcessor;
use Qubus\Exception\Data\TypeException;
use Qubus\Expressive\Database;

use function App\Shared\Helpers\get_all_sites;
use function App\Shared\Helpers\is_plugin_activated;
use function App\Shared\Helpers\restore_current_site;
use function App\Shared\Helpers\switch_to_site;
use function sprintf;

final class SeoSubmitQueueCommand extends ConsoleCommand
{
    protected string $name = 'seo:submit-queue';

    protected string $description = 'Processes queued Simple SEO URL submissions.';

    public function __construct(
        protected Application $codefy,
        private readonly SubmissionQueueProcessor $processor,
        private readonly Database $dfdb,
    ) {
        parent::__construct($codefy);
    }

    /**
     * @return int
     * @throws \Codefy\QueryBus\UnresolvableQueryHandlerException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function handle(): int
    {
        foreach (get_all_sites() as $site) {
            try {
                switch_to_site($site['key']);

                if (! $this->shouldRunForCurrentSite()) {
                    $this->output->writeln(sprintf(
                        '<comment>Skipping SimpleSeo queue for site: %s</comment>',
                        $site['domain'] ?? $site['key'] ?? ''
                    ));

                    continue;
                }

                $result = $this->processor->process(10);

                $this->output->writeln(
                    sprintf(
                        'Processed: %s, Failed: %s. For site %s',
                        $result['processed'],
                        $result['failed'],
                        $site['domain'] ?? $site['key'] ?? ''
                    )
                );
            } catch (\Throwable $e) {
                $this->output->writeln(sprintf(
                    '<error>IndexNow queue failed for site %s: %s</error>',
                    $site['domain'] ?? $site['key'],
                    $e->getMessage()
                ));
            } finally {
                restore_current_site();
            }
        }

        return self::SUCCESS;
    }

    /**
     * @throws TypeException
     */
    private function seoQueueTableExists(): bool
    {
        $table = $this->dfdb->prefix . 'seo_submission_queue';

        $prepare = $this->codefy->configContainer->string(key: 'database.default') === 'sqlite'
            ? $this->dfdb->prepare("SELECT name FROM sqlite_master WHERE type IN ('table') AND name = ?", [$table])
            : $this->dfdb->prepare("SHOW TABLES LIKE ?", ["%$table%"]);

        $result = $this->dfdb->getVar($prepare);

        return $result !== null;
    }

    private function isSimpleSeoActiveForCurrentSite(): bool
    {
        return is_plugin_activated('Plugin\\SimpleSeo\\SimpleSeoPlugin');
    }

    /**
     * @return bool
     * @throws TypeException
     */
    private function shouldRunForCurrentSite(): bool
    {
        return $this->isSimpleSeoActiveForCurrentSite()
                && $this->seoQueueTableExists();
    }
}
