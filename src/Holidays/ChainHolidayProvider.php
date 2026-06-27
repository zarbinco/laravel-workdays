<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Data\Holiday;

final readonly class ChainHolidayProvider implements HolidayProviderInterface
{
    public function __construct(
        private HolidayProviderInterface $configProvider,
        private HolidayProviderInterface $databaseProvider,
    ) {}

    public function recurringHolidays(string $profile, string $calendar): array
    {
        return array_replace(
            $this->configProvider->recurringHolidays($profile, $calendar),
            $this->databaseProvider->recurringHolidays($profile, $calendar),
        );
    }

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
    {
        return $this->customHoliday($profile, $date) !== null;
    }

    public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday
    {
        return $this->databaseProvider->customHoliday($profile, $date)
            ?? $this->configProvider->customHoliday($profile, $date);
    }

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
    {
        return $this->extraWorkingDay($profile, $date) !== null;
    }

    public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday
    {
        return $this->databaseProvider->extraWorkingDay($profile, $date)
            ?? $this->configProvider->extraWorkingDay($profile, $date);
    }
}
