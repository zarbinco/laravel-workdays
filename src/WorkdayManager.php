<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayResult;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\DayInfo;
use Zarbinco\LaravelWorkdays\Holidays\ChainHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\ConfigHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\DatabaseHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\StorageDriver;

final class WorkdayManager
{
    public function profile(string $profile): BusinessDayCalculator
    {
        $profiles = $this->profiles();

        if (! array_key_exists($profile, $profiles)) {
            throw new InvalidArgumentException(sprintf('Workdays profile [%s] is not configured.', $profile));
        }

        $profileConfig = ProfileConfigValidator::validate($profile, $profiles[$profile]);

        return new BusinessDayCalculator(
            profile: $profile,
            profileConfig: $profileConfig,
            includeStartDate: $this->includeStartDate(),
            maxScanDays: $this->maxScanDays(),
            hijriCalendar: HijriCalendarAdapter::fromConfig(),
            holidayProvider: $this->holidayProvider($profiles),
        );
    }

    public function isBusinessDay(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isBusinessDay($date);
    }

    public function explain(string|DateTimeInterface $date): DayInfo
    {
        return $this->defaultCalculator()->explain($date);
    }

    public function isHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isHoliday($date);
    }

    public function isNonWorkingDay(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isNonWorkingDay($date);
    }

    public function isWeekend(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isWeekend($date);
    }

    public function isCalendarHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isCalendarHoliday($date);
    }

    public function isGregorianHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isGregorianHoliday($date);
    }

    public function isJalaliHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isJalaliHoliday($date);
    }

    public function isHijriHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isHijriHoliday($date);
    }

    public function isCustomHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isCustomHoliday($date);
    }

    public function isExtraWorkingDay(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isExtraWorkingDay($date);
    }

    public function addBusinessDays(string|DateTimeInterface $date, int $days): CarbonImmutable
    {
        return $this->defaultCalculator()->addBusinessDays($date, $days);
    }

    public function subBusinessDays(string|DateTimeInterface $date, int $days): CarbonImmutable
    {
        return $this->defaultCalculator()->subBusinessDays($date, $days);
    }

    public function nextBusinessDay(string|DateTimeInterface $date): CarbonImmutable
    {
        return $this->defaultCalculator()->nextBusinessDay($date);
    }

    public function previousBusinessDay(string|DateTimeInterface $date): CarbonImmutable
    {
        return $this->defaultCalculator()->previousBusinessDay($date);
    }

    public function diffBusinessDays(string|DateTimeInterface $startDate, string|DateTimeInterface $endDate): int
    {
        return $this->defaultCalculator()->diffBusinessDays($startDate, $endDate);
    }

    public function calculate(string|DateTimeInterface $date, int $businessDays): BusinessDayResult
    {
        return $this->defaultCalculator()->calculate($date, $businessDays);
    }

    private function defaultCalculator(): BusinessDayCalculator
    {
        $profile = config('workdays.default_profile');

        if (! is_string($profile) || $profile === '') {
            throw new InvalidArgumentException('The workdays default_profile config value must be a non-empty string.');
        }

        return $this->profile($profile);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function profiles(): array
    {
        $profiles = config('workdays.profiles', []);

        if (! is_array($profiles)) {
            throw new InvalidArgumentException('The workdays profiles config value must be an array.');
        }

        return $profiles;
    }

    private function includeStartDate(): bool
    {
        return ProfileConfigValidator::validateIncludeStartDate(config('workdays.include_start_date', false));
    }

    private function maxScanDays(): int
    {
        return ProfileConfigValidator::validateMaxScanDays(config('workdays.max_scan_days', 3660));
    }

    /**
     * @param  array<string, array<string, mixed>>  $profiles
     */
    private function holidayProvider(array $profiles): HolidayProviderInterface
    {
        $driver = config('workdays.storage.driver', 'config');

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('The workdays storage.driver config value must be a non-empty string.');
        }

        $configProvider = new ConfigHolidayProvider($profiles);

        return match ($driver) {
            StorageDriver::CONFIG => $configProvider,
            StorageDriver::DATABASE => new DatabaseHolidayProvider,
            StorageDriver::CHAIN => new ChainHolidayProvider($configProvider, new DatabaseHolidayProvider),
            default => throw new InvalidArgumentException(StorageDriver::unsupportedMessage($driver)),
        };
    }
}
