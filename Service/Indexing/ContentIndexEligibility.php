<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service\Indexing;

use Plugin\SimpleSeo\Service\EntityUrlResolver;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Exception\Exception;
use ReflectionException;
use Throwable;

use function App\Shared\Helpers\get_content_attribute;
use function App\Shared\Helpers\get_content_by_id;
use function filter_var;
use function in_array;
use function is_bool;
use function is_int;
use function is_object;
use function is_string;
use function Qubus\Security\Helpers\esc_html__;
use function sprintf;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_URL;

final readonly class ContentIndexEligibility
{
    /**
     * @var list<string>
     */
    private const array INDEXABLE_STATUSES = [
        'published',
    ];

    public function __construct(
        private EntityUrlResolver $urlResolver
    ) {
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws Exception
     * @throws ReflectionException
     * @throws TypeException
     */
    public function check(string $contentId): ContentIndexEligibilityResult
    {
        $contentId = trim($contentId);

        if ($contentId === '') {
            return ContentIndexEligibilityResult::ineligible(
                reason: esc_html__('The content ID is empty.', 'simple-seo'),
            );
        }

        try {
            $content = get_content_by_id($contentId);
        } catch (Throwable $exception) {
            return ContentIndexEligibilityResult::ineligible(
                reason: sprintf(
                    esc_html__(
                        'The content could not be loaded: %s',
                        'simple-seo'
                    ),
                    $exception->getMessage()
                )
            );
        }

        if ($content === false || !is_object($content)) {
            return ContentIndexEligibilityResult::ineligible(
                reason: esc_html__('The content does not exist.', 'simple-seo'),
            );
        }

        $status = $this->resolveStatus($content);

        if (!in_array($status, self::INDEXABLE_STATUSES, true)) {
            return ContentIndexEligibilityResult::ineligible(
                reason: sprintf(
                    esc_html__('Content status "%s" is not eligible for index submission.', 'simple-seo'),
                    $status !== '' ? $status : 'unknown'
                )
            );
        }

        if (!$this->isAllowedToIndex($contentId)) {
            return ContentIndexEligibilityResult::ineligible(
                reason: esc_html__('The content is configured with a noindex directive.', 'simple-seo'),
            );
        }

        try {
            $url = $this->urlResolver->contentUrl($contentId);
        } catch (Throwable $exception) {
            return ContentIndexEligibilityResult::ineligible(
                reason: sprintf(
                    esc_html__(
                        'The content URL could not be resolved: %s',
                        'simple-seo'
                    ),
                    $exception->getMessage()
                )
            );
        }

        if (!is_string($url) || trim($url) === '') {
            return ContentIndexEligibilityResult::ineligible(
                reason: esc_html__('The content does not have a public URL.', 'simple-seo'),
            );
        }

        $url = trim($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ContentIndexEligibilityResult::ineligible(
                reason: sprintf(
                    esc_html__('The resolved content URL is invalid: %s', 'simple-seo'),
                    $url
                )
            );
        }

        return ContentIndexEligibilityResult::eligible($url);
    }

    private function resolveStatus(object $content): string
    {
        $status = $content->content_status ?? $content->status ?? '';

        return strtolower(trim((string) $status));
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws TypeException
     */
    private function isAllowedToIndex(string $contentId): bool
    {
        $robotsIndex = get_content_attribute(
            contentId: $contentId,
            key: 'seo.robots_index',
            default: '1'
        );

        return $this->toBoolean(
            value: $robotsIndex,
            default: true
        );
    }

    private function toBoolean(
        mixed $value,
        bool $default = false
    ): bool {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'on', 'index' => true,
            '0', 'false', 'no', 'off', 'noindex' => false,
            default => $default,
        };
    }
}
