<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Support\HolidayKeyValidator;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;

final readonly class ConfigHolidayProvider implements HolidayProviderInterface
{
    /**
     * @param  array<string, array<string, mixed>>  $profiles
     */
    public function __construct(
        private array $profiles,
    ) {}

    public function recurringHolidays(string $profile, string $calendar): array
    {
        $profileConfig = $this->profileConfig($profile);
        $holidays = $profileConfig['holidays'] ?? [];

        if (! is_array($holidays)) {
            throw new InvalidArgumentException(sprintf('The holidays config for profile [%s] must be an array.', $profile));
        }

        $recurringHolidays = $holidays[$calendar] ?? [];

        if (! is_array($recurringHolidays)) {
            throw new InvalidArgumentException(sprintf('The holidays.%s config for profile [%s] must be an array.', $calendar, $profile));
        }

        foreach (array_keys($recurringHolidays) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid %s recurring holiday key for profile [%s]. Expected a valid MM-DD string.',
                    $calendar,
                    $profile,
                ));
            }

            HolidayKeyValidator::validate($calendar, $key, $profile);
        }

        return $recurringHolidays;
    }

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
    {
        return $this->customHoliday($profile, $date) !== null;
    }

    public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday
    {
        $customHolidays = $this->profileConfig($profile)['custom_holidays'] ?? [];

        if (! is_array($customHolidays)) {
            throw new InvalidArgumentException(sprintf('The custom_holidays config for profile [%s] must be an array.', $profile));
        }

        $key = $date->toDateString();

        if (! array_key_exists($key, $customHolidays)) {
            return null;
        }

        return new Holiday(
            date: $date,
            name: is_string($customHolidays[$key]) ? $customHolidays[$key] : null,
            source: 'config',
        );
    }

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
    {
        return $this->extraWorkingDay($profile, $date) !== null;
    }

    public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday
    {
        $extraWorkingDays = $this->profileConfig($profile)['extra_working_days'] ?? [];

        if (! is_array($extraWorkingDays)) {
            throw new InvalidArgumentException(sprintf('The extra_working_days config for profile [%s] must be an array.', $profile));
        }

        $key = $date->toDateString();

        if (! array_key_exists($key, $extraWorkingDays)) {
            return null;
        }

        return new Holiday(
            date: $date,
            name: is_string($extraWorkingDays[$key]) ? $extraWorkingDays[$key] : null,
            source: 'config',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function profileConfig(string $profile): array
    {
        if (! array_key_exists($profile, $this->profiles)) {
            throw new InvalidArgumentException(sprintf('Workdays profile [%s] is not configured.', $profile));
        }

        return ProfileConfigValidator::validate($profile, $this->profiles[$profile]);
    }
}
