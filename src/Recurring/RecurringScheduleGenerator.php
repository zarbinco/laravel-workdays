<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Recurring;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hekmatinasser\Verta\Verta;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Data\RecurringOccurrence;
use Zarbinco\LaravelWorkdays\Data\RecurringScheduleResult;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\WorkingTimeBoundary;

/** Date-label recurrence; holiday adjustments never become the next cycle's anchor. */
final readonly class RecurringScheduleGenerator
{
    private Closure $resolveCalculator;

    /** @param callable(string): BusinessDayCalculator $resolveCalculator */
    public function __construct(callable $resolveCalculator)
    {
        $this->resolveCalculator = Closure::fromCallable($resolveCalculator);
    }

    /** @param array<string, mixed> $options */
    public function generate(string|DateTimeInterface $anchorDate, array $options): RecurringScheduleResult
    {
        $options = $this->options($options);
        $zone = new DateTimeZone($options['timezone']);
        $anchor = $this->anchor($anchorDate, $zone);

        try {
            $limit = ProfileConfigValidator::validateMaxScanDays(config('workdays.max_scan_days', 3660));
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException('Invalid recurring schedule horizon: '.$exception->getMessage(), previous: $exception);
        }

        // Compile and bound every requested nominal cycle before any adjustment/provider scan.
        $nominals = $this->nominals($anchor, $options, $limit);

        try {
            $calculator = ($this->resolveCalculator)($options['profile']);
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException('Invalid recurring schedule profile ['.$options['profile'].']: '.$exception->getMessage(), previous: $exception);
        }

        $eligibility = [];
        $occurrences = [];
        $scheduled = [];

        foreach ($nominals as $cycle => [$period, $nominal]) {
            $occurrence = $nominal === null
                ? new RecurringOccurrence($cycle, $period, null, null, 'skipped', 'invalid_month_day', null)
                : $this->adjust($cycle, $period, $nominal, $anchor, $options, $limit, $zone, $calculator, $eligibility);

            if ($occurrence->scheduledDate !== null) {
                $date = $occurrence->scheduledDate;

                if (isset($scheduled[$date]) && $options['collision_policy'] === 'reject') {
                    throw new RuntimeException(sprintf('Recurring schedule cycles [%d] and [%d] collide on scheduled date [%s].', $scheduled[$date][0], $cycle, $date));
                }

                $scheduled[$date][] = $cycle;
            }

            $occurrences[] = $occurrence;
        }

        $this->assertChronological($occurrences);

        foreach ($scheduled as $cycles) {
            if (count($cycles) < 2) {
                continue;
            }

            foreach ($cycles as $cycle) {
                $occurrence = $occurrences[$cycle];
                $occurrences[$cycle] = new RecurringOccurrence(
                    $occurrence->cycleIndex, $occurrence->nominalPeriod, $occurrence->nominalDate,
                    $occurrence->scheduledDate, $occurrence->status, $occurrence->reason, $occurrence->shiftDays, true,
                );
            }
        }

        return new RecurringScheduleResult(
            $options['profile'], $options['timezone'], $options['calendar'], $options['frequency'],
            $options['interval'], $anchor, $options['count'], $occurrences,
        );
    }

    /** @return array<string, mixed> */
    private function options(array $options): array
    {
        $allowed = ['profile', 'timezone', 'frequency', 'interval', 'count', 'adjustment', 'calendar', 'month_anchor', 'short_month', 'max_shift_days', 'collision_policy'];

        foreach (array_keys($options) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new WorkdayConfigurationException('Recurring schedule contains an unknown option ['.$key.'].');
            }
        }

        if (! is_string($options['profile'] ?? null) || trim($options['profile']) === '') {
            throw new WorkdayConfigurationException('Recurring schedule profile must be a configured nonempty profile name.');
        }

        if (! is_string($options['timezone'] ?? null) || ! in_array($options['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new WorkdayConfigurationException('Recurring schedule timezone must be a valid IANA timezone identifier.');
        }

        foreach (['interval' => [1, 12], 'count' => [1, 60]] as $key => [$minimum, $maximum]) {
            $this->integerOption($options, $key, $minimum, $maximum);
        }

        if (($options['frequency'] ?? null) === 'weekly'
            && (array_key_exists('month_anchor', $options) || array_key_exists('short_month', $options)
                || (array_key_exists('calendar', $options) && $options['calendar'] !== 'gregorian'))) {
            throw new WorkdayConfigurationException('Weekly recurrence accepts only the Gregorian calendar and rejects monthly-only month_anchor and short_month options.');
        }

        // Copy scalar options by value, detaching references from the caller's options array.
        $normalized = [];

        foreach ($options as $key => $value) {
            $normalized[$key] = $value;
        }

        $normalized += ['calendar' => 'gregorian', 'month_anchor' => 'day_of_month', 'short_month' => 'clamp', 'max_shift_days' => 7, 'collision_policy' => 'reject'];
        $this->integerOption($normalized, 'max_shift_days', 0, 31);

        foreach ([
            'frequency' => ['weekly', 'monthly'], 'calendar' => ['gregorian', 'jalali'],
            'month_anchor' => ['day_of_month', 'end_of_month'], 'short_month' => ['clamp', 'skip'],
            'adjustment' => ['next', 'previous', 'skip'], 'collision_policy' => ['reject', 'allow'],
        ] as $key => $values) {
            if (! is_string($normalized[$key] ?? null) || ! in_array($normalized[$key], $values, true)) {
                throw new WorkdayConfigurationException('Recurring schedule '.$key.' must be one of: '.implode(', ', $values).'.');
            }
        }

        return $normalized;
    }

    private function integerOption(array $options, string $key, int $minimum, int $maximum): void
    {
        if (! is_int($options[$key] ?? null) || $options[$key] < $minimum || $options[$key] > $maximum) {
            throw new WorkdayConfigurationException(sprintf('Recurring schedule %s must be a native integer from %d through %d.', $key, $minimum, $maximum));
        }
    }

    private function anchor(string|DateTimeInterface $input, DateTimeZone $zone): string
    {
        $label = $input instanceof DateTimeInterface ? CarbonImmutable::instance($input)->setTimezone($zone)->format('Y-m-d') : $input;

        if (! $this->validGregorian($label) || WorkingTimeBoundary::date($label, $zone) === null) {
            throw new InvalidArgumentException('Recurring schedule anchorDate must be a strict, existing Gregorian Y-m-d civil date in timezone ['.$zone->getName().'].');
        }

        return $label;
    }

    /** @return list<array{string, string|null}> */
    private function nominals(string $anchor, array $options, int $limit): array
    {
        $original = array_map('intval', explode('-', $anchor));

        if ($options['frequency'] === 'monthly' && $options['calendar'] === 'jalali') {
            $original = Verta::GregorianToJalali(...$original);

            if ($this->jalaliToGregorian(...$original) !== $anchor) {
                throw new RuntimeException('The Jalali recurrence anchor does not round-trip through the installed calendar library.');
            }
        }

        [$year, $month, $day] = $original;

        if ($options['frequency'] === 'monthly' && $options['month_anchor'] === 'end_of_month'
            && $day !== $this->monthLength($year, $month, $options['calendar'])) {
            throw new WorkdayConfigurationException('Recurring end_of_month requires an anchor on the final day of its selected calendar month.');
        }

        $nominals = [];

        for ($cycle = 0; $cycle < $options['count']; $cycle++) {
            if ($options['frequency'] === 'weekly') {
                $nominal = $this->labelAfter($anchor, $cycle * $options['interval'] * 7);
                $period = $nominal;
                $bounded = $nominal;
            } else {
                $ordinal = $year * 12 + $month - 1 + $cycle * $options['interval'];
                $targetYear = intdiv($ordinal, 12);
                $targetMonth = $ordinal % 12 + 1;
                $lastDay = $this->monthLength($targetYear, $targetMonth, $options['calendar']);
                $period = sprintf('%04d-%02d', $targetYear, $targetMonth);
                $targetDay = $options['month_anchor'] === 'end_of_month' ? $lastDay : $day;
                $invalid = $targetDay > $lastDay && $options['short_month'] === 'skip';
                $bounded = $this->gregorianLabel($targetYear, $targetMonth, min($targetDay, $lastDay), $options['calendar']);
                $nominal = $invalid ? null : $bounded;
                // For a nonexistent day the entire valid target month must fit; lastDay
                // bounds its valid range without inventing a nominal or scheduled date.
            }

            $this->assertHorizon($bounded, $anchor, $limit, $cycle);
            $nominals[] = [$period, $nominal];
        }

        return $nominals;
    }

    private function monthLength(int $year, int $month, string $calendar): int
    {
        if ($calendar === 'gregorian') {
            if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
                throw new RuntimeException('Recurring Gregorian month is outside the supported four-digit date range.');
            }

            return (int) DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('UTC'))->format('t');
        }

        foreach ([31, 30, 29] as $day) {
            if (Verta::isValidDate($year, $month, $day)) {
                return $day;
            }
        }

        throw new RuntimeException('Recurring Jalali month cannot be represented by the installed calendar library.');
    }

    private function gregorianLabel(int $year, int $month, int $day, string $calendar): string
    {
        return $calendar === 'jalali' ? $this->jalaliToGregorian($year, $month, $day) : sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function jalaliToGregorian(int $year, int $month, int $day): string
    {
        if (! Verta::isValidDate($year, $month, $day)) {
            throw new RuntimeException(sprintf('Invalid Jalali recurrence date [%04d-%02d-%02d].', $year, $month, $day));
        }

        $gregorian = Verta::jalaliToGregorian($year, $month, $day);
        $label = sprintf('%04d-%02d-%02d', ...$gregorian);

        if (! $this->validGregorian($label) || Verta::GregorianToJalali(...$gregorian) !== [$year, $month, $day]) {
            throw new RuntimeException(sprintf('Jalali recurrence date [%04d-%02d-%02d] cannot round-trip to a supported Gregorian date.', $year, $month, $day));
        }

        return $label;
    }

    private function adjust(int $cycle, string $period, string $nominal, string $anchor, array $options, int $limit, DateTimeZone $zone, BusinessDayCalculator $calculator, array &$eligibility): RecurringOccurrence
    {
        if ($this->eligible($nominal, $zone, $calculator, $eligibility)) {
            return new RecurringOccurrence($cycle, $period, $nominal, $nominal, 'scheduled', 'business_day', 0);
        }

        $adjustment = $options['adjustment'];

        if ($adjustment === 'skip') {
            return new RecurringOccurrence($cycle, $period, $nominal, null, 'skipped', 'closed_day', null);
        }

        $direction = $adjustment === 'next' ? 1 : -1;

        for ($offset = 1; $offset <= $options['max_shift_days']; $offset++) {
            try {
                $label = $this->labelAfter($nominal, $offset * $direction);
            } catch (RuntimeException $exception) {
                throw $this->shiftFailure($options, $cycle, $nominal, $exception->getMessage(), $exception);
            }

            if ($this->dateDistance($anchor, $label) > $limit) {
                throw $this->shiftFailure($options, $cycle, $nominal, 'The destination would exceed the original global calendar horizon of ['.$limit.'] future date labels.');
            }

            if ($this->eligible($label, $zone, $calculator, $eligibility)) {
                return new RecurringOccurrence($cycle, $period, $nominal, $label, 'shifted', 'shifted_'.$adjustment, $offset * $direction);
            }
        }

        throw $this->shiftFailure($options, $cycle, $nominal, 'No eligible business date exists within the allowed shift.');
    }

    private function eligible(string $label, DateTimeZone $zone, BusinessDayCalculator $calculator, array &$cache): bool
    {
        if (! array_key_exists($label, $cache)) {
            $date = WorkingTimeBoundary::date($label, $zone);
            $cache[$label] = $date !== null && $calculator->isBusinessDay($date);
        }

        return $cache[$label];
    }

    /** @param list<RecurringOccurrence> $occurrences */
    private function assertChronological(array $occurrences): void
    {
        $previous = null;

        foreach ($occurrences as $occurrence) {
            if ($occurrence->scheduledDate === null) {
                continue;
            }

            if ($previous !== null && $occurrence->scheduledDate < $previous->scheduledDate) {
                throw new RuntimeException(sprintf('Recurring schedule cycles [%d] and [%d] are chronologically reversed: [%s] then [%s].', $previous->cycleIndex, $occurrence->cycleIndex, $previous->scheduledDate, $occurrence->scheduledDate));
            }

            $previous = $occurrence;
        }
    }

    private function assertHorizon(string $label, string $anchor, int $limit, int $cycle): void
    {
        if ($this->dateDistance($anchor, $label) > $limit) {
            throw new RuntimeException(sprintf('Recurring cycle [%d], nominal date/period bound [%s], exceeds the original global horizon of [%d] future date labels from [%s].', $cycle, $label, $limit, $anchor));
        }
    }

    private function shiftFailure(array $options, int $cycle, string $nominal, string $reason, ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(sprintf('Recurring profile [%s], cycle [%d], nominal date [%s], adjustment [%s], max_shift_days [%d]: %s', $options['profile'], $cycle, $nominal, $options['adjustment'], $options['max_shift_days'], $reason), previous: $previous);
    }

    private function labelAfter(string $label, int $days): string
    {
        try {
            $next = WorkingTimeBoundary::labelAfter($label, $days);
        } catch (Throwable $exception) {
            throw new RuntimeException('Recurring date movement cannot be represented.', previous: $exception);
        }

        if (! $this->validGregorian($next)) {
            throw new RuntimeException('Recurring date movement exceeds the supported four-digit Gregorian date range.');
        }

        return $next;
    }

    /** UTC is used solely to count Gregorian labels, never as the scheduled local instant. */
    private function dateDistance(string $from, string $to): int
    {
        $zone = new DateTimeZone('UTC');
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);

        return intdiv($last->getTimestamp() - $first->getTimestamp(), 86400);
    }

    private function validGregorian(string $label): bool
    {
        return preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $label, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
