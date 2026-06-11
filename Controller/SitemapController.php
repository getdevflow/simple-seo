<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Controller;

use Codefy\Framework\Application;
use Exception;
use Plugin\SimpleSeo\Service\SitemapService;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\Factories\JsonResponseFactory;
use Qubus\Http\Factories\TextResponseFactory;
use Qubus\Http\Factories\XmlResponseFactory;

use function Qubus\Security\Helpers\t__;

final readonly class SitemapController
{
    public function __construct(private Application $devflow)
    {
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function index(): ResponseInterface
    {
        $xml = $this->devflow->make(name: SitemapService::class)->index();

        return $this->status($xml);
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function content(): ResponseInterface
    {
        $xml = $this->devflow->make(name: SitemapService::class)->urls('content');

        return $this->status($xml);
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function products(): ResponseInterface
    {
        $xml = $this->devflow->make(name: SitemapService::class)->urls('product');

        return $this->status($xml);
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function news(): ResponseInterface
    {
        $xml = $this->devflow->make(name: SitemapService::class)->news();

        return $this->status($xml);
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function images(): ResponseInterface
    {
        $xml = $this->devflow->make(name: SitemapService::class)->images();

        return $this->status($xml);
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function videos(): ResponseInterface
    {
        $xml = $this->devflow->make(name: SitemapService::class)->videos();

        return $this->status($xml);
    }

    /**
     * @return ResponseInterface
     * @throws \Qubus\Exception\Exception
     * @throws Exception
     */
    public function pages(): ResponseInterface
    {
        $xml = $this->devflow->make(name: SitemapService::class)->pages();

        return $this->status($xml);
    }

    /**
     * @param string $contentType
     * @return ResponseInterface
     * @throws Exception
     */
    public function contentType(string $contentType): ResponseInterface
    {
        $xml = $this->devflow
            ->make(name: SitemapService::class)
            ->contentType($contentType);

        return $this->status($xml);
    }

    /**
     * @param string $key
     * @return ResponseInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     * @throws Exception
     */
    public function indexNowKey(string $key): ResponseInterface
    {
        $expected = SimpleSeoSettings::get('indexnow_key', '');
        if ($expected !== '' && $key === $expected) {
            return TextResponseFactory::create($expected);
        }
        return JsonResponseFactory::create(t__('Wrong key.', 'simple-seo'), 404);
    }

    /**
     * @return ResponseInterface
     * @throws Exception
     */
    public function stylesheet(): ResponseInterface
    {
        return TextResponseFactory::create(
            $this->devflow->make(name: SitemapService::class)->stylesheet(),
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );
    }

    /**
     * @param string $xml
     * @return ResponseInterface
     * @throws Exception
     */
    private function xml(string $xml): ResponseInterface
    {
        return XmlResponseFactory::create($xml);
    }

    /**
     * @param string $xml
     * @return ResponseInterface
     * @throws Exception
     */
    private function status(string $xml): ResponseInterface
    {
        if ($xml === '') {
            return TextResponseFactory::create(t__('No sitemap.', 'simple-seo'), 404);
        }

        return $this->xml($xml);
    }
}
