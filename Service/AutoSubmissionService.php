<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\SubmissionQueueRepository;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Exception;
use ReflectionException;

final readonly class AutoSubmissionService
{
    public function __construct(private SubmissionQueueRepository $queue)
    {
    }

    /**
     * @param string $url
     * @param string|null $entityType
     * @param string|null $entityId
     * @return void
     * @throws InvalidArgumentException
     * @throws Exception
     * @throws ReflectionException
     */
    public function enqueueUrl(
        string $url,
        ?string $entityType = null,
        ?string $entityId = null
    ): void {
        if (!SimpleSeoSettings::get('auto_submit_urls', false)) {
            return;
        }

        $engine = (string) SimpleSeoSettings::get(
            'auto_submit_engine',
            'indexnow'
        );

        $this->queue->enqueue(
            url: $url,
            engine: $engine,
            entityType: $entityType,
            entityId: $entityId
        );
    }
}
