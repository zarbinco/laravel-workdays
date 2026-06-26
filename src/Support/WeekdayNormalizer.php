<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use InvalidArgumentException;

final class WeekdayNormalizer
{
    /**
     * @var array<string, int>
     */
    private const ENGLISH_ALIASES = [
        'monday' => 1,
        'mon' => 1,
        'tuesday' => 2,
        'tue' => 2,
        'wednesday' => 3,
        'wed' => 3,
        'thursday' => 4,
        'thu' => 4,
        'friday' => 5,
        'fri' => 5,
        'saturday' => 6,
        'sat' => 6,
        'sunday' => 7,
        'sun' => 7,
    ];

    /**
     * @var array<string, int>
     */
    private const PERSIAN_ALIASES = [
        'دوشنبه' => 1,
        'سهشنبه' => 2,
        'چهارشنبه' => 3,
        'پنجشنبه' => 4,
        'جمعه' => 5,
        'شنبه' => 6,
        'یکشنبه' => 7,
    ];

    public static function toIso(int|string $weekday): int
    {
        if (is_int($weekday)) {
            return self::validateIso($weekday);
        }

        $weekday = trim($weekday);

        if ($weekday === '') {
            throw new InvalidArgumentException('Weekday name cannot be empty.');
        }

        if (ctype_digit($weekday)) {
            return self::validateIso((int) $weekday);
        }

        $englishKey = strtolower($weekday);

        if (array_key_exists($englishKey, self::ENGLISH_ALIASES)) {
            return self::ENGLISH_ALIASES[$englishKey];
        }

        $persianKey = self::normalizePersian($weekday);

        if (array_key_exists($persianKey, self::PERSIAN_ALIASES)) {
            return self::PERSIAN_ALIASES[$persianKey];
        }

        throw new InvalidArgumentException(sprintf('Invalid weekday name [%s].', $weekday));
    }

    private static function validateIso(int $weekday): int
    {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException(sprintf('Invalid ISO weekday [%d]. Expected a value from 1 to 7.', $weekday));
        }

        return $weekday;
    }

    private static function normalizePersian(string $weekday): string
    {
        $weekday = strtr($weekday, [
            'ي' => 'ی',
            'ك' => 'ک',
        ]);

        return preg_replace('/[\s\x{200C}]+/u', '', $weekday) ?? $weekday;
    }
}
