<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Plugin\SimpleSeo\Service\GoogleOAuthService;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Data\TypeException;
use Qubus\Exception\Exception;
use Qubus\Http\Factories\RedirectResponseFactory;
use Qubus\Http\ServerRequest;
use ReflectionException;

use function App\Shared\Helpers\admin_url;
use function Qubus\Routing\Helpers\redirect;

final readonly class GoogleOAuthController
{
    public function __construct(private GoogleOAuthService $oauth)
    {
    }

    /**
     * @return ResponseInterface
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws TypeException
     * @throws \Random\RandomException
     */
    public function connect(): ResponseInterface
    {
        return RedirectResponseFactory::create($this->oauth->authUrl());
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws InvalidArgumentException
     * @throws TypeException
     * @throws Exception
     * @throws ReflectionException
     */
    public function callback(ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $state = (string) ($query['state'] ?? '');
        $saved = (string) SimpleSeoSettings::get('google_oauth_state', '');

        if ($state === '' || !hash_equals($saved, $state)) {
            throw new \RuntimeException('Invalid Google OAuth state.');
        }

        $code = (string) ($query['code'] ?? '');

        if ($code !== '') {
            $this->oauth->exchangeCode($code);
        }

        return redirect(admin_url('plugin/simple-seo/search-console/'));
    }

    /**
     * @return ResponseInterface
     * @throws Exception
     * @throws \Exception
     */
    public function disconnect(): ResponseInterface
    {
        $this->oauth->disconnect();

        return redirect(admin_url('plugin/simple-seo/search-console/'));
    }
}
