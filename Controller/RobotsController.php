<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Exception;
use Plugin\SimpleSeo\Service\RobotsService;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\TextResponseFactory;

final readonly class RobotsController
{
    public function __construct(private RobotsService $robots)
    {
    }

    /**
     * @return ResponseInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     * @throws Exception
     */
    public function index(): ResponseInterface
    {
        $content = $this->robots->render();

        if ($content === '') {
            return TextResponseFactory::create('robots.txt disabled.', 404);
        }

        return TextResponseFactory::create(
            $content,
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
}
