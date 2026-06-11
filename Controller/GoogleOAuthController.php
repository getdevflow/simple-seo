<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Plugin\SimpleSeo\Service\GoogleOAuthService;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\RedirectResponseFactory;
use Qubus\Http\ServerRequest;

use function App\Shared\Helpers\admin_url;
use function Qubus\Routing\Helpers\redirect;

final readonly class GoogleOAuthController
{
    public function __construct(private GoogleOAuthService $oauth)
    {
    }

    public function connect(): ResponseInterface
    {
        return RedirectResponseFactory::create($this->oauth->authUrl());
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function callback(ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $code = (string) ($query['code'] ?? '');

        if ($code !== '') {
            $this->oauth->exchangeCode($code);
        }

        return redirect(admin_url('plugin/simple-seo/search-console/'));
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     */
    public function disconnect(): ResponseInterface
    {
        $this->oauth->disconnect();

        return redirect(admin_url('plugin/simple-seo/search-console/'));
    }
}
