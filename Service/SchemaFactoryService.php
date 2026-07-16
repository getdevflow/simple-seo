<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Service;

use Melbahja\Seo\Schema;
use Melbahja\Seo\Schema\Thing;
use Plugin\SimpleSeo\Support\SimpleSeoSettings;

use Psr\SimpleCache\InvalidArgumentException;
use Qubus\Exception\Exception;
use ReflectionException;

use function App\Shared\Helpers\site_url;
use function array_filter;
use function function_exists;
use function is_array;
use function json_decode;
use function rtrim;

final class SchemaFactoryService
{
    /**
     * @param array $item
     * @param array $seo
     * @param string $kind
     * @return Schema
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public function make(array $item, array $seo, string $kind): Schema
    {
        $schemaType = (string) ($seo['schema_type'] ?? '');

        if ($schemaType === '' || $schemaType === 'Default') {
            $schemaType = match ($kind) {
                'product' => 'Product',
                'home' => SimpleSeoSettings::get('homepage_schema_type', 'WebSite'),
                'page' => 'WebPage',
                default => 'Article',
            };
        }

        if (!empty($seo['schema_json'])) {
            $decoded = $this->validSchemaJson((string) $seo['schema_json']);

            if ($decoded !== null) {
                $type = (string) $decoded['@type'];
                unset($decoded['@context'], $decoded['@type']);

                return new Schema(new Thing($type, $decoded));
            }
        }

        $nodes = [];

        if ($kind === 'home') {
            $nodes[] = new Thing('WebSite', $this->withoutEmpty($this->website($item, $seo)));
            $nodes[] = new Thing('WebPage', $this->withoutEmpty($this->webpage($item, $seo)));
            $this->addOrganization($nodes);

            return new Schema(...$nodes);
        }

        if ($schemaType === 'Product' || $kind === 'product') {
            $nodes[] = new Thing('Product', $this->withoutEmpty($this->product($item, $seo)));
            $nodes[] = new Thing('WebPage', $this->withoutEmpty($this->webpage($item, $seo)));
            $this->addOrganization($nodes);

            return new Schema(...$nodes);
        }

        if ($schemaType === 'WebSite') {
            $nodes[] = new Thing('WebSite', $this->withoutEmpty($this->website($item, $seo)));
            $nodes[] = new Thing('WebPage', $this->withoutEmpty($this->webpage($item, $seo)));
            $this->addOrganization($nodes);

            return new Schema(...$nodes);
        }

        if ($schemaType === 'WebPage') {
            if ($this->isHomepage($item)) {
                $nodes[] = new Thing('WebSite', $this->withoutEmpty($this->website($item, $seo)));
            }

            $nodes[] = new Thing('WebPage', $this->withoutEmpty($this->webpage($item, $seo)));
            $this->addOrganization($nodes);

            return new Schema(...$nodes);
        }

        $nodes[] = new Thing($schemaType ?: 'Article', $this->withoutEmpty(
            $this->articleLike($item, $seo, $schemaType ?: 'Article')
        ));

        $nodes[] = new Thing('WebPage', $this->withoutEmpty($this->webpage($item, $seo)));

        $this->addOrganization($nodes);

        return new Schema(...$nodes);
    }

    private function withoutEmpty(array $data): array
    {
        return array_filter($data, static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function schemaDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? $date : date('c', $timestamp);
    }

    private function schemaId(string $url, string $type): string
    {
        $entityUrl = rtrim($url !== '' ? $url : site_url(), '/') . '/';
        $siteUrl = rtrim(site_url(), '/') . '/';

        return match ($type) {
            'WebSite' => $siteUrl . '#website',
            'Organization' => $this->organizationId(),
            'WebPage' => $entityUrl . '#webpage',
            'Product' => $entityUrl . '#product',
            'Article' => $entityUrl . '#article',
            'BlogPosting' => $entityUrl . '#blogposting',
            'NewsArticle' => $entityUrl . '#newsarticle',
            default => $entityUrl . '#' . strtolower($type),
        };
    }

    /**
     * @param array $item
     * @param array $seo
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function website(array $item, array $seo): array
    {
        return [
            '@id' => rtrim(site_url(), '/') . '/#website',
            'url' => rtrim(site_url(), '/') . '/',
            'name' => SimpleSeoSettings::get('site_name', $this->title($item, $seo)),
        ];
    }

    /**
     * @param array $item
     * @param array $seo
     * @return array
     * @throws \Qubus\Exception\Exception
     */
    private function webpage(array $item, array $seo): array
    {
        $url = rtrim((string) ($item['url'] ?? site_url()), '/') . '/';

        return [
            '@id' => $this->schemaId($url, 'WebPage'),
            'url' => $url,
            'name' => $this->title($item, $seo),
            'description' => $this->description($item, $seo),
            'isPartOf' => [
                '@id' => rtrim(site_url(), '/') . '/#website',
            ],
        ];
    }

