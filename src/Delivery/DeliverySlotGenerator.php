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
use Zarbinco\LaravelWorkdays\Data\DeliverySlot;
use Zarbinco\LaravelWorkdays\Data\DeliverySlotsResult;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Support\CutoffDateTimeNormalizer;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\WorkingTimeBoundary;

final readonly class DeliverySlotGenerator
{
    private Closure $resolveCalculator;

    /**
     * @param  callable(string): BusinessDayCalculator  $resolveCalculator
     */
    public function __construct(callable $resolveCalculator, private int $maxScanDays)
    {
        $this->resolveCalculator = Closure::fromCallable($resolveCalculator);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(string|DateTimeInterface $readyAt, array $options): DeliverySlotsResult
    {
        $options = $this->validateOptions($options);
        $profile = $options['profile'];
        $timezone = $options['timezone'];

        try {
            $calculator = ($this->resolveCalculator)($profile);
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException(sprintf('Delivery slots profile [%s]: %s', $profile, $exception->getMessage()), previous: $exception);
        }

        try {
            $localReady = CutoffDateTimeNormalizer::toImmutable($readyAt, $timezone);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid delivery-slot readyAt. Supply a supported absolute Gregorian datetime; relative, invalid and nonexistent local times are not allowed.', previous: $exception);
        }

        $zone = new DateTimeZone($timezone);
        $firstDate = CarbonImmutable::createFromFormat('!Y-m-d', $localReady->toDateString(), 'UTC');
        // One bounded lookup covers candidate offsets and folds, including historical date jumps.
        $transitions = $zone->getTransitions($firstDate->getTimestamp() - 172800, $firstDate->addDays($options['days'])->getTimestamp() + 172800) ?: [];

        if (is_string($readyAt) && str_contains($readyAt, ' ') && $this->isAmbiguous($localReady, $transitions)) {
            throw new InvalidArgumentException('Ambiguous delivery-slot readyAt wall time. Supply an explicit UTC offset or a DateTimeInterface instant.');
        }

        $readyAt = $localReady->setTimezone('UTC');
        $eligibleFromAt = $this->addNotice($readyAt, $options['min_notice_minutes']);
        $slots = [];
        $seen = [];

        for ($day = 0; $day < $options['days']; $day++) {
            $label = $firstDate->addDays($day)->toDateString();
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $label, $zone);

            if ($date === false || $date->format('Y-m-d') !== $label) {
                continue; // A skipped civil date consumes its label in the requested range.
            }

            try {
                $windows = $calculator->workingWindowsFor(CarbonImmutable::instance($date));
            } catch (InvalidArgumentException $exception) {
                throw new WorkdayConfigurationException(sprintf('Delivery slots profile [%s] on [%s]: %s', $profile, $label, $exception->getMessage()), previous: $exception);
            } catch (RuntimeException $exception) {
                throw new RuntimeException(sprintf('Delivery slots profile [%s] on [%s]: %s', $profile, $label, $exception->getMessage()), previous: $exception);
            }

            foreach ($windows as $window) {
                $lastStart = $window->endMinutes() - $options['duration_minutes'];

                // Integer wall-minute steps keep each window's grid independent of readiness.
                for ($minute = $window->startMinutes(); $minute <= $lastStart; $minute += $options['step_minutes']) {
                    $start = WorkingTimeBoundary::candidateAt($profile, $label, $minute, $zone)?->setTimezone('UTC');

                    if ($start === null || $start->lessThan($eligibleFromAt)) {
                        continue;
                    }

                    $end = WorkingTimeBoundary::candidateAt($profile, $label, $minute + $options['duration_minutes'], $zone)?->setTimezone('UTC');

                    if ($end === null
                        || $end->getTimestamp() - $start->getTimestamp() !== $options['duration_minutes'] * 60
                        || $this->crossesTransition($start, $end, $transitions)) {
                        continue;
                    }

                    $key = $start->format('U.u').'|'.$end->format('U.u');

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $slots[] = new DeliverySlot($start, $end, $timezone);

                    // Unambiguous local grid order is UTC order; folds/gaps cannot duplicate it.
                    if (count($slots) === $options['max_slots']) {
                        return new DeliverySlotsResult($profile, $timezone, $readyAt, $eligibleFromAt, $options['days'], $slots);
                    }
                }
            }
        }

        return new DeliverySlotsResult($profile, $timezone, $readyAt, $eligibleFromAt, $options['days'], $slots);
    }

    private function addNotice(CarbonImmutable $readyAt, int $minutes): CarbonImmutable
    {
        if ($minutes === 0) {
            return $readyAt;
        }

        // Split the timestamp before addition so even a pre-epoch input cannot overflow
        // an intermediate minutes-to-seconds product whose final sum would still fit.
        $timestamp = $readyAt->getTimestamp();
        $wholeMinutes = intdiv($timestamp, 60);
        $seconds = $timestamp % 60;

        if ($seconds < 0) {
            $wholeMinutes--;
            $seconds += 60;
        }

        $lastWholeMinute = intdiv(PHP_INT_MAX - $seconds, 60);

        if ($minutes > $lastWholeMinute - $wholeMinutes) {
            throw new WorkdayConfigurationException('The delivery-slot min_notice_minutes exceeds the representable datetime range.');
        }

        $eligibleTimestamp = ($wholeMinutes + $minutes) * 60 + $seconds;
        $eligible = DateTimeImmutable::createFromFormat('U.u', (string) $eligibleTimestamp.'.'.$readyAt->format('u'));

        if ($eligible === false) {
            throw new WorkdayConfigurationException('The delivery-slot min_notice_minutes cannot be represented as a datetime.');
        }

        return CarbonImmutable::instance($eligible)->setTimezone('UTC');
    }

    /**
     * @param  array<int, array{ts: int, offset: int, time: string, isdst: bool, abbr: string}>  $transitions
     */
    private function isAmbiguous(CarbonImmutable $local, array $transitions): bool
    {
        $wallTimestamp = $local->getTimestamp() + $local->getOffset();
        $previousOffset = null;

        foreach ($transitions as $transition) {
            $offset = $transition['offset'];

            if ($previousOffset !== null && $offset < $previousOffset
                && $wallTimestamp >= $transition['ts'] + $offset
                && $wallTimestamp < $transition['ts'] + $previousOffset) {
                return true;
            }

            $previousOffset = $offset;
        }

        return false;
    }

    /**
     * @param  array<int, array{ts: int, offset: int, time: string, isdst: bool, abbr: string}>  $transitions
     */
    private function crossesTransition(CarbonImmutable $start, CarbonImmutable $end, array $transitions): bool
    {
        $previousOffset = null;

        foreach ($transitions as $transition) {
            if ($previousOffset !== null && $transition['offset'] !== $previousOffset
                && $transition['ts'] > $start->getTimestamp() && $transition['ts'] < $end->getTimestamp()) {
                return true;
            }

            $previousOffset = $transition['offset'];
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{profile: string, timezone: string, days: int, duration_minutes: int, step_minutes: int, min_notice_minutes: int, max_slots: int}
     */
    private function validateOptions(array $options): array
    {
        $required = ['profile', 'timezone', 'days', 'duration_minutes', 'step_minutes'];

        foreach (array_keys($options) as $key) {
            if (! in_array($key, [...$required, 'min_notice_minutes', 'max_slots'], true)) {
                throw new WorkdayConfigurationException(sprintf('Delivery slots contain unsupported option [%s].', $key));
            }
        }

        foreach ($required as $key) {
            if (! array_key_exists($key, $options)) {
                throw new WorkdayConfigurationException(sprintf('Delivery slots require option [%s].', $key));
            }
        }

        if (! is_string($options['profile']) || trim($options['profile']) === '') {
            throw new WorkdayConfigurationException('Delivery slots profile must be a non-empty configured profile name.');
        }

        if (! is_string($options['timezone']) || ! in_array($options['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new WorkdayConfigurationException('Delivery slots timezone must be a valid IANA timezone identifier.');
        }

        $options += ['min_notice_minutes' => 0, 'max_slots' => 50];
        $ranges = ['days' => [1, 31], 'duration_minutes' => [1, 1439], 'step_minutes' => [1, 1439], 'min_notice_minutes' => [0, PHP_INT_MAX], 'max_slots' => [1, 200]];

        foreach ($ranges as $key => [$min, $max]) {
            if (! is_int($options[$key]) || $options[$key] < $min || $options[$key] > $max) {
                throw new WorkdayConfigurationException(sprintf('Delivery slots %s must be an integer from %d to %d; strings, floats, booleans and null are not accepted.', $key, $min, $max));
            }
        }

        try {
            $limit = ProfileConfigValidator::validateMaxScanDays($this->maxScanDays);
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException('Invalid delivery-slot max_scan_days: '.$exception->getMessage(), previous: $exception);
        }

        if ($limit < $options['days'] - 1) {
            throw new WorkdayConfigurationException(sprintf('Delivery slots days [%d] exceeds the max_scan_days [%d] horizon; days - 1 must not exceed the limit.', $options['days'], $limit));
        }

        return $options;
    }
}
