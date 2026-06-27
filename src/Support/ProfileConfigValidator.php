<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Closure;
use InvalidArgumentException;

final class ProfileConfigValidator
{
    /**
     * @return array<string, mixed>
     */
    public static function validate(string $profile, mixed $profileConfig): array
    {
        if (! is_array($profileConfig)) {
            throw new InvalidArgumentException(sprintf('The workdays profile [%s] config must be an array.', $profile));
        }

        if (! array_key_exists('weekends', $profileConfig) || ! is_array($profileConfig['weekends'])) {
            throw new InvalidArgumentException(sprintf('The weekends config for profile [%s] must be an array.', $profile));
        }

        if (array_key_exists('holidays', $profileConfig)) {
            self::validateHolidays($profile, $profileConfig['holidays']);
        }

        if (array_key_exists('custom_holidays', $profileConfig)) {
            self::validateExactDates($profile, 'custom_holidays', $profileConfig['custom_holidays']);
        }

        if (array_key_exists('extra_working_days', $profileConfig)) {
            self::validateExactDates($profile, 'extra_working_days', $profileConfig['extra_working_days']);
        }

        return $profileConfig;
    }

    public static function validateMaxScanDays(mixed $maxScanDays): int
    {
        if (! is_int($maxScanDays) || $maxScanDays < 1) {
            throw new InvalidArgumentException('The workdays max_scan_days config value must be a positive integer.');
        }

        return $maxScanDays;
    }

    public static function validateIncludeStartDate(mixed $includeStartDate): bool
    {
        if (is_array($includeStartDate) || is_object($includeStartDate)) {
            throw new InvalidArgumentException('The workdays include_start_date config value must be boolean-compatible.');
        }

        return (bool) $includeStartDate;
    }

    private static function validateHolidays(string $profile, mixed $holidays): void
    {
        if (! is_array($holidays)) {
            throw new InvalidArgumentException(sprintf('The holidays config for profile [%s] must be an array.', $profile));
        }

        foreach (HolidayKeyValidator::supportedCalendars() as $calendar) {
            if (! array_key_exists($calendar, $holidays)) {
                continue;
            }

            if (! is_array($holidays[$calendar])) {
                throw new InvalidArgumentException(sprintf('The holidays.%s config for profile [%s] must be an array.', $calendar, $profile));
            }
        }
    }

    private static function validateExactDates(string $profile, string $section, mixed $dates): void
    {
        if (! is_array($dates)) {
            throw new InvalidArgumentException(sprintf('The %s config for profile [%s] must be an array.', $section, $profile));
        }

        foreach ($dates as $date => $label) {
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                self::throwInvalidExactDate($profile, $section, (string) $date);
            }

            try {
                DateNormalizer::toImmutable($date);
            } catch (InvalidArgumentException) {
                self::throwInvalidExactDate($profile, $section, $date);
            }

            if (! is_string($label) && $label !== null) {
                $type = $label instanceof Closure ? 'Closure' : get_debug_type($label);

                throw new InvalidArgumentException(sprintf(
                    'The %s value for profile [%s] and date [%s] must be a string or null; %s given.',
                    $section,
                    $profile,
                    $date,
                    $type,
                ));
            }
        }
    }

    private static function throwInvalidExactDate(string $profile, string $section, string $date): never
    {
        throw new InvalidArgumentException(sprintf(
            'Invalid %s date key [%s] for profile [%s]. Expected a valid Gregorian date in Y-m-d format.',
            $section,
            $date,
            $profile,
        ));
    }
}
