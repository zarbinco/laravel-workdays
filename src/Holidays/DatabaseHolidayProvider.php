<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Support\HolidayKeyValidator;

final class DatabaseHolidayProvider implements HolidayProviderInterface
{
    private const TYPE_HOLIDAY = 'holiday';

    private const TYPE_WORKING_DAY = 'working_day';

    private const MISSING_TABLES_MESSAGE = 'Workdays database storage is enabled, but the workday tables are not available. Publish and run the workdays migrations.';

    /**
     * @var array<string, Collection<int, WorkdayHolidayRule>>
     */
    private array $rulesByProfile = [];

    /**
     * @var array<string, Collection<int, WorkdaySpecialDate>>
     */
    private array $specialDatesByProfileAndDate = [];

    public function recurringHolidays(string $profile, string $calendar): array
    {
        $holidays = [];

        foreach ($this->rulesForProfile($profile) as $rule) {
            if ($rule->calendar_type !== $calendar) {
                continue;
            }

            $holidays[sprintf('%02d-%02d', $rule->month, $rule->day)] = $rule->title;
        }

        return $holidays;
    }

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
    {
        return $this->specialDatesForProfileAndDate($profile, $date)
            ->contains(static fn (WorkdaySpecialDate $specialDate): bool => $specialDate->type === self::TYPE_HOLIDAY);
    }

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
    {
        return $this->specialDatesForProfileAndDate($profile, $date)
            ->contains(static fn (WorkdaySpecialDate $specialDate): bool => $specialDate->type === self::TYPE_WORKING_DAY);
    }

    /**
     * @return Collection<int, WorkdayHolidayRule>
     */
    private function rulesForProfile(string $profile): Collection
    {
        if (! array_key_exists($profile, $this->rulesByProfile)) {
            try {
                $rules = WorkdayHolidayRule::query()
                    ->where('profile', $profile)
                    ->where('is_active', true)
                    ->get();
            } catch (QueryException $exception) {
                throw new RuntimeException(self::MISSING_TABLES_MESSAGE, previous: $exception);
            }

            $this->rulesByProfile[$profile] = $rules
                ->each(fn (WorkdayHolidayRule $rule): WorkdayHolidayRule => $this->validateRule($rule));
        }

        return $this->rulesByProfile[$profile];
    }

    /**
     * @return Collection<int, WorkdaySpecialDate>
     */
    private function specialDatesForProfileAndDate(string $profile, CarbonImmutable $date): Collection
    {
        $key = $profile.'|'.$date->toDateString();

        if (! array_key_exists($key, $this->specialDatesByProfileAndDate)) {
            try {
                $specialDates = WorkdaySpecialDate::query()
                    ->where('profile', $profile)
                    ->whereDate('date', $date->toDateString())
                    ->where('is_active', true)
                    ->get();
            } catch (QueryException $exception) {
                throw new RuntimeException(self::MISSING_TABLES_MESSAGE, previous: $exception);
            }

            $this->specialDatesByProfileAndDate[$key] = $specialDates
                ->each(fn (WorkdaySpecialDate $specialDate): WorkdaySpecialDate => $this->validateSpecialDate($specialDate));
        }

        return $this->specialDatesByProfileAndDate[$key];
    }

    private function validateRule(WorkdayHolidayRule $rule): WorkdayHolidayRule
    {
        if (! is_string($rule->profile) || $rule->profile === '') {
            throw new InvalidArgumentException('Invalid database holiday rule. Profile must be a non-empty string.');
        }

        if (! is_string($rule->calendar_type) || ! in_array($rule->calendar_type, HolidayKeyValidator::supportedCalendars(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid database holiday rule calendar_type [%s]. Expected one of: %s.',
                (string) $rule->calendar_type,
                implode(', ', HolidayKeyValidator::supportedCalendars()),
            ));
        }

        HolidayKeyValidator::validateMonthDay($rule->calendar_type, (int) $rule->month, (int) $rule->day, $rule->profile);

        return $rule;
    }

    private function validateSpecialDate(WorkdaySpecialDate $specialDate): WorkdaySpecialDate
    {
        if (! is_string($specialDate->profile) || $specialDate->profile === '') {
            throw new InvalidArgumentException('Invalid database special date. Profile must be a non-empty string.');
        }

        if (! in_array($specialDate->type, [self::TYPE_HOLIDAY, self::TYPE_WORKING_DAY], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid database special date type [%s]. Expected holiday or working_day.',
                (string) $specialDate->type,
            ));
        }

        if ($specialDate->date === null) {
            throw new InvalidArgumentException('Invalid database special date. Date must be a Gregorian date.');
        }

        return $specialDate;
    }
}
