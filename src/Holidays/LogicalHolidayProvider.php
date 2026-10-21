<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Support\HolidayKeyValidator;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;

final class LogicalHolidayProvider implements HolidayProviderInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $validatedProfiles = [];

    private readonly Closure $resolveProfile;

    /** @param callable(string): array $resolveProfile Effective logical definitions under selected identity. */
    public function __construct(callable $resolveProfile, private readonly string $source = 'config')
    {
        $this->resolveProfile = Closure::fromCallable($resolveProfile);
    }

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
            source: $this->source,
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
            source: $this->source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function profileConfig(string $profile): array
    {
        return $this->validatedProfiles[$profile] ??= ProfileConfigValidator::validate($profile, ($this->resolveProfile)($profile));
    }
}
