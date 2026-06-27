<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

final class StorageDriver
{
    public const CONFIG = 'config';
    public const DATABASE = 'database';
    public const CHAIN = 'chain';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::CONFIG,
            self::DATABASE,
            self::CHAIN,
        ];
    }

    public static function isSupported(string $driver): bool
    {
        return in_array($driver, self::all(), true);
    }

    public static function supportedList(): string
    {
        return implode(', ', self::all());
    }

    public static function unsupportedMessage(string $driver): string
    {
        return sprintf(
            'Unsupported workdays storage driver [%s]. Supported drivers are: %s.',
            $driver,
            self::supportedList(),
        );
    }
}
