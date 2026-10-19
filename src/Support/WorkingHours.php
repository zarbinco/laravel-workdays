<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

final class WorkingHours
{
    /**
     * @param  array<int, array<int, TimeWindow>>  $windowsByWeekday
     * @param  array<int, TimeWindow>  $extraWorkingDayWindows
     */
    private function __construct(
        private readonly string $profile,
        private readonly array $windowsByWeekday,
        private readonly array $extraWorkingDayWindows,
        private readonly ?EffectiveWorkingHours $effective = null,
    ) {}

    /**
     * @param  array<string, mixed>  $profileConfig
     */
    public static function fromProfileConfig(string $profile, array $profileConfig): self
    {
        if (! array_key_exists('working_hours', $profileConfig)) {
            throw new WorkdayConfigurationException(sprintf(
                'Working hours are not configured for profile [%s]. Configure profiles.%s.working_hours before using business-time methods.',
                $profile,
                $profile,
            ));
        }

        if (self::hasEnhancedConfig($profileConfig)) {
            return new self($profile, [], [], EffectiveWorkingHours::fromProfileConfig($profile, $profileConfig));
        }

        self::validateProfileConfig($profile, $profileConfig);

        /** @var array<int, array<int, TimeWindow>> $windowsByWeekday */
        $windowsByWeekday = self::normalizeWorkingHours($profile, $profileConfig['working_hours']);
        $extraWorkingDayWindows = array_key_exists('extra_working_day_hours', $profileConfig)
            ? self::normalizeWindows($profile, 'extra_working_day_hours', $profileConfig['extra_working_day_hours'])
            : [];

        return new self($profile, $windowsByWeekday, $extraWorkingDayWindows);
    }

    /**
     * @param  array<string, mixed>  $profileConfig
     */
    public static function validateProfileConfig(string $profile, array $profileConfig): void
    {
        if (array_key_exists('allow_overnight_working_hours', $profileConfig) && ! is_bool($profileConfig['allow_overnight_working_hours'])) {
            throw new WorkdayConfigurationException(sprintf('The allow_overnight_working_hours config for profile [%s] must be a boolean.', $profile));
        }

        if (self::hasEnhancedConfig($profileConfig)) {
            EffectiveWorkingHours::fromProfileConfig($profile, $profileConfig);

            return;
        }

        if (array_key_exists('working_hours', $profileConfig)) {
            self::normalizeWorkingHours($profile, $profileConfig['working_hours']);
        }

        if (array_key_exists('extra_working_day_hours', $profileConfig)) {
            self::normalizeWindows($profile, 'extra_working_day_hours', $profileConfig['extra_working_day_hours']);
        }
    }

    /**
     * @return array<int, TimeWindow>
     */
    public function windowsForIsoWeekday(int $weekday): array
    {
        return $this->windowsByWeekday[$weekday] ?? [];
    }

    /**
     * @return array<int, TimeWindow>
     */
    public function extraWorkingDayWindows(): array
    {
        return $this->extraWorkingDayWindows;
    }

    public function missingExtraWorkingDayHoursMessage(): string
    {
        return sprintf(
            'Extra working day hours are not configured for profile [%s]. Configure working_hours for that weekday or profiles.%s.extra_working_day_hours.',
            $this->profile,
            $this->profile,
        );
    }

    /** @param array<string, mixed> $profileConfig */
    public static function hasEnhancedConfig(array $profileConfig): bool
    {
        return ($profileConfig['allow_overnight_working_hours'] ?? false) === true
            || array_key_exists('special_working_hours', $profileConfig)
            || array_key_exists('seasonal_working_hours', $profileConfig);
    }

    public function effective(): ?EffectiveWorkingHours
    {
        return $this->effective;
    }

