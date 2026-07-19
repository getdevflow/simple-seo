<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\SubmissionQueueRepository;
use Plugin\SimpleSeo\Service\Indexing\ContentIndexEligibility;
use Plugin\SimpleSeo\Service\Indexing\ContentIndexEligibilityResult;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Data\TypeException;
use Qubus\Exception\Exception;
use ReflectionException;
use Throwable;

use function Codefy\Framework\Helpers\logger;
use function filter_var;
use function Qubus\Security\Helpers\esc_html__;
use function sprintf;
use function trim;

use const FILTER_VALIDATE_URL;

final readonly class SubmissionQueueProcessor
{
    public function __construct(
        private SubmissionQueueRepository $queue,
        private ContentIndexEligibility $contentEligibility,
        private IndexNowService $indexNow,
        private GoogleIndexingService $googleIndexing
    ) {
    }

    /**
     * @return array{
     *     processed: int,
     *     failed: int,
     *     skipped: int,
     *     errors: list<array{
     *         id: string,
     *         url: string,
     *         engine: string,
     *         error: string
     *     }>
     * }
     */
    public function process(int $limit = 10): array
    {
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($this->queue->pending($limit) as $item) {
            $id = (string) ($item->id ?? '');

            if ($id === '') {
                continue;
            }

            try {
                $this->queue->markProcessing($id);

                $eligibility = $this->checkEligibility($item);

                if (!$eligibility->eligible) {
                    /*
                     * This is not a submission failure. The content changed
                     * after it was queued and should no longer be indexed.
                     */
                    $this->queue->delete($id);
                    $skipped++;

                    logger(
                        level: 'info',
                        message: esc_html__(
                            'SEO submission skipped because the entity is no longer indexable.',
                            'simple-seo'
                        ),
                        context: [
                            'queue_id' => $id,
                            'entity_type' => $item->entity_type ?? null,
                            'entity_id' => $item->entity_id ?? null,
                            'url' => $item->url ?? null,
                            'reason' => $eligibility->reason,
                        ]
                    );

                    continue;
                }

                $url = $eligibility->url ?? trim((string) ($item->url ?? ''));

                $this->submit(
                    url: $url,
                    engine: (string) ($item->engine ?? 'indexnow')
                );

                /*
                 * Successful queue items are removed instead of retained as
                 * completed history.
                 */
                $this->queue->delete($id);
                $processed++;
            } catch (Throwable $e) {
                $this->queue->markFailed(
                    id: $id,
                    error: $e->getMessage()
                );

                $failed++;

                $errors[] = [
                    'id' => (string) $item->id,
                    'url' => (string) $item->url,
                    'engine' => (string) $item->engine,
                    'error' => $e->getMessage(),
                ];

                logger(
                    level: 'error',
                    message: $e->getMessage(),
                    context: [
                        'queue_id' => $id,
                        'entity_type' => $item->entity_type ?? null,
                        'entity_id' => $item->entity_id ?? null,
                        'url' => $item->url ?? null,
                    ]
                );
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param object<string, mixed> $item
     * @return ContentIndexEligibilityResult
     * @throws Exception
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws TypeException
     */
    private function checkEligibility(object $item): ContentIndexEligibilityResult
    {
        $entityType = trim((string) ($item->entity_type ?? ''));
        $entityId = trim((string) ($item->entity_id ?? ''));
        $url = trim((string) ($item->url ?? ''));

        if ($entityType === 'content') {
            if ($entityId === '') {
                return ContentIndexEligibilityResult::ineligible(
                    reason: esc_html__(
                        'The content queue item does not contain an entity ID.',
                        'simple-seo'
                    )
                );
            }

            return $this->contentEligibility->check($entityId);
        }

        if (
                $url === ''
                || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return ContentIndexEligibilityResult::ineligible(
                reason: esc_html__('The queue item does not contain a valid URL.', 'simple-seo')
            );
        }

        return ContentIndexEligibilityResult::eligible($url);
    }

    /**
     * @param string $url
     * @param string $engine
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function submit(string $url, string $engine): void
    {
        match ($engine) {
            'indexnow' => $this->indexNow->submitUrls([$url]),
            'google' => $this->googleIndexing->submitUrl($url),
            'both' => $this->submitToBoth($url),

            default => throw new \RuntimeException(
                sprintf(esc_html__('Unsupported indexing engine: %s.', 'simple-seo'), $engine)
            ),
        };
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function submitToBoth(string $url): void
    {
        $this->indexNow->submitUrls([$url]);
        $this->googleIndexing->submitUrl($url);
    }
}
