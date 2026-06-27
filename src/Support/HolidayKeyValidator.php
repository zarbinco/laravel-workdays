<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use InvalidArgumentException;

final class HolidayKeyValidator
{
    /**
     * @var array<int, int>
     */
    private const GREGORIAN_DAYS_IN_MONTH = [
        1 => 31,
        2 => 29,
        3 => 31,
        4 => 30,
        5 => 31,
        6 => 30,
        7 => 31,
        8 => 31,
        9 => 30,
        10 => 31,
        11 => 30,
        12 => 31,
    ];

    /**
     * @var array<int, int>
     */
    private const JALALI_DAYS_IN_MONTH = [
        1 => 31,
        2 => 31,
        3 => 31,
        4 => 31,
        5 => 31,
        6 => 31,
        7 => 30,
        8 => 30,
        9 => 30,
        10 => 30,
        11 => 30,
        12 => 30,
    ];

    public static function validateGregorian(string $key, string $profile): void
    {
        self::validate($key, $profile, 'Gregorian', self::GREGORIAN_DAYS_IN_MONTH);
    }

    public static function validateJalali(string $key, string $profile): void
    {
        self::validate($key, $profile, 'Jalali', self::JALALI_DAYS_IN_MONTH);
    }

    /**
     * @param array<int, int> $daysInMonth
     */
    private static function validate(string $key, string $profile, string $calendar, array $daysInMonth): void
    {
        if (! preg_match('/^(?<month>\d{2})-(?<day>\d{2})$/', $key, $matches)) {
            self::throwInvalidKey($key, $profile, $calendar);
        }

        $month = (int) $matches['month'];
        $day = (int) $matches['day'];

        if (! array_key_exists($month, $daysInMonth) || $day < 1 || $day > $daysInMonth[$month]) {
            self::throwInvalidKey($key, $profile, $calendar);
        }
    }

    private static function throwInvalidKey(string $key, string $profile, string $calendar): never
    {
        throw new InvalidArgumentException(sprintf(
            'Invalid %s recurring holiday key [%s] for profile [%s]. Expected a valid MM-DD value.',
            strtolower($calendar),
            $key,
            $profile,
        ));
    }
}
