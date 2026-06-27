<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use InvalidArgumentException;

final class HolidayKeyValidator
{
    private const CALENDAR_GREGORIAN = 'gregorian';

    private const CALENDAR_JALALI = 'jalali';

    private const CALENDAR_HIJRI = 'hijri';

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

    /**
     * @var array<int, int>
     */
    private const HIJRI_DAYS_IN_MONTH = [
        1 => 30,
        2 => 30,
        3 => 30,
        4 => 30,
        5 => 30,
        6 => 30,
        7 => 30,
        8 => 30,
        9 => 30,
        10 => 30,
        11 => 30,
        12 => 30,
    ];

    public static function validateGregorian(string $key, string $profile): void
    {
        self::validate(self::CALENDAR_GREGORIAN, $key, $profile);
    }

    public static function validateJalali(string $key, string $profile): void
    {
        self::validate(self::CALENDAR_JALALI, $key, $profile);
    }

    public static function validateHijri(string $key, string $profile): void
    {
        self::validate(self::CALENDAR_HIJRI, $key, $profile);
    }

    /**
     * @return array<int, string>
     */
    public static function supportedCalendars(): array
    {
        return [
            self::CALENDAR_GREGORIAN,
            self::CALENDAR_JALALI,
            self::CALENDAR_HIJRI,
        ];
    }

    public static function isSupportedCalendar(string $calendar): bool
    {
        return in_array($calendar, self::supportedCalendars(), true);
    }

    public static function supportedList(): string
    {
        return implode(', ', self::supportedCalendars());
    }

    public static function validate(string $calendar, string $key, string $profile): void
    {
        if (! preg_match('/^(?<month>\d{2})-(?<day>\d{2})$/', $key, $matches)) {
            self::throwInvalidKey($key, $profile, $calendar);
        }

        self::validateMonthDay($calendar, (int) $matches['month'], (int) $matches['day'], $profile, $key);
    }

    public static function validateMonthDay(string $calendar, int $month, int $day, string $profile, ?string $key = null): void
    {
        $daysInMonth = self::daysInMonth($calendar, $profile, $key ?? sprintf('%02d-%02d', $month, $day));

        if (! array_key_exists($month, $daysInMonth) || $day < 1 || $day > $daysInMonth[$month]) {
            self::throwInvalidKey($key ?? sprintf('%02d-%02d', $month, $day), $profile, $calendar);
        }
    }

    /**
     * @return array<int, int>
     */
    private static function daysInMonth(string $calendar, string $profile, string $key): array
    {
        return match ($calendar) {
            self::CALENDAR_GREGORIAN => self::GREGORIAN_DAYS_IN_MONTH,
            self::CALENDAR_JALALI => self::JALALI_DAYS_IN_MONTH,
            self::CALENDAR_HIJRI => self::HIJRI_DAYS_IN_MONTH,
            default => throw new InvalidArgumentException(sprintf(
                'Invalid recurring holiday calendar type [%s] for profile [%s] and key [%s].',
                $calendar,
                $profile,
                $key,
            )),
        };
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
