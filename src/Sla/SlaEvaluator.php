<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Sla;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Data\SlaResult;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Support\CutoffDateTimeNormalizer;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\WorkingTimeBoundary;

/** Exact interval accounting, isolated from legacy whole-minute duration methods. */
final readonly class SlaEvaluator
{
    private const MINUTE = 60000000;

    private Closure $resolveCalculator;

    private ?Closure $resolveProfileConfig;

    /**
     * @param  callable(string, int): BusinessDayCalculator  $resolveCalculator
     * @param  null|callable(string): array<string, mixed>  $resolveProfileConfig
     */
    public function __construct(callable $resolveCalculator, ?callable $resolveProfileConfig = null, private ?array $scanSettings = null)
    {
        $this->resolveCalculator = Closure::fromCallable($resolveCalculator);
        $this->resolveProfileConfig = $resolveProfileConfig === null ? null : Closure::fromCallable($resolveProfileConfig);
    }

    /** @param array<string, mixed> $options */
    public function evaluate(string|DateTimeInterface $startedAt, string|DateTimeInterface $evaluatedAt, array $options): SlaResult
    {
        $this->validateOptions($options);
        $profile = $options['profile'];
        $timezone = $options['timezone'];
        $targetMinutes = $options['target_minutes'];
        $startedAt = $this->instant($startedAt, $timezone, 'startedAt');
        $evaluatedAt = $this->instant($evaluatedAt, $timezone, 'evaluatedAt');
        $start = self::micros($startedAt);
        $evaluation = self::micros($evaluatedAt);

        if ($evaluation < $start) {
            throw new InvalidArgumentException('SLA evaluatedAt must not precede startedAt.');
        }

        [$pauses, $paused] = $this->pauses($options['pauses'] ?? [], $timezone, $start, $evaluation);

        try {
            $limit = ProfileConfigValidator::validateMaxScanDays($this->scanSettings === null ? config('workdays.max_scan_days', 3660) : $this->scanSettings['max_scan_days']);
            $calculator = ($this->resolveCalculator)($profile, $limit);
            $effective = $this->profileConfig($profile);

            if (! array_key_exists('working_hours', $effective)) {
                throw new WorkdayConfigurationException(sprintf('Working hours are not configured for SLA profile [%s].', $profile));
            }
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException('Invalid SLA profile configuration: '.$exception->getMessage(), previous: $exception);
        }

        $zone = new DateTimeZone($timezone);
        $anchor = $startedAt->setTimezone($zone)->toDateString();

        try {
            $lastDate = WorkingTimeBoundary::labelAfter($anchor, $limit);
        } catch (Throwable $exception) {
            throw $this->failure($profile, $limit, 'The configured date horizon cannot be represented.', $exception);
        }

        if ($evaluatedAt->setTimezone($zone)->toDateString() > $lastDate) {
            throw $this->failure($profile, $limit, 'evaluatedAt lies beyond the last permitted local date ['.$lastDate.'].');
        }

        $target = $targetMinutes * self::MINUTE;
        $consumed = 0;
        $pausedTime = 0;
        $eligible = 0;
        $due = null;
        $pauseIndex = 0;
        $pending = [];

        // Each callback sees disjoint chronological working fragments, not rounded minutes.
        $active = static function (int $from, int $through) use (&$consumed, &$eligible, &$due, $evaluation, $target): void {
            if ($through <= $from) {
                return;
            }

            $duration = self::duration($from, $through);

            if ($due === null && $duration >= $target - $eligible) {
                $due = $from + ($target - $eligible);
            }

            // After reaching the budget, only the evaluation accounting needs to grow.
            $eligible = min($target, $eligible + min($duration, $target - $eligible));
            $observedEnd = min($through, $evaluation);

            if ($observedEnd > $from) {
                $consumed = self::sum($consumed, self::duration($from, $observedEnd));
            }
        };
        $account = static function (int $from, int $through) use ($pauses, &$pauseIndex, &$pausedTime, $evaluation, $active): void {
            $cursor = $from;

            while ($cursor < $through && isset($pauses[$pauseIndex])) {
                [$pauseStart, $pauseEnd] = $pauses[$pauseIndex];

                if ($pauseEnd !== null && $pauseEnd <= $cursor) {
                    $pauseIndex++;

                    continue;
                }

                if ($pauseStart >= $through) {
                    break;
                }

                $active($cursor, min($through, max($cursor, $pauseStart)));
                $cursor = max($cursor, $pauseStart);
                $end = min($through, $pauseEnd ?? $through);
                $observedEnd = min($end, $evaluation);

                if ($observedEnd > $cursor) {
                    $pausedTime = self::sum($pausedTime, self::duration($cursor, $observedEnd));
                }

                $cursor = $end;

                if ($pauseEnd !== null && $pauseEnd <= $cursor) {
                    $pauseIndex++;
                }
            }

            $active($cursor, $through);
        };

        // An open pause beginning at the start/evaluation needs no future windows at all.
        $finished = $paused && $evaluation === $start;

        for ($label = $anchor; ! $finished && $label <= $lastDate; $label = WorkingTimeBoundary::labelAfter($label, 1)) {
            $date = WorkingTimeBoundary::date($label, $zone);

            if ($date === null) {
                continue; // Date-label budget is not extended by a skipped civil date.
            }

            $nextLabel = WorkingTimeBoundary::labelAfter($label, 1);
            $watermark = self::micros($this->earliestBoundary($nextLabel, $zone));

            foreach ($calculator->workingWindowsFor($date) as $window) {
                $from = WorkingTimeBoundary::at($profile, $label, $window->startMinutes(), $zone, $window->startMinutes() === 0);
                $through = WorkingTimeBoundary::at($profile, $label, $window->endMinutes(), $zone, $window->endMinutes() === 1440);
                $from = max($start, self::micros($from));
                $through = self::micros($through);

                if ($through > $from) {
                    $pending[] = [$from, $through];
                }
            }

            // Merge spill/overlap across date rows before committing anything past midnight.
            $pending = self::union($pending);
            $tail = [];

            foreach ($pending as [$from, $through]) {
                $end = min($through, $watermark);

                if ($end > $from) {
                    $account($from, $end);
                }

                if ($through > $watermark) {
                    $tail[] = [max($from, $watermark), $through];
                }
            }

            $pending = $tail;
            $finished = $watermark >= $evaluation && ($due !== null || $paused);

            if ($finished) {
                break;
            }
        }

        $dueAt = $due === null ? null : self::fromMicros($due);

        if (! $finished || ($dueAt === null && ! $paused) || ($dueAt !== null && $dueAt->setTimezone($zone)->toDateString() > $lastDate)) {
            throw $this->failure($profile, $limit, 'Unable to resolve the SLA deadline and evaluation within the last permitted local date ['.$lastDate.'].');
        }

        $remaining = max(0, $target - $consumed);
        $breached = $due !== null && $evaluation > $due;

        return new SlaResult(
            $profile, $timezone, $startedAt, $evaluatedAt, $targetMinutes,
            intdiv($consumed, self::MINUTE), intdiv($pausedTime, self::MINUTE),
            intdiv($remaining, self::MINUTE) + ($remaining % self::MINUTE === 0 ? 0 : 1),
            intdiv(max(0, $consumed - $target), self::MINUTE), $dueAt,
            $paused, $breached, $breached ? 'breached' : ($paused ? 'paused' : 'active'),
        );
    }

    private function validateOptions(array $options): void
    {
        if (PHP_INT_SIZE < 8) {
            throw new WorkdayConfigurationException('Exact SLA accounting requires 64-bit PHP integers.');
        }

        foreach (array_keys($options) as $key) {
            if (! in_array($key, ['profile', 'timezone', 'target_minutes', 'pauses'], true)) {
                throw new WorkdayConfigurationException('Unknown SLA option ['.$key.'].');
            }
        }

        if (! is_string($options['profile'] ?? null) || trim($options['profile']) === '') {
            throw new WorkdayConfigurationException('SLA profile must be a configured non-empty name.');
        }

        if (! is_string($options['timezone'] ?? null) || ! in_array($options['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new WorkdayConfigurationException('SLA timezone must be a valid IANA timezone identifier.');
        }

        if (! is_int($options['target_minutes'] ?? null) || $options['target_minutes'] < 1 || $options['target_minutes'] > 1000000) {
            throw new WorkdayConfigurationException('SLA target_minutes must be an integer from 1 to 1,000,000.');
        }

        if (array_key_exists('pauses', $options) && (! is_array($options['pauses']) || ! array_is_list($options['pauses']) || count($options['pauses']) > 1000)) {
            throw new WorkdayConfigurationException('SLA pauses must be an ordered list of at most 1,000 intervals.');
        }
    }

    /** @return array{list<array{int, ?int}>, bool} */
    private function pauses(array $pauses, string $timezone, int $start, int $evaluation): array
    {
        $intervals = [];
        $open = false;

        foreach ($pauses as $pause) {
            if (! is_array($pause) || count($pause) !== 2 || ! array_key_exists('started_at', $pause) || ! array_key_exists('ended_at', $pause)) {
                throw new WorkdayConfigurationException('Each SLA pause must contain exactly started_at and ended_at.');
            }

            $from = self::micros($this->instant($pause['started_at'], $timezone, 'pause.started_at'));
            $through = $pause['ended_at'] === null ? null : self::micros($this->instant($pause['ended_at'], $timezone, 'pause.ended_at'));

            if ($from < $start || $from > $evaluation || ($through !== null && ($through <= $from || $through > $evaluation))) {
                throw new InvalidArgumentException('SLA pauses must begin between startedAt and evaluatedAt; a closed pause must end strictly after its start and no later than evaluatedAt.');
            }

            if ($through === null && $open) {
                throw new InvalidArgumentException('At most one SLA pause may be open-ended.');
            }

            $open = $open || $through === null;
            $intervals[] = [$from, $through];
        }

        return [self::union($intervals), $open];
    }

    /** @param list<array{int, ?int}> $intervals @return list<array{int, ?int}> */
    private static function union(array $intervals): array
    {
        usort($intervals, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $merged = [];

        foreach ($intervals as $interval) {
            $last = count($merged) - 1;

            if ($last >= 0 && ($merged[$last][1] === null || $interval[0] <= $merged[$last][1])) {
                $merged[$last][1] = $merged[$last][1] === null || $interval[1] === null ? null : max($merged[$last][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    private function instant(mixed $value, string $timezone, string $field): CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('SLA '.$field.' must be an absolute timestamp or DateTimeInterface instant.');
        }

        try {
            $local = CutoffDateTimeNormalizer::toImmutable($value, $timezone);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid SLA '.$field.': '.$exception->getMessage(), previous: $exception);
        }

        if (is_string($value) && str_contains($value, ' ')) {
            $zone = new DateTimeZone($timezone);
            $transitions = $zone->getTransitions($local->getTimestamp() - 172800, $local->getTimestamp() + 172800) ?: [];
            $wall = $local->getTimestamp() + $local->getOffset();

            foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
                $alternative = CarbonImmutable::createFromTimestamp($wall - $offset, 'UTC')->setTimezone($zone);

                if ($alternative->getTimestamp() !== $local->getTimestamp() && $alternative->format('Y-m-d H:i:s') === $local->format('Y-m-d H:i:s')) {
                    throw new InvalidArgumentException('Ambiguous SLA '.$field.' wall time; supply an explicit UTC offset or DateTimeInterface instant.');
                }
            }
        }

        return $local->setTimezone('UTC');
    }

    /** Conservative watermark: no next-row window can begin before this native date boundary. */
    private function earliestBoundary(string $label, DateTimeZone $zone): CarbonImmutable
    {
        $native = DateTimeImmutable::createFromFormat('!Y-m-d', $label, $zone);

        if ($native === false) {
            throw new RuntimeException('SLA calendar date boundary cannot be represented.');
        }

        $earliest = CarbonImmutable::instance($native)->setTimezone('UTC');
        $wall = $native->getTimestamp() + $native->getOffset();
        $transitions = $zone->getTransitions($native->getTimestamp() - 172800, $native->getTimestamp() + 172800) ?: [];

        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $alternative = CarbonImmutable::createFromTimestamp($wall - $offset, 'UTC');

            if ($alternative->lessThan($earliest) && $alternative->setTimezone($zone)->format('Y-m-d H:i:s.u') === $native->format('Y-m-d H:i:s.u')) {
                $earliest = $alternative;
            }
        }

        return $earliest;
    }

    private function profileConfig(string $profile): array
    {
        if ($this->resolveProfileConfig !== null) {
            return ($this->resolveProfileConfig)($profile);
        }

        $profiles = config('workdays.profiles', []);

        if (! is_array($profiles) || ! array_key_exists($profile, $profiles)) {
            throw new WorkdayConfigurationException(sprintf('SLA profile [%s] is not configured.', $profile));
        }

        return is_array($profiles[$profile]) && array_key_exists('extends', $profiles[$profile])
            ? (new ProfileConfigResolver($profiles))->resolve($profile)
            : ProfileConfigValidator::validate($profile, $profiles[$profile]);
    }

    private static function micros(CarbonImmutable $instant): int
    {
        $seconds = $instant->getTimestamp();
        $bound = intdiv(PHP_INT_MAX, 1000000) - 1;

        if ($seconds < -$bound || $seconds > $bound) {
            throw new InvalidArgumentException('SLA timestamp exceeds the safe 64-bit microsecond range.');
        }

        return $seconds * 1000000 + (int) $instant->format('u');
    }

    private static function fromMicros(int $value): CarbonImmutable
    {
        $seconds = intdiv($value, 1000000);
        $microseconds = $value % 1000000;

        if ($microseconds < 0) {
            $seconds--;
            $microseconds += 1000000;
        }

        return CarbonImmutable::createFromTimestamp($seconds, 'UTC')->setMicrosecond($microseconds);
    }

    private static function duration(int $from, int $through): int
    {
        if ($from < 0 && $through > PHP_INT_MAX + $from) {
            throw new RuntimeException('SLA duration exceeds safe 64-bit microsecond arithmetic.');
        }

        return $through - $from;
    }

    private static function sum(int $total, int $duration): int
    {
        if ($duration > PHP_INT_MAX - $total) {
            throw new RuntimeException('SLA accounting exceeds safe 64-bit microsecond arithmetic.');
        }

        return $total + $duration;
    }

    private function failure(string $profile, int $limit, string $message, ?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(sprintf('SLA profile [%s] failed within the global horizon of [%d] future local calendar dates: %s', $profile, $limit, $message), previous: $previous);
    }
}
