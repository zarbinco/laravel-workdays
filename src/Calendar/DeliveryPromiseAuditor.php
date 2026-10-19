<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calendar;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseComparison;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Delivery\DeliveryPlanner;
use Zarbinco\LaravelWorkdays\Holidays\SnapshotHolidayProvider;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Support\CutoffPolicy;
use Zarbinco\LaravelWorkdays\Support\HolidayKeyValidator;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\StorageDriver;
use Zarbinco\LaravelWorkdays\Support\WeekdayNormalizer;
use Zarbinco\LaravelWorkdays\Support\WorkingHours;
use Zarbinco\LaravelWorkdays\Support\WorkingTimeBoundary;

/** Opt-in, operation-local materialization and comparison. The delivery engine stays authoritative. */
final class DeliveryPromiseAuditor
{
    public function capture(string|DateTimeInterface $receivedAt, array $plan, array $options): DeliveryPromiseSnapshot
    {
        $plan = json_decode(CalendarFingerprint::canonical($plan), true, CalendarFingerprint::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
        $validator = new DeliveryPlanner(static fn () => throw new RuntimeException('Validation does not resolve calculators.'));
        $validator->validatePlan($plan);
        $horizon = $this->horizon($options);
        $receivedAt = $validator->normalizeInput($receivedAt, $plan['timezone']);
        $evidence = $this->materialize($receivedAt, $plan, $horizon);
        $result = self::planFrozen($receivedAt, $plan, $horizon, $evidence);

        foreach ([$result->cutoffResult?->receivedAt, $result->cutoffResult?->cutoffAt, $result->cutoffResult?->effectiveBusinessDate] as $local) {
            if ($local !== null && $local->getOffset() % 60 !== 0) {
                throw new InvalidArgumentException('Delivery promise cutoff timestamps require timezone offsets in whole minutes for portable serialization.');
            }
        }

        $snapshot = new DeliveryPromiseSnapshot(1, CalendarFingerprint::hash($evidence), $receivedAt, $plan, $horizon, $result, $evidence, self::runtimeMetadata());
        CalendarFingerprint::assertJson($snapshot->toArray());

        return $snapshot;
    }

    public function compare(DeliveryPromiseSnapshot|array $snapshot): DeliveryPromiseComparison
    {
        // Validate object inputs too: public readonly DTO construction is not a validation bypass.
        $snapshot = DeliveryPromiseSnapshot::fromArray(is_array($snapshot) ? $snapshot : $snapshot->toArray());
        $runtimeChanged = CalendarFingerprint::canonical($snapshot->runtimeMetadata) !== CalendarFingerprint::canonical(self::runtimeMetadata());
        $currentVersion = null;
        $evidence = null;
        $current = null;
        $error = null;

        try {
            $this->horizon(['horizon_days' => $snapshot->horizonDays]);
            $evidence = $this->materialize($snapshot->receivedAt, $snapshot->plan, $snapshot->horizonDays);
            $currentVersion = CalendarFingerprint::hash($evidence);
            $current = self::planFrozen($snapshot->receivedAt, $snapshot->plan, $snapshot->horizonDays, $evidence)->estimatedCompletionAt;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $original = $snapshot->originalResult->estimatedCompletionAt;
        $delta = $current === null ? null : ($current->getTimestamp() - $original->getTimestamp()) + (((int) $current->format('u') - (int) $original->format('u')) / 1000000);

        if ($delta !== null && floor($delta) === $delta) {
            $delta = (int) $delta;
        }

        return new DeliveryPromiseComparison(
            $current === null ? 'unresolvable' : 'resolved',
            $snapshot->calendarVersion,
            $currentVersion,
            $currentVersion === null ? null : $snapshot->calendarVersion !== $currentVersion,
            $delta === null ? null : $delta !== 0,
            $delta === null ? 'unresolvable' : ($delta > 0 ? 'later' : ($delta < 0 ? 'earlier' : 'unchanged')),
            $original,
            $current,
            $delta,
            $evidence === null ? [] : self::changedDays($snapshot->calendarEvidence, $evidence),
            $runtimeChanged,
            $error,
            $evidence === null ? null : (self::sectionChanged($snapshot->calendarEvidence, $evidence, 'config') || self::providerSourceChanged($snapshot->calendarEvidence, $evidence, 'config')),
            $evidence === null ? null : self::sectionChanged($snapshot->calendarEvidence, $evidence, 'holiday_inputs'),
            $evidence === null ? null : self::providerSourceChanged($snapshot->calendarEvidence, $evidence, 'database'),
        );
    }

    private function horizon(array $options): int
    {
        if (array_keys($options) !== ['horizon_days'] || ! is_int($options['horizon_days']) || $options['horizon_days'] < 1 || $options['horizon_days'] > 31) {
            throw new InvalidArgumentException('Delivery promise options require only horizon_days, a strict integer from 1 to 31.');
        }

        if ($options['horizon_days'] > ProfileConfigValidator::validateMaxScanDays(config('workdays.max_scan_days', 3660))) {
            throw new InvalidArgumentException('Delivery promise horizon_days must not exceed workdays.max_scan_days.');
        }

        return $options['horizon_days'];
    }

    /** @return array<string, bool> profile => whether a time-based stage needs windows */
    public static function referencedProfiles(array $plan): array
    {
        $profiles = [];

        foreach ($plan['stages'] as $stage) {
            foreach ($stage['type'] === 'handoff' ? $stage['profiles'] : [$stage['profile']] as $profile) {
                $profiles[$profile] = ($profiles[$profile] ?? false) || $stage['type'] !== 'business_days';
            }
        }

        if (count($profiles) > 8) {
            throw new InvalidArgumentException('A delivery promise snapshot supports at most eight referenced profiles.');
        }

        ksort($profiles, SORT_STRING);

        return $profiles;
    }

    private function materialize(CarbonImmutable $receivedAt, array $plan, int $horizon): array
    {
        $selected = self::referencedProfiles($plan);
        $profiles = config('workdays.profiles', []);

        if (! is_array($profiles)) {
            throw new InvalidArgumentException('The workdays profiles config value must be an array.');
        }

        $driver = config('workdays.storage.driver', 'config');

        if (! is_string($driver) || ! StorageDriver::isSupported($driver)) {
            throw new InvalidArgumentException('Invalid delivery promise storage driver. Expected config, database or chain.');
        }

        $hijri = ['method' => config('workdays.hijri.method', 'umm_al_qura'), 'adjustment' => config('workdays.hijri.adjustment', 0)];

        if (! is_string($hijri['method']) || ! is_int($hijri['adjustment'])) {
            throw new InvalidArgumentException('Invalid Hijri method or adjustment configuration.');
        }

        new HijriCalendarAdapter($hijri['method'], $hijri['adjustment']);
        $evidence = ['schema_version' => 1, 'plan_timezone' => $plan['timezone'], 'anchor_date' => $receivedAt->setTimezone($plan['timezone'])->toDateString(), 'horizon_days' => $horizon, 'hijri' => $hijri, 'profiles' => []];
        $resolver = new ProfileConfigResolver($profiles);

        foreach ($selected as $name => $needsHours) {
            $name = (string) $name;

            if (! array_key_exists($name, $profiles)) {
                throw new InvalidArgumentException(sprintf('Workdays profile [%s] is not configured.', $name));
            }

            $effective = is_array($profiles[$name]) && array_key_exists('extends', $profiles[$name])
                ? $resolver->resolve($name)
                : ProfileConfigValidator::validate($name, $profiles[$name]);
            $coverage = self::coverage($receivedAt, $plan['timezone'], $plan['profile_timezones'][$name], $horizon);
            $evidence['profiles'][$name] = [
                'timezone' => $plan['profile_timezones'][$name],
                'needs_working_hours' => $needsHours,
                'config' => self::calendarConfig($name, $effective, $needsHours, ($plan['cutoff_profile'] ?? null) === $name, $coverage),
                'coverage' => $coverage,
                'holiday_inputs' => $this->holidayInputs($name, $effective, $driver, $coverage),
                'days' => [],
            ];
        }

        return self::withDays($evidence);
    }

    /** Inclusive observed range, plus one provider-only owner date for overnight carry-in. */
    public static function coverage(CarbonImmutable $receivedAt, string $planTimezone, string $profileTimezone, int $horizon): array
    {
        $anchor = $receivedAt->setTimezone($planTimezone)->toDateString();
        $zone = new DateTimeZone($planTimezone);
        $start = CarbonImmutable::instance(DateTimeImmutable::createFromFormat('!Y-m-d', $anchor, $zone));
        $end = CarbonImmutable::instance(DateTimeImmutable::createFromFormat('!Y-m-d', WorkingTimeBoundary::labelAfter($anchor, $horizon + 1), $zone));
        $from = WorkingTimeBoundary::labelAfter($start->setTimezone($profileTimezone)->toDateString(), -2);
        $coverage = ['from' => $from, 'through' => WorkingTimeBoundary::labelAfter($end->setTimezone($profileTimezone)->toDateString(), 2), 'provider_from' => WorkingTimeBoundary::labelAfter($from, -1)];

        foreach ($coverage as $label) {
            if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $label) || ! checkdate((int) substr($label, 5, 2), (int) substr($label, 8, 2), (int) substr($label, 0, 4))) {
                throw new InvalidArgumentException('Delivery promise date coverage must fit Gregorian years 0001 through 9999.');
            }
        }

        return $coverage;
    }

