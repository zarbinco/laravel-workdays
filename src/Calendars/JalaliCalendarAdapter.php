<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calendars;

use Carbon\CarbonImmutable;
use Hekmatinasser\Verta\Verta;

final class JalaliCalendarAdapter
{
    public function monthDayFromGregorian(CarbonImmutable $date): string
    {
        return Verta::instance($date)->format('m-d');
    }

    public function jalaliDateToGregorian(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::instance(
            Verta::createJalaliDate($year, $month, $day)->toCarbon(),
        )->startOfDay();
    }
}
