<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Support;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Qubus\Exception\Exception;
use Qubus\Support\DateTime\QubusDateTimeImmutable;
use ReflectionException;

use function App\Shared\Helpers\cms_expand_attribute_urls;
use function filter_var;
use function in_array;
use function mb_substr;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_URL;

final readonly class VideoSitemapEntry
{
    private const array SOURCE_TYPES = [
        'content',
        'player',
    ];

    public function __construct(
        public string $sourceType,
        public string $contentUrl,
        public string $playerUrl,
        public string $thumbnailUrl,
        public string $title,
        public string $description,
        public ?int $duration = null,
        public ?string $publicationDate = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $sourceType = strtolower(
            trim((string) ($data['source_type'] ?? 'content'))
        );

        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            $sourceType = 'content';
        }

        $duration = self::normalizeDuration(
            $data['duration'] ?? null
        );

        return new self(
            sourceType: $sourceType,
            contentUrl: trim((string) ($data['content_url'] ?? '')),
            playerUrl: trim((string) ($data['player_url'] ?? '')),
            thumbnailUrl: trim((string) ($data['thumbnail_url'] ?? '')),
            title: mb_substr(
                trim((string) ($data['title'] ?? '')),
                0,
                100
            ),
            description: mb_substr(
                trim((string) ($data['description'] ?? '')),
                0,
                2048
            ),
            duration: $duration,
            publicationDate: self::normalizeDate(
                $data['publication_date'] ?? null
            )
        );
    }

    /**
     * @return array{
     *     source_type: string,
     *     content_url: string,
     *     player_url: string,
     *     thumbnail_url: string,
     *     title: string,
     *     description: string,
     *     duration: int|null,
     *     publication_date: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'content_url' => $this->contentUrl,
            'player_url' => $this->playerUrl,
            'thumbnail_url' => $this->thumbnailUrl,
            'title' => $this->title,
            'description' => $this->description,
            'duration' => $this->duration,
            'publication_date' => $this->publicationDate,
        ];
    }

    /**
     * @return string
     */
    public function sourceUrl(): string
    {
        return $this->sourceType === 'player'
            ? $this->playerUrl
            : $this->contentUrl;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws Exception
     */
    public function isValid(): bool
    {
        $thumbnailUrl = cms_expand_attribute_urls(
            $this->thumbnailUrl
        );

        if (
            $thumbnailUrl === ''
            || filter_var(
                $thumbnailUrl,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            return false;
        }

        if (
            $this->title === ''
            || $this->description === ''
        ) {
            return false;
        }

        $sourceUrl = cms_expand_attribute_urls(
            $this->sourceUrl()
        );

        return $sourceUrl !== ''
            && filter_var(
                $sourceUrl,
                FILTER_VALIDATE_URL
            ) !== false;
    }

    private static function normalizeDuration(mixed $duration): ?int
    {
        if (
            $duration === null
            || trim((string) $duration) === ''
        ) {
            return null;
        }

        $duration = filter_var(
            $duration,
            FILTER_VALIDATE_INT
        );

        if (
            $duration === false
            || $duration < 1
            || $duration > 28800
        ) {
            return null;
        }

        return $duration;
    }

    private static function normalizeDate(mixed $date): ?string
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        try {
            return new QubusDateTimeImmutable($date)
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
