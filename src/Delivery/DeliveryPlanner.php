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
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryStageResult;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Support\CutoffDateTimeNormalizer;
use Zarbinco\LaravelWorkdays\Support\CutoffPolicy;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;

final readonly class DeliveryPlanner
{
    private Closure $resolveCalculator;

    private ?Closure $resolveProfileConfig;

    /**
     * @param  callable(string, int): BusinessDayCalculator  $resolveCalculator
     * @param  null|callable(string): array<string, mixed>  $resolveProfileConfig
     */
    public function __construct(callable $resolveCalculator, ?callable $resolveProfileConfig = null)
    {
        $this->resolveCalculator = Closure::fromCallable($resolveCalculator);
        $this->resolveProfileConfig = $resolveProfileConfig === null ? null : Closure::fromCallable($resolveProfileConfig);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function plan(string|DateTimeInterface $receivedAt, array $plan): DeliveryPlanResult
    {
        $this->validatePlan($plan);
        $timezone = $plan['timezone'];
        $receivedAt = $this->normalizeInput($receivedAt, $timezone);

        try {
            $limit = ProfileConfigValidator::validateMaxScanDays(config('workdays.max_scan_days', 3660));
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException('Invalid delivery planning horizon: '.$exception->getMessage(), previous: $exception);
        }

        // UTC is used only to advance Gregorian date labels, including skipped civil dates.
        $lastDate = CarbonImmutable::createFromFormat('!Y-m-d', $receivedAt->setTimezone($timezone)->toDateString(), 'UTC')->addDays($limit);
        $lastDateLabel = $lastDate->toDateString();
        $horizonEnd = $this->horizonEnd($lastDate, $timezone);
        $calculators = [];
        $overnightProfiles = [];
        $effectiveConfigs = [];

        foreach ($plan['stages'] as $stage) {
            foreach ($this->stageProfiles($stage) as $profile) {
                if (! array_key_exists($profile, $calculators)) {
                    try {
                        $profileTimezone = $plan['profile_timezones'][$profile];
                        $firstLocalDate = CarbonImmutable::createFromFormat('!Y-m-d', $receivedAt->setTimezone($profileTimezone)->toDateString(), 'UTC');
                        $lastLocalDate = CarbonImmutable::createFromFormat('!Y-m-d', $horizonEnd->setTimezone($profileTimezone)->toDateString(), 'UTC');
                        // One plan date can reach a second profile-local date near zone boundaries.
                        $scanLimit = max($limit, (int) $firstLocalDate->diffInDays($lastLocalDate));
                        $calculators[$profile] = ($this->resolveCalculator)($profile, $scanLimit);
                        $effectiveConfigs[$profile] = $this->profileConfig($profile);
                        $overnightProfiles[$profile] = ($effectiveConfigs[$profile]['allow_overnight_working_hours'] ?? false) === true;
                    } catch (InvalidArgumentException $exception) {
                        throw new WorkdayConfigurationException($this->stageContext($stage).': '.$exception->getMessage(), previous: $exception);
                    } catch (RuntimeException $exception) {
                        throw new RuntimeException($this->stageContext($stage).': '.$exception->getMessage(), previous: $exception);
                    }
                }
            }
        }

        $cursor = $receivedAt;
        $cutoffResult = null;

        if (array_key_exists('cutoff_profile', $plan)) {
            $first = $plan['stages'][0];

            try {
                $profileConfig = $effectiveConfigs[$plan['cutoff_profile']];

                if (! is_array($profileConfig)) {
                    throw new WorkdayConfigurationException(sprintf('The cutoff profile [%s] configuration is not available.', $plan['cutoff_profile']));
                }

                $policy = CutoffPolicy::fromProfileConfig($plan['cutoff_profile'], $profileConfig);

                if ($policy->timezone !== $plan['profile_timezones'][$plan['cutoff_profile']]) {
                    throw new WorkdayConfigurationException('The cutoff timezone must match profile_timezones for the cutoff_profile.');
                }

                $cutoffResult = $calculators[$plan['cutoff_profile']]->resolveCutoff($receivedAt);

                if (! $cutoffResult->sameDayEligible) {
                    $cursor = $cutoffResult->effectiveBusinessDate->setTimezone('UTC');
                }

                $this->assertWithinHorizon($cursor, $timezone, $lastDateLabel);
            } catch (InvalidArgumentException $exception) {
                throw new WorkdayConfigurationException($this->stageContext($first).': '.$exception->getMessage(), previous: $exception);
            } catch (RuntimeException $exception) {
                throw $this->stageFailure($first, $limit, $exception);
            }
        }

        $planningStartAt = $cursor;
        $results = [];

        foreach ($plan['stages'] as $stage) {
            $profiles = $this->stageProfiles($stage);

            try {
                $this->assertWithinHorizon($cursor, $timezone, $lastDateLabel);

                [$startedAt, $completedAt] = match ($stage['type']) {
                    'work' => $this->work($cursor, $calculators[$profiles[0]], $plan['profile_timezones'][$profiles[0]], $stage['minutes'], $horizonEnd, $overnightProfiles[$profiles[0]]),
                    'handoff' => $this->handoff($cursor, $profiles, $calculators, $plan['profile_timezones'], $timezone, $lastDateLabel),
                    'business_days' => [$cursor, $this->businessDays($cursor, $calculators[$profiles[0]], $plan['profile_timezones'][$profiles[0]], $stage['days'], $horizonEnd)],
                };

                $this->assertWithinHorizon($startedAt, $timezone, $lastDateLabel);
                $this->assertWithinHorizon($completedAt, $timezone, $lastDateLabel);

                if ($startedAt->lessThan($cursor) || $completedAt->lessThan($startedAt)) {
                    throw new RuntimeException('The stage calculation did not advance monotonically.');
                }
            } catch (InvalidArgumentException $exception) {
                throw new WorkdayConfigurationException($this->stageContext($stage).': '.$exception->getMessage(), previous: $exception);
            } catch (RuntimeException $exception) {
                throw $this->stageFailure($stage, $limit, $exception);
            }

            $results[] = new DeliveryStageResult($stage['id'], $stage['type'], $profiles, $cursor, $startedAt, $completedAt);
            $cursor = $completedAt;
        }

        return new DeliveryPlanResult($timezone, $receivedAt, $planningStartAt, $cutoffResult, $results, $cursor);
    }

    private function profileConfig(string $profile): mixed
    {
        if ($this->resolveProfileConfig !== null) {
            return ($this->resolveProfileConfig)($profile);
        }

        // Preserve one-argument constructor usages, resolving only inherited metadata.
        $profiles = config('workdays.profiles', []);
        $profileConfig = is_array($profiles) ? ($profiles[$profile] ?? null) : null;

        return is_array($profileConfig) && array_key_exists('extends', $profileConfig)
            ? (new ProfileConfigResolver($profiles))->resolve($profile)
            : $profileConfig;
    }

    private function normalizeInput(string|DateTimeInterface $receivedAt, string $timezone): CarbonImmutable
    {
        try {
            $normalized = CutoffDateTimeNormalizer::toImmutable($receivedAt, $timezone);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid delivery receivedAt. Expected a supported absolute Gregorian datetime; relative, invalid and nonexistent wall times are not allowed.', previous: $exception);
        }

        // A naive fold has two possible instants. Offset-bearing inputs already disambiguate it.
        if (is_string($receivedAt) && str_contains($receivedAt, ' ')) {
            $timestamp = $normalized->getTimestamp();
            $wallTimestamp = $timestamp + $normalized->getOffset();
            $transitions = (new DateTimeZone($timezone))->getTransitions($timestamp - 172800, $timestamp + 172800) ?: [];
            $previousOffset = null;

            foreach ($transitions as $transition) {
                $offset = $transition['offset'];

                if ($previousOffset !== null && $offset < $previousOffset
                    && $wallTimestamp >= $transition['ts'] + $offset
                    && $wallTimestamp < $transition['ts'] + $previousOffset) {
                    throw new InvalidArgumentException('Ambiguous delivery receivedAt wall time. Supply an explicit UTC offset or a DateTimeInterface instant.');
                }

                $previousOffset = $offset;
            }
        }

        return $normalized->setTimezone('UTC');
    }

    private function horizonEnd(CarbonImmutable $lastDate, string $timezone): CarbonImmutable
    {
        $boundary = DateTimeImmutable::createFromFormat('!Y-m-d', $lastDate->addDay()->toDateString(), new DateTimeZone($timezone));
        $end = CarbonImmutable::instance($boundary)->setTimezone('UTC');
        $wallTime = $boundary->format('Y-m-d H:i:s.u');
        $wallTimestamp = $boundary->getTimestamp() + $boundary->getOffset();
        $transitions = (new DateTimeZone($timezone))->getTransitions($end->getTimestamp() - 172800, $end->getTimestamp() + 172800) ?: [];

        // Some historical folds cross midnight and repeat the previous date. Use the latest
        // occurrence of the boundary; every stage still checks its actual plan-local date.
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $alternative = CarbonImmutable::createFromTimestamp($wallTimestamp - $offset, 'UTC');

            if ($alternative->setTimezone($timezone)->format('Y-m-d H:i:s.u') === $wallTime && $alternative->greaterThan($end)) {
                $end = $alternative;
            }
        }

        return $end->subMicrosecond();
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function work(CarbonImmutable $cursor, BusinessDayCalculator $calculator, string $timezone, int $minutes, CarbonImmutable $horizonEnd, bool $overnight): array
    {
        $start = $calculator->nextBusinessTime($cursor->setTimezone($timezone));

        if ($start->setTimezone('UTC')->greaterThan($horizonEnd)) {
            throw new RuntimeException('No working start exists within the global planning horizon.');
        }

        if ($minutes > 0) {
            $this->assertWorkCapacity($start, $calculator, $minutes, $horizonEnd->setTimezone($timezone), $overnight);
            $completion = $calculator->addBusinessMinutes($start, $minutes);
        } else {
            $completion = $start;
        }

        return [$start->setTimezone('UTC'), $completion->setTimezone('UTC')];
    }

    private function assertWorkCapacity(CarbonImmutable $start, BusinessDayCalculator $calculator, int $minutes, CarbonImmutable $end, bool $overnight): void
    {
        // Bound the legacy duration loop before calling it, without reimplementing minute arithmetic.
        // Stop as soon as enough capacity exists; short stages do not scan the entire horizon.
        $remaining = $minutes;
        $probe = $start;

        while ($probe->lessThan($end)) {
            $nextDate = $probe->startOfDay()->addDay()->startOfDay();

            if ($nextDate->lessThanOrEqualTo($probe)) {
                throw new RuntimeException('Working-capacity date search made no progress.');
            }

            $sliceEnd = $overnight ? $nextDate : $nextDate->subMicrosecond();
            $sliceEnd = $sliceEnd->lessThan($end) ? $sliceEnd : $end;
            $available = $calculator->diffBusinessMinutes($probe, $sliceEnd);

            if ($available >= $remaining) {
                return;
            }

            $remaining -= $available;
            $probe = $nextDate;
        }

        // Daily integer totals can discard fractional minutes at both the initial cursor
        // and a profile-local horizon boundary. Recheck only a failing overnight request
        // against one aggregate interval; ordinary short stages still stop immediately.
        if ($overnight && $calculator->diffBusinessMinutes($start, $end) >= $minutes) {
            return;
        }

        throw new RuntimeException('Insufficient business minutes within the global planning horizon.');
    }

    /**
     * @param  list<string>  $profiles
     * @param  array<string, BusinessDayCalculator>  $calculators
     * @param  array<string, string>  $profileTimezones
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function handoff(CarbonImmutable $cursor, array $profiles, array $calculators, array $profileTimezones, string $planTimezone, string $lastDate): array
    {
        $candidate = $cursor;

        while ($candidate->setTimezone($planTimezone)->toDateString() <= $lastDate) {
            $this->assertWithinHorizon($candidate, $planTimezone, $lastDate);
            $next = $candidate;

            foreach ($profiles as $profile) {
                $openAt = $calculators[$profile]->nextBusinessTime($candidate->setTimezone($profileTimezones[$profile]))->setTimezone('UTC');
                $this->assertWithinHorizon($openAt, $planTimezone, $lastDate);

                if ($openAt->lessThan($candidate)) {
                    throw new RuntimeException('Handoff working-time search moved backward.');
                }

                $next = $openAt->greaterThan($next) ? $openAt : $next;
            }

            if ($calculators[$profiles[0]]->isBusinessTime($next->setTimezone($profileTimezones[$profiles[0]]))
                && $calculators[$profiles[1]]->isBusinessTime($next->setTimezone($profileTimezones[$profiles[1]]))) {
                return [$next, $next];
            }

            if ($next->lessThanOrEqualTo($candidate)) {
                throw new RuntimeException('Handoff search made no progress toward a shared working instant.');
            }

            $candidate = $next;
        }

        throw new RuntimeException('No shared working instant exists within the global planning horizon.');
    }

    private function businessDays(CarbonImmutable $cursor, BusinessDayCalculator $calculator, string $timezone, int $days, CarbonImmutable $horizonEnd): CarbonImmutable
    {
        if ($days === 0) {
            return $cursor;
        }

        $local = $cursor->setTimezone($timezone);
        $dateLabel = CarbonImmutable::createFromFormat('!Y-m-d', $local->toDateString(), 'UTC');
        $lastLocalDate = $horizonEnd->setTimezone($timezone)->toDateString();
        $counted = 0;

        while ($dateLabel->toDateString() < $lastLocalDate) {
            $dateLabel = $dateLabel->addDay();
            $label = $dateLabel->toDateString();
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $label, new DateTimeZone($timezone));

            if ($date === false || $date->format('Y-m-d') !== $label) {
                continue; // A skipped civil date has no business-date instants to count.
            }

            if (! $calculator->isBusinessDay(CarbonImmutable::instance($date))) {
                continue;
            }

            $counted++;

            if ($counted === $days) {
                $wallTime = $label.' '.$local->format('H:i:s.u');
                $target = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $wallTime, new DateTimeZone($timezone));

                if ($target === false || $target->format('Y-m-d H:i:s.u') !== $wallTime) {
                    throw new RuntimeException(sprintf('The business_days target wall time [%s] does not exist in timezone [%s].', $wallTime, $timezone));
                }

                return CarbonImmutable::instance($target)->setTimezone('UTC');
            }
        }

        throw new RuntimeException('Unable to count the requested future business dates within the global planning horizon.');
    }

    private function assertWithinHorizon(CarbonImmutable $instant, string $timezone, string $lastDate): void
    {
        if ($instant->setTimezone($timezone)->toDateString() > $lastDate) {
            throw new RuntimeException(sprintf('The stage exceeds the last permitted plan date [%s] in timezone [%s].', $lastDate, $timezone));
        }
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private function stageFailure(array $stage, int $limit, RuntimeException $exception): RuntimeException
    {
        return new RuntimeException(sprintf('%s failed within the global horizon of [%d] future calendar days: %s', $this->stageContext($stage), $limit, $exception->getMessage()), previous: $exception);
    }

    /**
     * @param  array<string, mixed>  $stage
     */
    private function stageContext(array $stage): string
    {
        return sprintf('Delivery stage [%s] (%s), profiles [%s]', $stage['id'], $stage['type'], implode(', ', $this->stageProfiles($stage)));
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return list<string>
     */
    private function stageProfiles(array $stage): array
    {
        return $stage['type'] === 'handoff' ? $stage['profiles'] : [$stage['profile']];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function validatePlan(array $plan): void
    {
        $this->rejectUnknownKeys($plan, ['timezone', 'profile_timezones', 'cutoff_profile', 'stages'], 'Delivery plan');
        $this->validateTimezone($plan['timezone'] ?? null, 'Delivery plan timezone');

        if (! isset($plan['profile_timezones']) || ! is_array($plan['profile_timezones'])) {
            throw new WorkdayConfigurationException('Delivery plan profile_timezones is required and must map profile names to timezone identifiers.');
        }

        foreach ($plan['profile_timezones'] as $profile => $timezone) {
            if (trim((string) $profile) === '') {
                throw new WorkdayConfigurationException('Delivery plan profile_timezones contains an empty profile name.');
            }

            $this->validateTimezone($timezone, sprintf('Delivery profile_timezones [%s]', $profile));
        }

        $stages = $plan['stages'] ?? null;

        if (! is_array($stages) || ! array_is_list($stages) || count($stages) < 1 || count($stages) > 32) {
            throw new WorkdayConfigurationException('Delivery plan stages must be a non-empty ordered list of at most 32 stages.');
        }

        $ids = [];
        $referencedProfiles = [];

        foreach ($stages as $index => $stage) {
            $context = sprintf('Delivery stage at index [%d]', $index);

            if (! is_array($stage)) {
                throw new WorkdayConfigurationException($context.' must be an array.');
            }

            if (! is_string($stage['id'] ?? null) || trim($stage['id']) === '') {
                throw new WorkdayConfigurationException($context.' requires a non-empty string id.');
            }

            $context = sprintf('Delivery stage [%s] at index [%d]', $stage['id'], $index);

            if (isset($ids[$stage['id']])) {
                throw new WorkdayConfigurationException($context.' has a duplicate stage id.');
            }

            $ids[$stage['id']] = true;
            $type = $stage['type'] ?? null;

            if (! is_string($type) || ! in_array($type, ['work', 'handoff', 'business_days'], true)) {
                throw new WorkdayConfigurationException($context.' has a missing or unsupported stage type.');
            }

            $allowed = match ($type) {
                'work' => ['id', 'type', 'profile', 'minutes'],
                'handoff' => ['id', 'type', 'profiles'],
                'business_days' => ['id', 'type', 'profile', 'days'],
            };
            $this->rejectUnknownKeys($stage, $allowed, $context);

            if ($type === 'handoff') {
                $profiles = $stage['profiles'] ?? null;

                if (! is_array($profiles) || ! array_is_list($profiles) || count($profiles) !== 2 || $profiles[0] === $profiles[1]) {
                    throw new WorkdayConfigurationException($context.' requires exactly two different profiles in an ordered list.');
                }
            } else {
                $profiles = [$stage['profile'] ?? null];
                $duration = $type === 'work' ? 'minutes' : 'days';

                if (! is_int($stage[$duration] ?? null) || $stage[$duration] < 0) {
                    throw new WorkdayConfigurationException(sprintf('%s requires %s as a non-negative integer; strings and floats are not accepted.', $context, $duration));
                }
            }

            foreach ($profiles as $profile) {
                if (! is_string($profile) || trim($profile) === '') {
                    throw new WorkdayConfigurationException($context.' requires non-empty profile names.');
                }

                if (! array_key_exists($profile, $plan['profile_timezones'])) {
                    throw new WorkdayConfigurationException(sprintf('%s is missing profile_timezones for profile [%s].', $context, $profile));
                }

                $referencedProfiles[$profile] = true;
            }
        }

        $profileConfigs = config('workdays.profiles', []);

        foreach (array_keys($plan['profile_timezones']) as $profile) {
            // Referenced profiles are resolved once below, with their stage's error context.
            if (! isset($referencedProfiles[$profile])
                && (! is_array($profileConfigs) || ! array_key_exists($profile, $profileConfigs))) {
                throw new WorkdayConfigurationException(sprintf('Delivery profile_timezones contains unknown profile [%s].', $profile));
            }
        }

        if (array_key_exists('cutoff_profile', $plan)) {
            $profile = $plan['cutoff_profile'];
            $first = $stages[0];

            if (! is_string($profile) || trim($profile) === '' || $first['type'] !== 'work' || $profile !== $first['profile']) {
                throw new WorkdayConfigurationException(sprintf('Delivery cutoff_profile [%s] must match the first work stage [%s] profile.', is_string($profile) ? $profile : get_debug_type($profile), $first['id']));
            }
        }
    }

    private function validateTimezone(mixed $timezone, string $context): void
    {
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new WorkdayConfigurationException($context.' must be a valid IANA timezone identifier.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  list<string>  $allowed
     */
    private function rejectUnknownKeys(array $config, array $allowed, string $context): void
    {
        foreach (array_keys($config) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new WorkdayConfigurationException(sprintf('%s contains unsupported key [%s].', $context, $key));
            }
        }
    }
}
