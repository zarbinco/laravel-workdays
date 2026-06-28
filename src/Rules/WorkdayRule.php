<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Rules;

use DateTimeInterface;

final class WorkdayRule
{
    public static function businessDay(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isBusinessDay', true, $profile, 'The :attribute must be a business day.');
    }

    public static function nonWorkingDay(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isNonWorkingDay', true, $profile, 'The :attribute must be a non-working day.');
    }

    public static function weekend(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isWeekend', true, $profile, 'The :attribute must be a weekend.');
    }

    public static function calendarHoliday(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isCalendarHoliday', true, $profile, 'The :attribute must be a calendar holiday.');
    }

    public static function customHoliday(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isCustomHoliday', true, $profile, 'The :attribute must be a custom holiday.');
    }

    public static function extraWorkingDay(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isExtraWorkingDay', true, $profile, 'The :attribute must be an extra working day.');
    }

    public static function notBusinessDay(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isBusinessDay', false, $profile, 'The :attribute must not be a business day.');
    }

    public static function notWeekend(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isWeekend', false, $profile, 'The :attribute must not be a weekend.');
    }

    public static function notCalendarHoliday(?string $profile = null): WorkdayDateRule
    {
        return new WorkdayDateRule('isCalendarHoliday', false, $profile, 'The :attribute must not be a calendar holiday.');
    }

    public static function businessTime(?string $profile = null): WorkdayBusinessTimeRule
    {
        return new WorkdayBusinessTimeRule(true, $profile);
    }

    public static function notBusinessTime(?string $profile = null): WorkdayBusinessTimeRule
    {
        return new WorkdayBusinessTimeRule(false, $profile);
    }

    public static function afterBusinessDays(int $days, string|DateTimeInterface|null $from = null, ?string $profile = null): RelativeBusinessDaysRule
    {
        return new RelativeBusinessDaysRule('after', $days, $from, $profile);
    }

    public static function beforeBusinessDays(int $days, string|DateTimeInterface|null $from = null, ?string $profile = null): RelativeBusinessDaysRule
    {
        return new RelativeBusinessDaysRule('before', $days, $from, $profile);
    }
}
