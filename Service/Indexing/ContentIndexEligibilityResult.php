<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service\Indexing;

final readonly class ContentIndexEligibilityResult
{
    private function __construct(
        public bool $eligible,
        public string $reason,
        public ?string $url
    ) {
    }

    public static function eligible(string $url): self
    {
        return new self(
            eligible: true,
            reason: '',
            url: $url
        );
    }

    public static function ineligible(string $reason): self
    {
        return new self(
            eligible: false,
            reason: $reason,
            url: null
        );
    }
}
