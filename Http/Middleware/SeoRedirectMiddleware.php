<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Http\Middleware;

use Laminas\Diactoros\Response\RedirectResponse;
use Plugin\SimpleSeo\Repository\RedirectRepository;
use Plugin\SimpleSeo\Service\RedirectResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SeoRedirectMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RedirectResolver $resolver,
        private RedirectRepository $redirects
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $match = $this->resolver->resolve($request);

        if ($match === null) {
            return $handler->handle($request);
        }

        $this->redirects->incrementHits(
            (string) $match->redirect->id
        );

        return new RedirectResponse(
            uri: $match->targetUrl,
            status: (int) (
                $match->redirect->status_code
                ?? 301
            )
        );
    }
}
