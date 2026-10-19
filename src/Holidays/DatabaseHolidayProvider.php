<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Support\HolidayKeyValidator;

final class DatabaseHolidayProvider implements RangePrefetchableHolidayProviderInterface
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

    /** @var array<string, Collection<int, WorkdayHolidayRule>> Unvalidated until this profile is used. */
    private array $prefetchedRulesByProfile = [];

    /** @var array<string, Collection<int, WorkdaySpecialDate>> Unvalidated until this date is used. */
    private array $prefetchedSpecialDatesByProfileAndDate = [];

    /** @var array<string, list<array{string, string}>> Includes negative lookups without materializing every date. */
    private array $prefetchedRangesByProfile = [];

    public function prefetch(array $profiles, string $from, string $through): void
    {
        $profiles = $this->prefetchProfiles($profiles, $from, $through);

        if ($profiles === []) {
            return;
        }

        $missingRules = array_values(array_filter($profiles, fn (string $profile): bool => ! array_key_exists($profile, $this->rulesByProfile)
            && ! array_key_exists($profile, $this->prefetchedRulesByProfile)));

        try {
            $rules = $missingRules === [] ? new Collection : $this->withMembership(
                WorkdayHolidayRule::query()->whereIn('profile', $missingRules)->where('is_active', true),
                $missingRules,
            )->get();
            // DATE() matches the existing whereDate lookup, including Eloquent's SQLite
            // timestamp storage and the complete final date. It does not cast unused rows.
            $dates = $this->withMembership(
                WorkdaySpecialDate::query()->whereIn('profile', $profiles)
                    ->whereDate('date', '>=', $from)->whereDate('date', '<=', $through)
                    ->where('is_active', true),
                $profiles,
            )->selectRaw('DATE(date) as workdays_prefetch_date')->get();
        } catch (QueryException $exception) {
            throw new RuntimeException(self::MISSING_TABLES_MESSAGE, previous: $exception);
        }

        foreach ($missingRules as $profile) {
            $this->prefetchedRulesByProfile[$profile] = new Collection;
        }

        foreach ($rules as $rule) {
            foreach ($this->memberships($rule, $missingRules) as $profile) {
                $this->prefetchedRulesByProfile[$profile]->push($rule);
            }
        }

        $grouped = [];

        foreach ($dates as $date) {
            foreach ($this->memberships($date, $profiles) as $profile) {
                $key = $profile.'|'.$date->getRawOriginal('workdays_prefetch_date');
                ($grouped[$key] ??= new Collection)->push($date);
            }
        }

        foreach ($grouped as $key => $datesForProfile) {
            $this->prefetchedSpecialDatesByProfileAndDate[$key] = $datesForProfile;
        }

        foreach ($profiles as $profile) {
            $this->prefetchedRangesByProfile[$profile][] = [$from, $through];
        }
    }

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
        return $this->customHoliday($profile, $date) !== null;
    }

    public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday
    {
        return $this->specialDate($profile, $date, self::TYPE_HOLIDAY);
    }

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
    {
        return $this->extraWorkingDay($profile, $date) !== null;
    }

    public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday
    {
        return $this->specialDate($profile, $date, self::TYPE_WORKING_DAY);
    }

    private function specialDate(string $profile, CarbonImmutable $date, string $type): ?Holiday
    {
        $specialDate = $this->specialDatesForProfileAndDate($profile, $date)
            ->first(static fn (WorkdaySpecialDate $specialDate): bool => $specialDate->type === $type);

        if (! $specialDate instanceof WorkdaySpecialDate) {
            return null;
        }

        return new Holiday(
            date: $date,
            name: is_string($specialDate->title) ? $specialDate->title : null,
            source: 'database',
        );
    }

    /**
     * @return Collection<int, WorkdayHolidayRule>
     */
    private function rulesForProfile(string $profile): Collection
    {
        if (! array_key_exists($profile, $this->rulesByProfile)) {
            if (array_key_exists($profile, $this->prefetchedRulesByProfile)) {
                $rules = $this->prefetchedRulesByProfile[$profile];
            } else {
                try {
                    $rules = WorkdayHolidayRule::query()
                        ->where('profile', $profile)
                        ->where('is_active', true)
                        ->get();
                } catch (QueryException $exception) {
                    throw new RuntimeException(self::MISSING_TABLES_MESSAGE, previous: $exception);
                }
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
            $prefetched = $this->prefetchedSpecialDatesByProfileAndDate[$key] ?? new Collection;

            if ($this->prefetchedDate($profile, $date->toDateString()) && ! $this->ambiguousSpecialDates($prefetched)) {
                $specialDates = $prefetched;
            } else {
                try {
                    $specialDates = WorkdaySpecialDate::query()
                        ->where('profile', $profile)
                        ->whereDate('date', $date->toDateString())
                        ->where('is_active', true)
                        ->get();
                } catch (QueryException $exception) {
                    throw new RuntimeException(self::MISSING_TABLES_MESSAGE, previous: $exception);
                }
            }

            $this->specialDatesByProfileAndDate[$key] = $specialDates
                ->each(fn (WorkdaySpecialDate $specialDate): WorkdaySpecialDate => $this->validateSpecialDate($specialDate));
        }

        return $this->specialDatesByProfileAndDate[$key];
    }

    /** SQLite DATE columns can retain distinct raw timestamps for the same date/type. */
    private function ambiguousSpecialDates(Collection $rows): bool
    {
        $types = [];

        foreach ($rows as $row) {
            $type = $row->getRawOriginal('type');

            if (isset($types[$type])) {
                // Preserve the lazy query's exact first-row precedence for this rare case.
                return true;
            }

            $types[$type] = true;
        }

        return false;
    }

    /** @return list<string> */
    private function prefetchProfiles(array $profiles, string $from, string $through): array
    {
        if (! array_is_list($profiles) || count($profiles) > 50) {
            throw new InvalidArgumentException('Holiday prefetch requires a list of at most 50 profile names.');
        }

        foreach ($profiles as $profile) {
            if (! is_string($profile) || $profile === '') {
                throw new InvalidArgumentException('Holiday prefetch profile names must be nonempty strings.');
            }
        }

        foreach ([$from, $through] as $label) {
            if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $label, $parts) !== 1
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new InvalidArgumentException('Holiday prefetch dates must be strict Gregorian Y-m-d labels.');
            }
        }

        $zone = new DateTimeZone('UTC');
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $through, $zone);

        if ($from > $through || (int) $first->diff($last)->format('%a') > 61) {
            throw new InvalidArgumentException('Holiday prefetch must cover 1 through 62 inclusive Gregorian date labels.');
        }

        return array_values(array_unique($profiles, SORT_STRING));
    }

    /** SQL equality preserves database collation, including aliases matching the same stored profile. */
    private function withMembership(Builder $query, array $profiles): Builder
    {
        $query->select('*');
        $column = $query->getQuery()->getGrammar()->wrap('profile');

        // Two 25-bit masks stay exact on both 32-bit PHP and the supported SQL engines.
        foreach ([0, 1] as $group) {
            $parts = [];
            $bindings = [];

            foreach (array_slice($profiles, $group * 25, 25) as $bit => $profile) {
                $parts[] = '(CASE WHEN '.$column.' = ? THEN '.(1 << $bit).' ELSE 0 END)';
                $bindings[] = $profile;
            }

            $query->selectRaw(($parts === [] ? '0' : implode(' + ', $parts)).' as workdays_prefetch_mask_'.$group, $bindings);
        }

        return $query;
    }

    /** @return list<string> */
    private function memberships(WorkdayHolidayRule|WorkdaySpecialDate $row, array $profiles): array
    {
        $members = [];

        foreach ($profiles as $index => $profile) {
            $mask = (int) $row->getRawOriginal('workdays_prefetch_mask_'.intdiv($index, 25));

            if (($mask & (1 << ($index % 25))) !== 0) {
                $members[] = $profile;
            }
        }

        return $members;
    }

    private function prefetchedDate(string $profile, string $label): bool
    {
        foreach ($this->prefetchedRangesByProfile[$profile] ?? [] as [$from, $through]) {
            if ($label >= $from && $label <= $through) {
                return true;
            }
        }

        return false;
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
