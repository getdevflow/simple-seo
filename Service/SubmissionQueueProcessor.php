<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Plugin\SimpleSeo\Repository\SubmissionQueueRepository;

final readonly class SubmissionQueueProcessor
{
    public function __construct(
        private SubmissionQueueRepository $queue,
        private IndexNowService $indexNow,
        private GoogleIndexingService $googleIndexing
    ) {
    }

    public function process(int $limit = 10): array
    {
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->queue->pending($limit) as $item) {
            $this->queue->markProcessing((string) $item->id);

            try {
                $this->submit((string) $item->url, (string) $item->engine);

                $this->queue->markDone((string) $item->id);
                $processed++;
            } catch (\Throwable $e) {
                $message = $e->getMessage();

                $this->queue->markFailed((string) $item->id, $message);

                $failed++;

                $errors[] = [
                    'id' => (string) $item->id,
                    'url' => (string) $item->url,
                    'engine' => (string) $item->engine,
                    'error' => $message,
                ];
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
        ];
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
        if ($engine === 'indexnow') {
            $this->indexNow->submitUrl($url);
            return;
        }

        if ($engine === 'google') {
            $this->googleIndexing->submitUrl($url);
            return;
        }

        $errors = [];

        try {
            $this->indexNow->submitUrl($url);
        } catch (\Throwable $e) {
            $errors[] = 'IndexNow: ' . $e->getMessage();
        }

        try {
            $this->googleIndexing->submitUrl($url);
        } catch (\Throwable $e) {
            $errors[] = 'Google: ' . $e->getMessage();
        }

        if ($errors !== [] && count($errors) === 2) {
            throw new \RuntimeException(implode(' | ', $errors));
        }
    }
}
