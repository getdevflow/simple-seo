<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Routing;

use Plugin\SimpleSeo\Repository\RedirectRepository;
use Plugin\SimpleSeo\Service\RedirectResolver;
use Psr\Http\Message\RequestInterface;
use Qubus\Routing\Interfaces\BootManager;
use Qubus\Routing\Router;

use function header;
use function http_response_code;
use function in_array;

final readonly class SeoRedirectBootManager implements BootManager
{
    public function __construct(
        private RedirectResolver $resolver,
        private RedirectRepository $redirects
    ) {
    }

    public function boot(
        Router $router,
        RequestInterface $request
    ): void {
        $match = $this->resolver->resolve($request);

        if ($match === null) {
            return;
        }

        $statusCode = (int) (
            $match->redirect->status_code
            ?? 301
        );

        if (
            !in_array(
                $statusCode,
                [301, 302, 307, 308],
                true
            )
        ) {
            $statusCode = 301;
        }

        $this->redirects->incrementHits(
            (string) $match->redirect->id
        );

        http_response_code($statusCode);

        header(
            'Location: ' . $match->targetUrl,
            true,
            $statusCode
        );

        exit;
    }
}
