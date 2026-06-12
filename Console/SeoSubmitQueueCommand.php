<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Console;

use Codefy\Framework\Application;
use Codefy\Framework\Console\ConsoleCommand;
use Plugin\SimpleSeo\Service\SubmissionQueueProcessor;

final class SeoSubmitQueueCommand extends ConsoleCommand
{
    protected string $name = 'seo:submit-queue';

    protected string $description = 'Processes queued Simple SEO URL submissions.';

    public function __construct(
        protected Application $codefy,
        private readonly SubmissionQueueProcessor $processor
    ) {
        parent::__construct($codefy);
    }

    public function handle(): int
    {
        $result = $this->processor->process(10);

        $this->output->writeln(
            'Processed: ' . $result['processed'] . ', Failed: ' . $result['failed']
        );

        return self::SUCCESS;
    }
}
