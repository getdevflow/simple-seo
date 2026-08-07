<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Routing;

final readonly class RedirectMatch
{
    public function __construct(
        public object $redirect,
        public string $targetUrl
    ) {
    }
}
