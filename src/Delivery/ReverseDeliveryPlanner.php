<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Delivery;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Data\ReverseDeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Support\CutoffPolicy;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\WorkingHours;
use Zarbinco\LaravelWorkdays\Support\WorkingTimeBoundary;

/**
 * Operation-local search. Prefixes use the original forward engine and receipt-anchored horizon.
 * Each binary search is confined to a structurally monotone receipt interval, never the whole window.
 */
final class ReverseDeliveryPlanner
{
    private const MAX_EVALUATIONS = 65536;

    private const MAX_CELLS = 32768;

    private readonly Closure $resolveCalculator;

    private readonly Closure $resolveProfileConfig;

    private array $plan = [];

    /** @var array<int, array<string, mixed>> */
    private array $prefixes = [];

    /** @var array<int, array<int, int|null>> */
    private array $values = [];

    /** @var array<string, BusinessDayCalculator> */
    private array $calculators = [];

    /** @var array<string, list<int>> */
    private array $zoneEvents = [];

    /** @var array<string, string|null> */
    private array $destinationDates = [];

    /** @var array<string, list<array{int, int}>> */
    private array $workingIntervals = [];

    /** @var array<string, bool> */
    private array $enhancedProfiles = [];

    private int $target;

    private int $start;

    private int $evaluations = 0;

    private int $cells = 0;

