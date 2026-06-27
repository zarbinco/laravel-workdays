<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Data\Holiday;

interface HolidayProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function recurringHolidays(string $profile, string $calendar): array;

    public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday;

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool;

    public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday;

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool;
}
