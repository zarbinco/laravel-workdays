<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

/** Immutable schedule definitions; calendar eligibility is never cached here. */
final readonly class EffectiveWorkingHours
{
    /**
     * @param  array<int, array<int, TimeWindow>>  $weekly
     * @param  array<string, array<int, TimeWindow>>  $special
     * @param  list<array{from: string, through: string, weekdays: array<int, array<int, TimeWindow>>}>  $seasons
     * @param  array<int, TimeWindow>  $fallback
     */
    private function __construct(
        private string $profile,
        private array $weekly,
        private array $special,
        private array $seasons,
        private array $fallback,
        private bool $overnight,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromProfileConfig(string $profile, array $config): self
    {
        $overnight = $config['allow_overnight_working_hours'] ?? false;

        if (array_key_exists('allow_overnight_working_hours', $config) && ! is_bool($config['allow_overnight_working_hours'])) {
            throw new WorkdayConfigurationException(sprintf('The allow_overnight_working_hours config for profile [%s] must be a boolean.', $profile));
        }

        $weekly = array_key_exists('working_hours', $config) ? WorkingHours::normalizeWorkingHours($profile, $config['working_hours'], $overnight) : [];
        $fallback = array_key_exists('extra_working_day_hours', $config) ? WorkingHours::normalizeWindows($profile, 'extra_working_day_hours', $config['extra_working_day_hours'], $overnight) : [];
        $special = [];
        $definitions = $config['special_working_hours'] ?? [];

        if (! is_array($definitions) || (array_key_exists('special_working_hours', $config) && $config['special_working_hours'] === null)) {
            throw new WorkdayConfigurationException(sprintf('The special_working_hours config for profile [%s] must be an array keyed by Gregorian dates.', $profile));
        }

        foreach ($definitions as $label => $windows) {
            self::validateDate($profile, 'special_working_hours', $label);
            $special[$label] = WorkingHours::normalizeWindows($profile, 'special_working_hours.'.$label, $windows, $overnight);
        }

        $definitions = $config['seasonal_working_hours'] ?? [];

        if (! is_array($definitions) || ! array_is_list($definitions)
            || (array_key_exists('seasonal_working_hours', $config) && $config['seasonal_working_hours'] === null)) {
            throw new WorkdayConfigurationException(sprintf('The seasonal_working_hours config for profile [%s] must be an ordered list.', $profile));
        }

        $seasons = [];

        foreach ($definitions as $index => $season) {
            $section = 'seasonal_working_hours.'.$index;

            if (! is_array($season) || array_diff(array_keys($season), ['from', 'through', 'weekdays']) !== []
                || ! array_key_exists('from', $season) || ! array_key_exists('through', $season) || ! array_key_exists('weekdays', $season)) {
                throw new WorkdayConfigurationException(sprintf('The %s config for profile [%s] requires only from, through and weekdays.', $section, $profile));
            }

            self::validateDate($profile, $section.'.from', $season['from']);
            self::validateDate($profile, $section.'.through', $season['through']);

            if ($season['from'] > $season['through']) {
                throw new WorkdayConfigurationException(sprintf('The %s from date must not exceed through for profile [%s].', $section, $profile));
            }

            try {
                $weekdays = WorkingHours::normalizeWorkingHours($profile, $season['weekdays'], $overnight);
            } catch (WorkdayConfigurationException $exception) {
                throw new WorkdayConfigurationException(sprintf('Invalid %s weekdays for profile [%s]: %s', $section, $profile, $exception->getMessage()), previous: $exception);
            }

            $seasons[] = [
                'from' => $season['from'],
                'through' => $season['through'],
                'weekdays' => $weekdays,
            ];
        }

        usort($seasons, static fn (array $left, array $right): int => $left['from'] <=> $right['from']);

        foreach ($seasons as $index => $season) {
            if ($index > 0 && $season['from'] <= $seasons[$index - 1]['through']) {
                throw new WorkdayConfigurationException(sprintf('The seasonal_working_hours date ranges for profile [%s] must not overlap.', $profile));
            }
        }

        return new self($profile, $weekly, $special, $seasons, $fallback, $overnight);
    }

    /**
     * @param  callable(CarbonImmutable): bool  $isBusinessDate
     * @param  callable(CarbonImmutable): bool  $isExtraWorkingDay
     * @return array<int, TimeWindow>
     */
    public function windowsFor(CarbonImmutable $date, callable $isBusinessDate, callable $isExtraWorkingDay): array
    {
        $label = $date->toDateString();
        $zone = $date->getTimezone();
        $fragments = [];

        foreach ($this->startingWindows($date, $isBusinessDate, $isExtraWorkingDay) as $window) {
            $this->validateShift($label, $window, $zone);
            $fragments[] = new TimeWindow($window->start, $window->endMinutes() < $window->startMinutes() ? '24:00' : $window->end);
        }

        if ($this->overnight) {
            // Only syntax selection may inspect a label outside the finite instant range.
            // Ordinary daytime windows require no previous-date boundary or calendar query.
            $previousLabel = CarbonImmutable::createFromFormat('!Y-m-d', $label, 'UTC')->subDay();
            if ($this->mayCarryFrom($previousLabel)) {
                $previous = WorkingTimeBoundary::date($previousLabel->toDateString(), $zone);

                if ($previous !== null) {
                    foreach ($this->startingWindows($previous, $isBusinessDate, $isExtraWorkingDay) as $window) {
                        if ($window->endMinutes() < $window->startMinutes()) {
                            $this->validateShift($previous->toDateString(), $window, $zone);

                            if ($window->endMinutes() > 0) {
                                $fragments[] = new TimeWindow('00:00', $window->end);
                            }
                        }
                    }
                }
            }
        }

        usort($fragments, static fn (TimeWindow $left, TimeWindow $right): int => $left->startMinutes() <=> $right->startMinutes());
        $effective = [];

        foreach ($fragments as $fragment) {
            WorkingTimeBoundary::at($this->profile, $label, $fragment->startMinutes(), $zone, $fragment->startMinutes() === 0);
            WorkingTimeBoundary::at($this->profile, $label, $fragment->endMinutes(), $zone, $fragment->endMinutes() === 1440);
            $index = count($effective) - 1;
            $previous = $effective[$index] ?? null;

            // Only genuine overlap is unioned; independently configured touching windows stay separate.
            if ($previous !== null && $fragment->startMinutes() < $previous->endMinutes()) {
                $effective[$index] = new TimeWindow($previous->start, $fragment->endMinutes() > $previous->endMinutes() ? $fragment->end : $previous->end);
            } else {
                $effective[] = $fragment;
            }
        }

        return $effective;
    }

    /**
     * @param  callable(CarbonImmutable): bool  $isBusinessDate
     * @param  callable(CarbonImmutable): bool  $isExtraWorkingDay
     * @return array<int, TimeWindow>
     */
    private function startingWindows(CarbonImmutable $date, callable $isBusinessDate, callable $isExtraWorkingDay): array
    {
        if (! $isBusinessDate($date)) {
            return [];
        }

        $label = $date->toDateString();

        if (array_key_exists($label, $this->special)) {
            return $this->special[$label];
        }

        foreach ($this->seasons as $season) {
            if ($label >= $season['from'] && $label <= $season['through'] && array_key_exists($date->isoWeekday(), $season['weekdays'])) {
                return $season['weekdays'][$date->isoWeekday()];
            }
        }

        $windows = $this->weekly[$date->isoWeekday()] ?? [];

        if ($windows !== [] || ! $isExtraWorkingDay($date)) {
            return $windows;
        }

        if ($this->fallback === []) {
            throw new WorkdayConfigurationException(sprintf('Extra working day hours are not configured for profile [%s]. Configure working_hours for that weekday or profiles.%s.extra_working_day_hours.', $this->profile, $this->profile));
        }

        return $this->fallback;
    }

    private function validateShift(string $label, TimeWindow $window, DateTimeZone $zone): void
    {
        WorkingTimeBoundary::at($this->profile, $label, $window->startMinutes(), $zone);
        $endLabel = $window->endMinutes() < $window->startMinutes() ? WorkingTimeBoundary::labelAfter($label, 1) : $label;
        WorkingTimeBoundary::at($this->profile, $endLabel, $window->endMinutes(), $zone);
    }

    /** Check actual originating syntax before querying an unrelated previous calendar date. */
    private function mayCarryFrom(CarbonImmutable $date): bool
    {
        $windows = $this->special[$date->toDateString()] ?? null;
        if ($windows === null) {
            foreach ($this->seasons as $season) {
                if ($date->toDateString() >= $season['from'] && $date->toDateString() <= $season['through']
                    && array_key_exists($date->isoWeekday(), $season['weekdays'])) {
                    $windows = $season['weekdays'][$date->isoWeekday()];
                    break;
                }
            }
        }
        $windows ??= ($this->weekly[$date->isoWeekday()] ?? []) ?: $this->fallback;
        foreach ($windows as $window) {
            if ($window->endMinutes() < $window->startMinutes()) {
                return true;
            }
        }

        return false;
    }

    private static function validateDate(string $profile, string $section, mixed $label): void
    {
        if (! is_string($label) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $label)
            || ! checkdate((int) substr($label, 5, 2), (int) substr($label, 8, 2), (int) substr($label, 0, 4))) {
            throw new WorkdayConfigurationException(sprintf('Invalid %s date [%s] for profile [%s]. Expected a valid Gregorian Y-m-d date.', $section, is_scalar($label) ? (string) $label : get_debug_type($label), $profile));
        }
    }
}
