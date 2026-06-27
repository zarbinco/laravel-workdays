<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;

final readonly class ChainHolidayProvider implements HolidayProviderInterface
{
    public function __construct(
        private HolidayProviderInterface $configProvider,
        private HolidayProviderInterface $databaseProvider,
    ) {
    }

    public function recurringHolidays(string $profile, string $calendar): array
    {
        return array_replace(
            $this->configProvider->recurringHolidays($profile, $calendar),
            $this->databaseProvider->recurringHolidays($profile, $calendar),
        );
    }

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
    {
        return $this->configProvider->hasCustomHoliday($profile, $date)
            || $this->databaseProvider->hasCustomHoliday($profile, $date);
    }

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
    {
        return $this->configProvider->hasExtraWorkingDay($profile, $date)
            || $this->databaseProvider->hasExtraWorkingDay($profile, $date);
    }
}
