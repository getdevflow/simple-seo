<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\SubmissionQueueRepository;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;

final class AutoSubmissionService
{
    public function __construct(private SubmissionQueueRepository $queue)
    {
    }

    /**
     * @param string $url
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function enqueueUrl(string $url): void
    {
        if (!SimpleSeoSettings::get('auto_submit_urls', false)) {
            return;
        }

        $engine = (string) SimpleSeoSettings::get('auto_submit_engine', 'indexnow');

        $this->queue->enqueue($url, $engine);
    }
}