    /**
     * @param  callable(string, int): BusinessDayCalculator  $resolveCalculator
     * @param  callable(string): array<string, mixed>  $resolveProfileConfig
     */
    public function __construct(private readonly DeliveryPlanner $forward, callable $resolveCalculator, callable $resolveProfileConfig, private readonly ?array $scanSettings = null)
    {
        $this->resolveCalculator = Closure::fromCallable($resolveCalculator);
        $this->resolveProfileConfig = Closure::fromCallable($resolveProfileConfig);
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $options */
    public function plan(string|DateTimeInterface $desiredCompletionAt, array $plan, array $options): ReverseDeliveryPlanResult
    {
        $this->forward->validatePlan($plan);

        try {
            $limit = ProfileConfigValidator::validateMaxScanDays($this->scanSettings === null ? config('workdays.max_scan_days', 3660) : $this->scanSettings['max_scan_days']);
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException('Invalid reverse delivery horizon: '.$exception->getMessage(), previous: $exception);
        }

        if (array_diff(array_keys($options), ['lookback_days']) !== []
            || ! is_int($options['lookback_days'] ?? null)
            || $options['lookback_days'] < 1 || $options['lookback_days'] > 31 || $options['lookback_days'] > $limit) {
            throw new WorkdayConfigurationException('Reverse delivery options require only lookback_days, a native integer from 1 to 31, not exceeding workdays.max_scan_days.');
        }

        try {
            $target = $this->forward->normalizeInput($desiredCompletionAt, $plan['timezone']);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid reverse delivery desiredCompletionAt: '.$exception->getMessage(), previous: $exception);
        }

        // Instances are internal, but resetting also makes direct sequential reuse deterministic.
        $plan = $this->detach($plan);
        $this->plan = $plan;
        $this->prefixes = $this->values = $this->calculators = $this->zoneEvents = $this->destinationDates = [];
        $this->workingIntervals = [];
        $this->enhancedProfiles = [];
        $this->evaluations = $this->cells = 0;
        $this->target = self::micros($target);
        $targetLabel = $target->setTimezone($plan['timezone'])->toDateString();
        $firstLabel = WorkingTimeBoundary::labelAfter($targetLabel, -$options['lookback_days']);

        for ($label = $firstLabel; $label <= $targetLabel; $label = WorkingTimeBoundary::labelAfter($label, 1)) {
            $first = $this->civilStart($label, $plan['timezone']);

            if ($first !== null) {
                break;
            }
        }

        if (! isset($first) || $first === null) {
            throw $this->unprovable('The backward date window cannot be represented.');
        }

        $this->start = self::micros($first);
        $this->prepare($limit);
        $events = $this->events($plan['timezone']);

        // The plan-local date fixes the global horizon; original receipt dates in every
        // selected zone also fix the forward calculator's exact scan-limit variant.
        foreach ($this->zoneEvents as $profileEvents) {
            $events = array_merge($events, $profileEvents);
        }

        if (isset($plan['cutoff_profile'])) {
            $profile = $plan['cutoff_profile'];
            $zone = $plan['profile_timezones'][$profile];
            $events = array_merge($events, $this->events($zone));
            $policy = CutoffPolicy::fromProfileConfig($profile, ($this->resolveProfileConfig)($profile));

            if ($policy->timezone !== $zone) {
                throw new WorkdayConfigurationException('The cutoff timezone must match profile_timezones for the cutoff_profile.');
            }

            foreach ($this->dateLabels($zone) as $label) {
                $date = $this->civilStart($label, $zone);
                $cutoff = $date === null ? null : $policy->cutoffAt($date->setTimezone($zone));

                if ($cutoff !== null) {
                    $events[] = self::micros($cutoff);
                }
            }
        }

        foreach ($plan['stages'] as $stage) {
            if (! $this->hasOnTimeCapacity($stage, $this->start)) {
                return new ReverseDeliveryPlanResult($plan['timezone'], $target, $first, false, null, null, null, null, null, null);
            }
        }

        $latest = null;

        foreach (array_reverse($this->split($this->start, $this->target, $events)) as [$from, $through]) {
            $through = $this->lastOnTime($from, $through, 0);

            if ($through !== null) {
                $latest = $this->search($from, $through, 0);

                if ($latest !== null) {
                    break; // All later receipt intervals have already been excluded.
                }
            }
        }

        if ($latest === null) {
            return new ReverseDeliveryPlanResult($plan['timezone'], $target, $first, false, null, null, null, null, null, null);
        }

        // A fresh full evaluation, with the exact original plan and unchanged forward horizon.
        $verified = $this->forward->plan(self::instant($latest), $plan);
        $completion = self::micros($verified->estimatedCompletionAt);

        if ($completion > $this->target || self::micros($verified->receivedAt) !== $latest) {
            throw $this->unprovable('Final forward verification disagrees with the search.');
        }

        return new ReverseDeliveryPlanResult(
            $plan['timezone'], $target, $first, true, $verified->receivedAt,
            $verified->planningStartAt, $verified->stages[0]->startedAt,
            $verified->estimatedCompletionAt, $this->target - $completion, $verified,
        );
    }

    private function prepare(int $limit): void
    {
        $timeProfiles = [];
        $profiles = [];

        foreach ($this->plan['stages'] as $index => $stage) {
            $this->prefixes[$index + 1] = array_replace($this->plan, ['stages' => array_slice($this->plan['stages'], 0, $index + 1)]);

            foreach ($this->stageProfiles($stage) as $profile) {
                $profiles[$profile] = true;

                if ($stage['type'] !== 'business_days') {
                    $timeProfiles[$profile] = true;
                }
            }
        }

        // Resolve every selected inherited configuration before the shared provider is constructed.
        foreach (array_keys($profiles) as $profile) {
            $profile = (string) $profile;
            try {
                ($this->resolveProfileConfig)($profile);
            } catch (InvalidArgumentException $exception) {
                throw new WorkdayConfigurationException('Invalid reverse delivery profile ['.$profile.']: '.$exception->getMessage(), previous: $exception);
            }
        }

        foreach (array_keys($profiles) as $profile) {
            $profile = (string) $profile;
            try {
                $this->calculators[$profile] = ($this->resolveCalculator)($profile, $limit);
            } catch (InvalidArgumentException $exception) {
                throw new WorkdayConfigurationException('Invalid reverse delivery profile ['.$profile.']: '.$exception->getMessage(), previous: $exception);
            }

            $zone = $this->plan['profile_timezones'][$profile];
            $this->events($zone);

            if (isset($timeProfiles[$profile])) {
                $this->validateTimeCalendar($profile, $zone);
            }
        }

        // Prefix zero means the actual cutoff router. A zero-minute copy of the first work
        // stage exposes planningStartAt through the existing engine; it does not replace work.
        if (isset($this->plan['cutoff_profile'])) {
            $first = $this->plan['stages'][0];
            $first['minutes'] = 0;
            $this->prefixes[0] = array_replace($this->plan, ['stages' => [$first]]);
        }
    }

    private function search(int $from, int $through, int $index): ?int
    {
        $stage = $this->plan['stages'][$index];
        $events = [];

        foreach ($this->stageProfiles($stage) as $profile) {
            $events = array_merge($events, $this->events($this->plan['profile_timezones'][$profile]));
        }

        $cells = $this->splitInputs($from, $through, $index, $events);
        $refined = [];

        foreach ($cells as [$left, $right]) {
            $extra = $stage['type'] === 'business_days' && $stage['days'] > 0
                ? $this->businessDayEvents($stage, $this->value($index, $left)) : [];

            if ($extra === null || ! $this->hasOnTimeCapacity($stage, $this->value($index, $left))) {
                continue;
            }

            array_push($refined, ...$this->splitInputs($left, $right, $index, $extra));
        }

        foreach (array_reverse($refined) as [$left, $right]) {
            $last = $this->lastOnTime($left, $right, $index + 1);

            if ($last === null) {
                continue;
            }

            if ($index + 1 === count($this->plan['stages'])) {
                return $last;
            }

            $latest = $this->search($left, $last, $index + 1);

            if ($latest !== null) {
                return $latest;
            }
        }

        return null;
    }

    /**
     * Invariant: prefix $prefix is defined, on time and nondecreasing throughout this cell.
     * Pull each next-stage discontinuity back to its first receipt preimage.
     *
     * @param  list<int>  $events
     * @return list<array{int, int}>
     */
    private function splitInputs(int $from, int $through, int $prefix, array $events): array
    {
        $first = $this->value($prefix, $from);
        $last = $this->value($prefix, $through);

        if ($first === null || $last === null || $last < $first) {
            throw $this->unprovable('An earlier prefix violates its monotone interval invariant.');
        }

        $cuts = [];

        foreach ($this->ordered($events) as $event) {
            if ($event <= $first || $event > $last) {
                continue;
            }

            $low = $from;
            $high = $through;

            while ($low < $high) {
                $middle = $low + intdiv($high - $low, 2);
                $value = $this->value($prefix, $middle);

                if ($value === null || $value < $first || $value > $last) {
                    throw $this->unprovable('An earlier prefix is undefined or moved outside its proven bounds.');
                }

                if ($value >= $event) {
                    $high = $middle;
                } else {
                    $low = $middle + 1;
                }
            }

            $cuts[] = $low;
        }

        return $this->split($from, $through, $cuts);
    }

    /** Last true receipt in one proven monotone cell; null denotes an entirely excluded cell. */
    private function lastOnTime(int $from, int $through, int $prefix): ?int
    {
        $first = $this->value($prefix, $from);
        $last = $this->value($prefix, $through);

        if (($first === null && $last !== null) || ($first !== null && $last !== null && $last < $first)) {
            throw $this->unprovable('A forward prefix violates the calendar interval proof.');
        }

        if ($first === null || $first > $this->target) {
            return null;
        }

        if ($last !== null && $last <= $this->target) {
            return $through;
        }

        $low = $from;
        $high = $through;

        while ($low < $high) {
            $middle = $low + intdiv($high - $low + 1, 2);
            $value = $this->value($prefix, $middle);

            if ($value !== null && $value < $first) {
                throw $this->unprovable('A forward prefix moved backward inside a calendar interval.');
            }

            if ($value !== null && $value <= $this->target) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    private function value(int $prefix, int $receipt, bool $preflight = true): ?int
    {
        if ($prefix === 0 && ! isset($this->plan['cutoff_profile'])) {
            return $receipt;
        }

        if (array_key_exists($receipt, $this->values[$prefix] ?? [])) {
            return $this->values[$prefix][$receipt];
        }

        if ($preflight) {
            $stage = $prefix === 0 ? $this->prefixes[0]['stages'][0] : $this->plan['stages'][$prefix - 1];
            // The previous prefix is already proven on this receipt cell. Its actual engine
            // evaluation needs no recursive preflight, and never changes the original horizon.
            $input = $prefix === 0 ? $receipt : $this->value($prefix - 1, $receipt, false);

            if ($input === null || $input > $this->target || ! $this->hasOnTimeCapacity($stage, $input)) {
                return $this->values[$prefix][$receipt] = null;
            }
        }

        if (++$this->evaluations > self::MAX_EVALUATIONS) {
            throw $this->unprovable('The bounded forward-evaluation budget of 65536 was exhausted.');
        }

        try {
            $result = $this->forward->plan(self::instant($receipt), $this->prefixes[$prefix]);
            $value = self::micros($prefix === 0 ? $result->planningStartAt : $result->estimatedCompletionAt);

            foreach ($result->stages as $index => $stageResult) {
                if ($prefix > 0) {
                    $this->values[$index + 1][$receipt] = self::micros($stageResult->completedAt);
                }
            }

            $this->values[0][$receipt] = self::micros($result->planningStartAt);
        } catch (RuntimeException $exception) {
            if (! $this->availabilityFailure($exception)) {
                throw $exception;
            }

            $value = null;
        }

        return $this->values[$prefix][$receipt] = $value;
    }

    /** Only documented candidate-local failures originating in the existing engine qualify. */
    private function availabilityFailure(RuntimeException $exception): bool
    {
        while ($exception->getPrevious() instanceof RuntimeException) {
            $exception = $exception->getPrevious();
        }

        $file = str_replace('\\', '/', $exception->getFile());
        $deliveryFile = str_replace('\\', '/', __DIR__.'/DeliveryPlanner.php');
        $calculatorFile = str_replace('\\', '/', dirname(__DIR__).'/Calculator/BusinessDayCalculator.php');
        $message = $exception->getMessage();

        if ($file === $deliveryFile) {
            return in_array($message, [
                'No working start exists within the global planning horizon.',
                'Insufficient business minutes within the global planning horizon.',
                'No shared working instant exists within the global planning horizon.',
                'Unable to count the requested future business dates within the global planning horizon.',
            ], true)
                || preg_match('/\AThe stage exceeds the last permitted plan date \[[^\]]+\] in timezone \[[^\]]+\]\.\z/', $message) === 1
                || preg_match('/\AThe business_days target wall time \[[^\]]+\] does not exist in timezone \[[^\]]+\]\.\z/', $message) === 1;
        }

        return $file === $calculatorFile && (
            preg_match('/\AUnable to resolve a business time within \[[0-9]+\] calendar days for profile \[[\s\S]+\]\. Check working_hours, holidays, extra working days, and max_scan_days config\.\z/', $message) === 1
            || preg_match('/\AUnable to resolve a cut-off eligible business date within \[[0-9]+\] future calendar days for profile \[[\s\S]+\]\. Check cutoff, weekends, holidays, extra working days, and max_scan_days config\.\z/', $message) === 1
        );
    }

    /**
     * Source-date and offset events already isolate one local clock sequence. The destination
     * date is fixed there; pull back both ends of each destination gap/fold in wall coordinates.
     *
     * @return list<int>|null Null proves that no future destination date can finish on time.
     */
    private function businessDayEvents(array $stage, int $input): ?array
    {
        $profile = $stage['profile'];
        $zone = $this->plan['profile_timezones'][$profile];
        $source = self::instant($input)->setTimezone($zone);
        $key = $profile.'|'.$source->toDateString().'|'.$stage['days'];

        if (! array_key_exists($key, $this->destinationDates)) {
            $destination = null;
            $count = 0;
            $lastLabel = self::instant($this->target)->setTimezone($zone)->toDateString();

            for ($label = WorkingTimeBoundary::labelAfter($source->toDateString(), 1); $label <= $lastLabel; $label = WorkingTimeBoundary::labelAfter($label, 1)) {
                $date = WorkingTimeBoundary::date($label, new DateTimeZone($zone));

                if ($date !== null && $this->calculators[$profile]->isBusinessDay($date) && ++$count === $stage['days']) {
                    $destination = $label;
                    break;
                }
            }

            $this->destinationDates[$key] = $destination;
        }

        $destination = $this->destinationDates[$key];

        if ($destination === null) {
            return null; // Every valid output is after the deadline, regardless of wall clock.
        }

        $destinationBase = $this->labelTimestamp($destination);
        $sourceBase = $this->labelTimestamp($source->toDateString());
        $transitions = (new DateTimeZone($zone))->getTransitions($destinationBase - 172800, $destinationBase + 259200) ?: [];
        $previous = null;
        $events = [];

        foreach ($transitions as $transition) {
            if ($previous !== null && $previous !== $transition['offset']) {
                foreach ([$previous, $transition['offset']] as $offset) {
                    $clock = $transition['ts'] + $offset - $destinationBase;

                    if ($clock >= 0 && $clock <= 86400) {
                        $events[] = ($sourceBase + $clock - $source->getOffset()) * 1000000;
                    }
                }
            }

            $previous = $transition['offset'];
        }

        return $events;
    }

    /** @return list<int> */
    private function events(string $zone): array
    {
        if (isset($this->zoneEvents[$zone])) {
            return $this->zoneEvents[$zone];
        }

        $events = [];

        foreach ($this->dateLabels($zone) as $label) {
            $date = $this->civilStart($label, $zone);

            if ($date !== null) {
                $events[] = self::micros($date);
            }
        }

        $transitions = (new DateTimeZone($zone))->getTransitions(intdiv($this->start, 1000000) - 1, intdiv($this->target, 1000000) + 1) ?: [];

        foreach (array_slice($transitions, 1) as $transition) {
            $instant = self::instant($transition['ts'] * 1000000);

            if ($instant->setTimezone($zone)->toDateString() < $instant->subSecond()->setTimezone($zone)->toDateString()) {
                throw $this->unprovable('Timezone ['.$zone.'] moves backward across a civil-date label in the search window.');
            }

            $events[] = self::micros($instant);
        }

        return $this->zoneEvents[$zone] = $this->ordered($events);
    }

    /**
     * Validate the time-calendar proof once, including overnight carry-in. Enhanced boundaries
     * use their actual strict resolver; legacy rows retain elapsed-midnight arithmetic. A legacy
     * row spilling past its receiving date or based on an ambiguous midnight is not proven here.
     */
    private function validateTimeCalendar(string $profile, string $zone): void
    {
        $enhanced = WorkingHours::hasEnhancedConfig(($this->resolveProfileConfig)($profile));
        $this->enhancedProfiles[$profile] = $enhanced;
        $timezone = new DateTimeZone($zone);
        $this->workingIntervals[$profile] = [];

        foreach ($this->dateLabels($zone, true) as $label) {
            $date = $this->civilStart($label, $zone);

            if ($date === null) {
                continue;
            }

            $date = $date->setTimezone($timezone);

            $windows = $this->calculators[$profile]->workingWindowsFor($date);

            if ($windows === []) {
                continue;
            }

            $endOfDate = null;

            if (! $enhanced) {
                // Strict resolution detects a repeated midnight without changing legacy APIs.
                try {
                    WorkingTimeBoundary::at($profile, $label, 0, $timezone, true);
                } catch (WorkdayConfigurationException $exception) {
                    throw $this->unprovable('Legacy profile ['.$profile.'] has an ambiguous midnight on ['.$label.'].', $exception);
                }

                for ($next = 1; $next <= 3 && $endOfDate === null; $next++) {
                    $endOfDate = $this->civilStart(WorkingTimeBoundary::labelAfter($label, $next), $zone);
                }

                if ($endOfDate === null) {
                    throw $this->unprovable('A legacy receiving-date boundary cannot be represented.');
                }
            }

            foreach ($windows as $window) {
                $start = $enhanced
                    ? WorkingTimeBoundary::at($profile, $label, $window->startMinutes(), $timezone, $window->startMinutes() === 0)
                    : $date->startOfDay()->addMinutes($window->startMinutes());
                $end = $enhanced
                    ? WorkingTimeBoundary::at($profile, $label, $window->endMinutes(), $timezone, $window->endMinutes() === 1440)
                    : $date->startOfDay()->addMinutes($window->endMinutes());

                if ($end->lessThanOrEqualTo($start)) {
                    throw $this->unprovable('A working interval has nonpositive elapsed length.');
                }

                if (! $enhanced && ($start->toDateString() !== $label || $end->greaterThan($endOfDate))) {
                    throw $this->unprovable('Legacy working hours for profile ['.$profile.'] spill past civil date ['.$label.']; use explicit enhanced wall-clock schedules.');
                }

                if (self::micros($end) > $this->start && self::micros($start) <= $this->target) {
                    $this->workingIntervals[$profile][] = [self::micros($start), self::micros($end)];
                }
            }
        }
    }

    /**
     * Calendar evidence only excludes impossible cells. It never substitutes a successful
     * forward evaluation. Legacy capacity uses its integer-second, per-window minute floor;
     * enhanced capacity uses aggregate exact elapsed time. Neither can exclude an on-time plan.
     */
    private function hasOnTimeCapacity(array $stage, int $input): bool
    {
        if ($stage['type'] === 'business_days') {
            return true;
        }

        if ($stage['type'] === 'handoff') {
            $other = $this->workingIntervals[$stage['profiles'][1]];
            $index = 0;

            foreach ($this->workingIntervals[$stage['profiles'][0]] as [$left, $right]) {
                while (isset($other[$index]) && $other[$index][1] <= max($input, $left)) {
                    $index++;
                }

                for ($probe = $index; isset($other[$probe]) && $other[$probe][0] < $right; $probe++) {
                    [$otherLeft, $otherRight] = $other[$probe];
                    $opening = max($left, $otherLeft, $input);

                    if ($opening <= $this->target && $opening < min($right, $otherRight)) {
                        return true;
                    }
                }
            }

            return false;
        }

        $upperMinutes = 0;
        $elapsed = 0;
        $hasStart = false;

        foreach ($this->workingIntervals[$stage['profile']] as [$left, $right]) {
            if ($right <= $input || $left > $this->target) {
                continue;
            }

            $hasStart = true;
            $begin = max($left, $input);
            $end = min($right, $this->target);

            if ($end > $begin) {
                $elapsed += $end - $begin;
                $upperMinutes += intdiv(self::seconds($end) - self::seconds($begin), 60);
            }
        }

        if ($this->enhancedProfiles[$stage['profile']]) {
            $upperMinutes = intdiv($elapsed, 60000000);
        }

        return $hasStart && ($stage['minutes'] === 0 || $upperMinutes >= $stage['minutes']);
    }

    /** Date-label enumeration, not elapsed 24-hour stepping. @return list<string> */
    private function dateLabels(string $zone, bool $carry = false): array
    {
        $first = self::instant($this->start)->setTimezone($zone)->toDateString();
        $last = self::instant($this->target)->setTimezone($zone)->toDateString();
        $labels = [];

        for ($label = WorkingTimeBoundary::labelAfter($first, $carry ? -1 : 0); $label <= $last; $label = WorkingTimeBoundary::labelAfter($label, 1)) {
            $labels[] = $label;
        }

        return $labels;
    }

    /** First actual instant of a civil date, including midnight gaps/folds; null for skipped labels. */
    private function civilStart(string $label, string $zone): ?CarbonImmutable
    {
        $native = DateTimeImmutable::createFromFormat('!Y-m-d', $label, new DateTimeZone($zone));

        if ($native === false || $native->format('Y-m-d') !== $label) {
            return null;
        }

        $first = CarbonImmutable::instance($native)->setTimezone('UTC');
        $base = $this->labelTimestamp($label);
        $transitions = (new DateTimeZone($zone))->getTransitions($base - 172800, $base + 172800) ?: [];

        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $candidate = CarbonImmutable::createFromTimestamp($base - $offset, 'UTC');

            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $label.' 00:00:00' && $candidate->lessThan($first)) {
                $first = $candidate;
            }
        }

        return $first;
    }

    private function labelTimestamp(string $label): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $label, new DateTimeZone('UTC'));

