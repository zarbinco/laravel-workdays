<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;

interface HolidayProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function recurringHolidays(string $profile, string $calendar): array;

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool;

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool;
}
