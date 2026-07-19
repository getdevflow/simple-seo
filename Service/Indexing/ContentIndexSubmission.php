<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service\Indexing;

use Plugin\SimpleSeo\Repository\SubmissionQueueRepository;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Data\TypeException;
use Qubus\Exception\Exception;
use ReflectionException;

final readonly class ContentIndexSubmission
{
    public function __construct(
        private ContentIndexEligibility $eligibility,
        private SubmissionQueueRepository $queue
    ) {
    }

    /**
     * @param string $contentId
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws TypeException
     */
    public function enqueue(string $contentId): void
    {
        $contentId = trim($contentId);

        if ($contentId === '') {
            return;
        }

        if (!SimpleSeoSettings::get('auto_submit_urls', false)) {
            return;
        }

        $result = $this->eligibility->check($contentId);

        if (!$result->eligible || $result->url === null) {
            /*
             * This handles content changing from published to draft,
             * scheduled, pending, archived, or noindex.
             */
            $this->queue->deletePendingForEntity(
                entityType: 'content',
                entityId: $contentId
            );

            return;
        }

        $engine = (string) SimpleSeoSettings::get(
            'auto_submit_engine',
            'indexnow'
        );

        $this->queue->enqueue(
            url: $result->url,
            engine: $engine,
            entityType: 'content',
            entityId: $contentId
        );
    }
}
