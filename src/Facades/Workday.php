<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Facades;

use Illuminate\Support\Facades\Facade;
use Zarbinco\LaravelWorkdays\WorkdayManager;

/**
 * @method static \Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator profile(string $profile)
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
