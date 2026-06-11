<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Support;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Qubus\Exception\Exception;
use ReflectionException;

use function htmlspecialchars;
use function strip_tags;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

final class SimpleSeoHelper
{
    /**
     * @param string|null $key
     * @param mixed|null $default
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function option(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return SimpleSeoSettings::all();
        }
        return SimpleSeoSettings::get($key, $default);
    }

    /**
     * @param string $type
     * @param string|null $id
     * @return array
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     * @throws ReflectionException
     */
    public static function attributes(string $type, ?string $id = null): array
    {
        return AttributeStore::getSeo($type, $id);
    }

    public static function text(?string $value = null): string
    {
        $value = trim((string) $value);
        $value = strip_tags($value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