        if ($date === false || $date->format('Y-m-d') !== $label) {
            throw $this->unprovable('A Gregorian date label cannot be represented.');
        }

        return $date->getTimestamp();
    }

    /** @param list<int> $events @return list<array{int, int}> */
    private function split(int $from, int $through, array $events): array
    {
        $cells = [];

        foreach ($this->ordered($events) as $event) {
            if ($event > $from && $event <= $through) {
                $cells[] = [$from, $event - 1];
                $from = $event;
            }
        }

        $cells[] = [$from, $through];
        $this->cells += count($cells);

        if ($this->cells > self::MAX_CELLS) {
            throw $this->unprovable('The bounded calendar-interval budget of 32768 was exhausted.');
        }

        return $cells;
    }

    /** @param list<int> $values @return list<int> */
    private function ordered(array $values): array
    {
        $values = array_values(array_unique($values, SORT_REGULAR));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /** @return list<string> */
    private function stageProfiles(array $stage): array
    {
        return $stage['type'] === 'handoff' ? $stage['profiles'] : [$stage['profile']];
    }

    private function unprovable(string $reason, ?\Throwable $previous = null): RuntimeException
    {
        return new RuntimeException('Reverse delivery scheduling cannot establish exact optimality: '.$reason, previous: $previous);
    }

    /** Copy scalar plan values by value, including caller arrays containing PHP references. */
    private function detach(array $values): array
    {
        $copy = [];

        foreach ($values as $key => $value) {
            $copy[$key] = is_array($value) ? $this->detach($value) : $value;
        }

        return $copy;
    }

    private static function micros(CarbonImmutable $instant): int
    {
        $seconds = $instant->getTimestamp();

        if ($seconds < intdiv(PHP_INT_MIN, 1000000) || $seconds > intdiv(PHP_INT_MAX - 999999, 1000000)) {
            throw new InvalidArgumentException('Reverse delivery timestamps must fit the signed integer microsecond range.');
        }

        return $seconds * 1000000 + (int) $instant->format('u');
    }

    private static function instant(int $micros): CarbonImmutable
    {
        $seconds = intdiv($micros, 1000000);
        $fraction = $micros % 1000000;

        if ($fraction < 0) {
            $seconds--;
            $fraction += 1000000;
        }

        return CarbonImmutable::createFromTimestamp($seconds, 'UTC')->setMicrosecond($fraction);
    }

    private static function seconds(int $micros): int
    {
        return intdiv($micros, 1000000) - ($micros % 1000000 < 0 ? 1 : 0);
    }
}