    private static function calendarConfig(string $profile, array $effective, bool $needsHours, bool $cutoff, array $coverage): array
    {
        $weekends = array_values(array_unique(array_map(WeekdayNormalizer::toIso(...), $effective['weekends'])));
        sort($weekends);
        $config = ['weekends' => $weekends];
        $overnight = ($effective['allow_overnight_working_hours'] ?? false) === true;
        $strict = WorkingHours::hasEnhancedConfig($effective);
        $windows = static fn (array $windows): array => array_map(static fn (TimeWindow $window): array => [$window->start, $window->end], $windows);
        $weekly = static function (array $hours) use ($profile, $overnight, $strict, $windows): array {
            return array_map($windows, WorkingHours::normalizeWorkingHours($profile, $hours, $overnight, $strict));
        };

        if ($needsHours) {
            if ($overnight) {
                $config['allow_overnight_working_hours'] = true;
            }

            if (array_key_exists('working_hours', $effective)) {
                $config['working_hours'] = $weekly($effective['working_hours']);
            }

            if (array_key_exists('extra_working_day_hours', $effective)) {
                $config['extra_working_day_hours'] = $windows(WorkingHours::normalizeWindows($profile, 'extra_working_day_hours', $effective['extra_working_day_hours'], $overnight, $strict));
            }

            if (array_key_exists('special_working_hours', $effective)) {
                $config['special_working_hours'] = [];

                foreach ($effective['special_working_hours'] as $date => $hours) {
                    if ($date >= $coverage['provider_from'] && $date <= $coverage['through']) {
                        $config['special_working_hours'][$date] = $windows(WorkingHours::normalizeWindows($profile, 'special_working_hours.'.$date, $hours, $overnight, true));
                    }
                }
            }

            if (array_key_exists('seasonal_working_hours', $effective)) {
                $config['seasonal_working_hours'] = [];

                foreach ($effective['seasonal_working_hours'] as $season) {
                    if ($season['from'] <= $coverage['through'] && $season['through'] >= $coverage['provider_from']) {
                        $config['seasonal_working_hours'][] = ['from' => max($season['from'], $coverage['provider_from']), 'through' => min($season['through'], $coverage['through']), 'weekdays' => $weekly($season['weekdays'])];
                    }
                }

                usort($config['seasonal_working_hours'], static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
            }
        }

        if ($cutoff) {
            CutoffPolicy::fromProfileConfig($profile, $effective);
            $value = $effective['cutoff'];
            $overrides = [];

            foreach ($value['weekdays'] ?? [] as $weekday => $time) {
                $overrides[WeekdayNormalizer::toIso($weekday)] = $time;
            }

            ksort($overrides);
            $config['cutoff'] = ['timezone' => $value['timezone'], 'time' => $value['time'], 'weekdays' => $overrides];
        }

        return $config;
    }

    private function holidayInputs(string $profile, array $effective, string $driver, array $coverage): array
    {
        $inputs = ['recurring' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom' => [], 'extra' => []];

        if ($driver !== StorageDriver::DATABASE) {
            foreach ($inputs['recurring'] as $calendar => $_) {
                foreach ($effective['holidays'][$calendar] ?? [] as $key => $title) {
                    $inputs['recurring'][$calendar][$key] = ['title' => is_string($title) ? $title : null, 'source' => 'config'];
                }
            }

            foreach (['custom' => 'custom_holidays', 'extra' => 'extra_working_days'] as $section => $key) {
                foreach ($effective[$key] ?? [] as $date => $title) {
                    if ($date >= $coverage['provider_from'] && $date <= $coverage['through']) {
                        $inputs[$section][$date] = ['title' => $title, 'source' => 'config'];
                    }
                }
            }
        }

        if ($driver !== StorageDriver::CONFIG) {
            try {
                $rules = WorkdayHolidayRule::query()->where('profile', $profile)->where('is_active', true)->get(['profile', 'calendar_type', 'month', 'day', 'title']);
                $dates = WorkdaySpecialDate::query()->where('profile', $profile)->where('is_active', true)->whereDate('date', '>=', $coverage['provider_from'])->whereDate('date', '<=', $coverage['through'])->get(['profile', 'date', 'type', 'title']);
            } catch (QueryException $exception) {
                throw new RuntimeException('Workdays database storage is enabled, but the workday tables are not available. Publish and run the workdays migrations.', previous: $exception);
            }

            foreach ($rules as $rule) {
                if (! is_string($rule->profile) || $rule->profile === '' || ! is_string($rule->calendar_type) || ! HolidayKeyValidator::isSupportedCalendar($rule->calendar_type)) {
                    throw new InvalidArgumentException('Invalid database holiday rule profile or calendar_type.');
                }

                HolidayKeyValidator::validateMonthDay($rule->calendar_type, (int) $rule->month, (int) $rule->day, $profile);
                $inputs['recurring'][$rule->calendar_type][sprintf('%02d-%02d', $rule->month, $rule->day)] = ['title' => is_string($rule->title) ? $rule->title : null, 'source' => 'database'];
            }

            foreach ($dates as $date) {
                if (! is_string($date->profile) || $date->profile === '' || ! in_array($date->type, ['holiday', 'working_day'], true) || $date->date === null) {
                    throw new InvalidArgumentException('Invalid database special date profile, type or date.');
                }

                $inputs[$date->type === 'holiday' ? 'custom' : 'extra'][$date->date->toDateString()] = ['title' => is_string($date->title) ? $date->title : null, 'source' => 'database'];
            }
        }

        return $inputs;
    }

    private static function calculator(string $profile, array $evidence, int $limit, SnapshotHolidayProvider $provider): BusinessDayCalculator
    {
        return new BusinessDayCalculator($profile, $evidence['profiles'][$profile]['config'], maxScanDays: $limit, hijriCalendar: new HijriCalendarAdapter($evidence['hijri']['method'], $evidence['hijri']['adjustment']), holidayProvider: $provider);
    }

    private static function planFrozen(CarbonImmutable $receivedAt, array $plan, int $horizon, array $evidence): DeliveryPlanResult
    {
        $provider = new SnapshotHolidayProvider($evidence['profiles']);

        return (new DeliveryPlanner(
            static fn (string $profile, int $limit): BusinessDayCalculator => self::calculator($profile, $evidence, $limit, $provider),
            static fn (string $profile): array => $evidence['profiles'][$profile]['config'],
            $horizon,
            array_keys($plan['profile_timezones']),
        ))->plan($receivedAt, $plan);
    }

    private static function withDays(array $evidence): array
    {
        foreach ($evidence['profiles'] as &$definition) {
            $definition['days'] = [];
        }
        unset($definition);

        $provider = new SnapshotHolidayProvider($evidence['profiles']);
        $nodes = 0;
        $bytes = 0;
        CalendarFingerprint::assertBudget($evidence, $nodes, $bytes);

        foreach ($evidence['profiles'] as $profile => &$definition) {
            $profile = (string) $profile;
            $calculator = self::calculator($profile, $evidence, $evidence['horizon_days'] + 3, $provider);
            $zone = new DateTimeZone($definition['timezone']);
            $days = [];

            for ($label = $definition['coverage']['from']; $label <= $definition['coverage']['through']; $label = WorkingTimeBoundary::labelAfter($label, 1)) {
                $date = WorkingTimeBoundary::date($label, $zone);
                $row = ['profile' => $profile, 'date' => $label, 'exists' => $date !== null, 'is_business_day' => false, 'is_holiday' => true, 'is_extra_working_day' => false, 'effective_working_windows' => $definition['needs_working_hours'] ? [] : null, 'holiday_reasons' => []];

                if ($date !== null) {
                    $info = $calculator->explain($date)->toArray();
                    $row['is_business_day'] = $info['is_business_day'];
                    $row['is_holiday'] = $info['is_non_working_day'];
                    $row['is_extra_working_day'] = $info['is_extra_working_day'];
                    $row['holiday_reasons'] = $info['reasons'];

                    if ($definition['needs_working_hours']) {
                        $enhanced = WorkingHours::hasEnhancedConfig($definition['config']);

                        foreach ($calculator->workingWindowsFor($date) as $window) {
                            $start = $enhanced ? WorkingTimeBoundary::at($profile, $label, $window->startMinutes(), $zone, $window->startMinutes() === 0) : $date->startOfDay()->addMinutes($window->startMinutes());
                            $end = $enhanced ? WorkingTimeBoundary::at($profile, $label, $window->endMinutes(), $zone, $window->endMinutes() === 1440) : $date->startOfDay()->addMinutes($window->endMinutes());
                            $row['effective_working_windows'][] = ['start' => $start->setTimezone('UTC')->format('Y-m-d\TH:i:s.uP'), 'end' => $end->setTimezone('UTC')->format('Y-m-d\TH:i:s.uP')];
                        }
                    }
                }

                CalendarFingerprint::assertBudget($row, $nodes, $bytes);
                $days[] = $row;
            }

            $definition['days'] = $days;
        }
        unset($definition);

        return $evidence;
    }

    /** @internal Validate portable captured data, never today's profiles or database records. */
    public static function validateFrozen(DeliveryPromiseSnapshot $snapshot): void
    {
        if (CalendarFingerprint::canonical($snapshot->runtimeMetadata) !== CalendarFingerprint::canonical(self::runtimeMetadata())) {
            return; // A different implementation environment cannot disprove historical calculated evidence.
        }

        foreach ($snapshot->calendarEvidence['profiles'] as $profile => $definition) {
            $expected = self::coverage($snapshot->receivedAt, $snapshot->plan['timezone'], $definition['timezone'], $snapshot->horizonDays);

            if (CalendarFingerprint::canonical($expected) !== CalendarFingerprint::canonical($definition['coverage'])) {
                throw new InvalidArgumentException(sprintf('Snapshot coverage is inconsistent for profile [%s].', $profile));
            }
        }

        $recomputed = self::withDays($snapshot->calendarEvidence);

        if (CalendarFingerprint::hash($recomputed) !== $snapshot->calendarVersion) {
            throw new InvalidArgumentException('Snapshot daily calendar evidence is inconsistent with the captured rules.');
        }

        // A reduced/invalid current global limit must not invalidate a portable historical result.
        $maximum = config('workdays.max_scan_days', 3660);

        if (is_int($maximum) && $maximum >= $snapshot->horizonDays) {
            $result = self::planFrozen($snapshot->receivedAt, $snapshot->plan, $snapshot->horizonDays, $snapshot->calendarEvidence);

            if (CalendarFingerprint::canonical($result->toArray()) !== CalendarFingerprint::canonical($snapshot->originalResult->toArray())) {
                throw new InvalidArgumentException('Snapshot original result is inconsistent with the captured calendar and plan.');
            }
        }
    }

    public static function runtimeMetadata(): array
    {
        $version = static fn (string $package): ?string => class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;

        return ['calculation_schema_version' => 1, 'php_version' => PHP_VERSION, 'carbon_version' => $version('nesbot/carbon'), 'verta_version' => $version('hekmatinasser/verta'), 'islamic_calendar_version' => $version('islamic-network/calendar'), 'timezone_database_version' => function_exists('timezone_version_get') ? timezone_version_get() : null];
    }

    private static function sectionChanged(array $old, array $current, string $section): bool
    {
        if ($section === 'config' && CalendarFingerprint::canonical($old['hijri']) !== CalendarFingerprint::canonical($current['hijri'])) {
            return true;
        }

        $previous = array_map(static fn (array $definition): array => $definition[$section], $old['profiles']);
        $next = array_map(static fn (array $definition): array => $definition[$section], $current['profiles']);

        return CalendarFingerprint::canonical($previous) !== CalendarFingerprint::canonical($next);
    }

    private static function providerSourceChanged(array $old, array $current, string $source): bool
    {
        $select = static function (array $evidence) use ($source): array {
            $selected = [];

            foreach ($evidence['profiles'] as $profile => $definition) {
                $inputs = $definition['holiday_inputs'];
                $matches = static fn (array $rule): bool => $rule['source'] === $source;
                $selected[$profile] = ['custom' => array_filter($inputs['custom'], $matches), 'extra' => array_filter($inputs['extra'], $matches), 'recurring' => array_map(static fn (array $rules): array => array_filter($rules, $matches), $inputs['recurring'])];
            }

            return $selected;
        };

        return CalendarFingerprint::canonical($select($old)) !== CalendarFingerprint::canonical($select($current));
    }

    private static function changedDays(array $old, array $current): array
    {
        // Normalize once, so field comparisons inside the bounded date loop need no JSON encoding.
        $old = json_decode(CalendarFingerprint::canonical($old), true, CalendarFingerprint::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
        $current = json_decode(CalendarFingerprint::canonical($current), true, CalendarFingerprint::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
        $changes = [];

        foreach ($old['profiles'] as $profile => $definition) {
            $previous = array_column($definition['days'], null, 'date');
            $next = array_column($current['profiles'][$profile]['days'], null, 'date');
            $labels = array_unique([...array_keys($previous), ...array_keys($next)]);
            sort($labels, SORT_STRING);

            foreach ($labels as $date) {
                $before = $previous[$date] ?? null;
                $after = $next[$date] ?? null;
                $fields = [];

                if ($before === null || $after === null) {
                    $fields[] = 'coverage';
                } else {
                    foreach (['exists', 'is_business_day', 'is_holiday', 'is_extra_working_day', 'effective_working_windows', 'holiday_reasons'] as $field) {
                        if ($before[$field] !== $after[$field]) {
                            $fields[] = $field;
                        }
                    }
                }

                if ($fields !== []) {
                    $changes[] = ['profile' => (string) $profile, 'date' => $date, 'changed_fields' => $fields, 'previous' => $before, 'current' => $after];
                }
            }
        }

        return $changes;
    }
}
