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

    /**
     * @return array<int, array<int, TimeWindow>>
     */
    private static function normalizeWorkingHours(string $profile, mixed $workingHours): array
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

            $windowsByWeekday[$isoWeekday] = self::normalizeWindows($profile, sprintf('working_hours.%s', self::describe($weekday)), $windows);
        }

        ksort($windowsByWeekday);

        return $windowsByWeekday;
    }

    /**
     * @return array<int, TimeWindow>
     */
    private static function normalizeWindows(string $profile, string $section, mixed $windows): array
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

            if (! is_string($start) || ! self::isValidTime($start)) {
                throw new WorkdayConfigurationException(sprintf(
                    'Invalid %s start time [%s] for profile [%s]. Expected HH:MM in 24-hour format.',
                    $section,
                    self::describe($start),
                    $profile,
                ));
            }

            if (! is_string($end) || ! self::isValidTime($end)) {
                throw new WorkdayConfigurationException(sprintf(
                    'Invalid %s end time [%s] for profile [%s]. Expected HH:MM in 24-hour format.',
                    $section,
                    self::describe($end),
                    $profile,
                ));
            }

            $timeWindow = new TimeWindow($start, $end);

            if ($timeWindow->endMinutes() <= $timeWindow->startMinutes()) {
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

            if ($previous instanceof TimeWindow && $window->startMinutes() < $previous->endMinutes()) {
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
