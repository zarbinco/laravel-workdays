<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Facades;

use Illuminate\Support\Facades\Facade;
use Zarbinco\LaravelWorkdays\WorkdayManager;

/**
 * @method static \Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator profile(string $profile)
 * @method static \Zarbinco\LaravelWorkdays\Data\DayInfo explain(string|\DateTimeInterface $date)
 * @method static bool isBusinessDay(string|\DateTimeInterface $date)
 * @method static bool isHoliday(string|\DateTimeInterface $date)
 * @method static bool isNonWorkingDay(string|\DateTimeInterface $date)
 * @method static bool isWeekend(string|\DateTimeInterface $date)
 * @method static bool isCalendarHoliday(string|\DateTimeInterface $date)
 * @method static bool isGregorianHoliday(string|\DateTimeInterface $date)
 * @method static bool isJalaliHoliday(string|\DateTimeInterface $date)
 * @method static bool isHijriHoliday(string|\DateTimeInterface $date)
 * @method static bool isCustomHoliday(string|\DateTimeInterface $date)
 * @method static bool isExtraWorkingDay(string|\DateTimeInterface $date)
 * @method static bool isBusinessTime(string|\DateTimeInterface $datetime)
 * @method static array<int, \Zarbinco\LaravelWorkdays\Data\TimeWindow> workingWindowsFor(string|\DateTimeInterface $date)
 * @method static \Carbon\CarbonImmutable nextBusinessTime(string|\DateTimeInterface $datetime)
 * @method static \Carbon\CarbonImmutable previousBusinessTime(string|\DateTimeInterface $datetime)
 * @method static \Carbon\CarbonImmutable addBusinessMinutes(string|\DateTimeInterface $datetime, int $minutes)
 * @method static \Carbon\CarbonImmutable addBusinessHours(string|\DateTimeInterface $datetime, int|float $hours)
 * @method static int diffBusinessMinutes(string|\DateTimeInterface $startDate, string|\DateTimeInterface $endDate)
 * @method static float diffBusinessHours(string|\DateTimeInterface $startDate, string|\DateTimeInterface $endDate)
 * @method static \Carbon\CarbonImmutable addBusinessDays(string|\DateTimeInterface $date, int $days)
 * @method static \Carbon\CarbonImmutable subBusinessDays(string|\DateTimeInterface $date, int $days)
 * @method static \Carbon\CarbonImmutable nextBusinessDay(string|\DateTimeInterface $date)
 * @method static \Carbon\CarbonImmutable previousBusinessDay(string|\DateTimeInterface $date)
 * @method static int diffBusinessDays(string|\DateTimeInterface $startDate, string|\DateTimeInterface $endDate)
 * @method static \Zarbinco\LaravelWorkdays\Calculator\BusinessDayResult calculate(string|\DateTimeInterface $date, int $businessDays)
 *
 * @see WorkdayManager
 */
final class Workday extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'workdays';
    }
}