    /**
     * @return array<int, array<int, TimeWindow>>
     */
    public static function normalizeWorkingHours(string $profile, mixed $workingHours, bool $allowOvernight = false, bool $strict = false): array
    {
        if (! is_array($workingHours)) {
            throw new WorkdayConfigurationException(sprintf('The working_hours config for profile [%s] must be an array.', $profile));
        }

        $windowsByWeekday = [];

        foreach ($workingHours as $weekday => $windows) {
            if (! is_int($weekday) && ! is_string($weekday)) {
                throw new WorkdayConfigurationException(sprintf(
                    'Invalid working_hours weekday key for profile [%s]. Expected an ISO weekday integer or weekday name string.',
                    $profile,
                ));
            }

            try {
                $isoWeekday = WeekdayNormalizer::toIso($weekday);
            } catch (InvalidArgumentException $exception) {
                throw new WorkdayConfigurationException(sprintf(
                    'Invalid working_hours weekday [%s] for profile [%s]. Expected an ISO weekday integer from 1 to 7 or a supported weekday name.',
                    self::describe($weekday),
                    $profile,
                ), previous: $exception);
            }

            if (array_key_exists($isoWeekday, $windowsByWeekday)) {
                throw new WorkdayConfigurationException(sprintf(
                    'Duplicate working_hours weekday [%s] for profile [%s].',
                    self::describe($weekday),
                    $profile,
                ));
            }

            $windowsByWeekday[$isoWeekday] = self::normalizeWindows($profile, sprintf('working_hours.%s', self::describe($weekday)), $windows, $allowOvernight, $strict);
        }

        ksort($windowsByWeekday);

        return $windowsByWeekday;
    }

    /**
     * @return array<int, TimeWindow>
     */
    public static function normalizeWindows(string $profile, string $section, mixed $windows, bool $allowOvernight = false, bool $strict = false): array
    {
        if (! is_array($windows)) {
            throw new WorkdayConfigurationException(sprintf('The %s config for profile [%s] must be an array of time windows.', $section, $profile));
        }

        $normalized = [];

        foreach ($windows as $index => $window) {
            if (! is_array($window) || array_values($window) !== $window || count($window) !== 2) {
                throw new WorkdayConfigurationException(sprintf(
                    'The %s window [%s] for profile [%s] must be an array like ["09:00", "17:00"].',
                    $section,
                    self::describe($index),
                    $profile,
                ));
            }

            [$start, $end] = $window;

            if (! is_string($start) || ! self::isValidTime($start) || ($strict && strlen($start) !== 5)) {
                throw new WorkdayConfigurationException(sprintf(
                    'Invalid %s start time [%s] for profile [%s]. Expected HH:MM in 24-hour format.',
                    $section,
                    self::describe($start),
                    $profile,
                ));
            }

            if (! is_string($end) || (! self::isValidTime($end) && ! ($allowOvernight && $end === '24:00')) || ($strict && strlen($end) !== 5)) {
                throw new WorkdayConfigurationException(sprintf(
                    'Invalid %s end time [%s] for profile [%s]. Expected HH:MM in 24-hour format.',
                    $section,
                    self::describe($end),
                    $profile,
                ));
            }

            $timeWindow = new TimeWindow($start, $end);

            if ($timeWindow->endMinutes() === $timeWindow->startMinutes()
                || (! $allowOvernight && $timeWindow->endMinutes() < $timeWindow->startMinutes())) {
                throw new WorkdayConfigurationException(sprintf(
                    'The %s window [%s] for profile [%s] must end after it starts.',
                    $section,
                    self::describe($index),
                    $profile,
                ));
            }

            $normalized[] = $timeWindow;
        }

        usort(
            $normalized,
            static fn (TimeWindow $left, TimeWindow $right): int => $left->startMinutes() <=> $right->startMinutes(),
        );

        foreach ($normalized as $index => $window) {
            $previous = $normalized[$index - 1] ?? null;

            $previousEnd = $previous?->endMinutes();

            if ($previous instanceof TimeWindow && $allowOvernight && $previousEnd < $previous->startMinutes()) {
                $previousEnd += 1440;
            }

            if ($previous instanceof TimeWindow && $window->startMinutes() < $previousEnd) {
                throw new WorkdayConfigurationException(sprintf(
                    'The %s windows for profile [%s] must not overlap.',
                    $section,
                    $profile,
                ));
            }
        }

        return $normalized;
    }

    private static function isValidTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }

    private static function describe(mixed $value): string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}
