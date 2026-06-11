<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Routing;

use Plugin\SimpleSeo\Service\UriRedirectService;
use Psr\Http\Message\RequestInterface;
use Qubus\Routing\Interfaces\BootManager;
use Qubus\Routing\Router;

final readonly class SeoRedirectBootManager implements BootManager
{
    public function __construct(private UriRedirectService $redirectManager)
    {
    }

    public function boot(Router $router, RequestInterface $request): void
    {
        $response = $this->redirectManager->handle($request);

        if ($response === null) {
            return;
        }

        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }

        exit;
    }
}
