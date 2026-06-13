<?php

declare(strict_types=1);

namespace Plugin\SimpleSeo\Support;

use function App\Shared\Helpers\delete_option;
use function App\Shared\Helpers\get_option;
use function App\Shared\Helpers\update_option;
use function is_array;

final class GoogleTokenStore
{
    public const string OPTION_KEY = 'simple_seo_google_tokens';

    /**
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function all(): array
    {
        $tokens = get_option(key: self::OPTION_KEY, default: []);

        return is_array($tokens) ? $tokens : [];
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function get(string $key, mixed $default = ''): mixed
    {
        $tokens = self::all();

        return $tokens[$key] ?? $default;
    }

    /**
     * @param array $tokens
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function save(array $tokens): void
    {
        update_option(key: self::OPTION_KEY, value: $tokens);
    }

    /**
     * @param array $tokens
     * @return void
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Qubus\Exception\Data\TypeException
     * @throws \Qubus\Exception\Exception
     * @throws \ReflectionException
     */
    public static function set(array $tokens): void
    {
        self::save(array_replace(self::all(), $tokens));
    }

    /**
     * @return void
     * @throws \Exception
     */
    public static function clear(): void
    {
        delete_option(key: self::OPTION_KEY);
    }
}