    private function product(array $item, array $seo): array
    {
        $url = (string) ($item['url'] ?? '');
        $image = $this->image($item, $seo);

        return [
            '@id' => $this->schemaId($url, 'Product'),
            'name' => $this->title($item, $seo),
            'description' => $this->description($item, $seo),
            'sku' => $item['sku'] ?? null,
            'image' => $image ?: null,
            'offers' => new Thing('Offer', $this->withoutEmpty([
                'availability' => 'https://schema.org/InStock',
                'priceCurrency' => $item['currency'] ?? 'USD',
                'price' => $item['price'] ?? '0',
                'url' => $url,
            ])),
        ];
    }

    private function articleLike(array $item, array $seo, string $type): array
    {
        $url = (string) ($item['url'] ?? '');
        $organizationId = $this->organizationId();

        $titleKey = in_array($type, ['Article', 'BlogPosting', 'NewsArticle'], true)
            ? 'headline'
            : 'name';

        return [
            '@id' => $this->schemaId($url, $type),
            $titleKey => $this->title($item, $seo),
            'description' => $this->description($item, $seo),
            'image' => $this->image($item, $seo) ?: null,
            'datePublished' => $this->schemaDate($item['published_at'] ?? $item['created_at'] ?? null),
            'dateModified' => $this->schemaDate($item['updated_at'] ?? null),
            'author' => SimpleSeoSettings::get('organization_name', '') !== ''
                ? ['@id' => $organizationId]
                : null,
            'mainEntityOfPage' => [
                '@id' => $this->schemaId($url, 'WebPage'),
            ],
        ];
    }

    /**
     * @param array $nodes
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function addOrganization(array &$nodes): void
    {
        $name = SimpleSeoSettings::get('organization_name', '');

        if ($name === '') {
            return;
        }

        $type = $this->organizationSchemaType();

        $data = $this->withoutEmpty([
            '@id' => $this->organizationId(),
            'name' => $name,
            'url' => site_url(),
            'logo' => SimpleSeoSettings::get('organization_logo', ''),
        ]);

        if ($type === 'Person') {
            unset($data['logo']);

            $image = SimpleSeoSettings::get('organization_logo', '');

            if ($image !== '') {
                $data['image'] = $image;
            }
        }

        $nodes[] = new Thing($type, $data);
    }

    private function title(array $item, array $seo): string
    {
        return (string) (($seo['meta_title'] ?? '') !== ''
            ? $seo['meta_title']
            : ($item['title'] ?? ''));
    }

    private function description(array $item, array $seo): string
    {
        return (string) (($seo['meta_description'] ?? '') !== ''
            ? $seo['meta_description']
            : ($item['description'] ?? ''));
    }

    /**
     * @param array $item
     * @param array $seo
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function image(array $item, array $seo): string
    {
        return (string) (($seo['facebook_image'] ?? '') !== ''
            ? $seo['facebook_image']
            : (($seo['twitter_image'] ?? '') !== ''
                ? $seo['twitter_image']
                : ($item['image'] ?? SimpleSeoSettings::get('default_social_image', ''))));
    }

    /**
     * @param array $item
     * @return bool
     * @throws \Qubus\Exception\Exception
     */
    private function isHomepage(array $item): bool
    {
        $url = rtrim((string) ($item['url'] ?? ''), '/') . '/';
        $home = rtrim(site_url(), '/') . '/';

        return $url === $home;
    }

    /**
     * @return string
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    private function organizationSchemaType(): string
    {
        $type = (string) SimpleSeoSettings::get(
            'organization_schema_type',
            'Organization'
        );

        $allowed = [
            'Organization',
            'Person',
            'LocalBusiness',
            'Corporation',
            'EducationalOrganization',
            'NGO',
        ];

        return in_array($type, $allowed, true)
            ? $type
            : 'Organization';
    }

    /**
     * @return string
     * @throws InvalidArgumentException
     * @throws Exception
     * @throws ReflectionException
     */
    private function organizationId(): string
    {
        return match ($this->organizationSchemaType()) {
            'Person' => rtrim(site_url(), '/') . '/#person',
            'LocalBusiness' => rtrim(site_url(), '/') . '/#localbusiness',
            default => rtrim(site_url(), '/') . '/#organization',
        };
    }

    private function validSchemaJson(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return null;
        }

        if (empty($decoded['@type']) || !is_string($decoded['@type'])) {
            return null;
        }

        $type = $decoded['@type'];

        if (!preg_match('/^[A-Za-z]+$/', $type)) {
            return null;
        }

        return $decoded;
    }
}
