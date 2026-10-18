<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

/**
 * Validated per call, never consulted by legacy day/time APIs.
 */
final readonly class CutoffPolicy
{
    /**
     * @param  array<int, string|null>  $weekdays
     */
    private function __construct(
        private string $profile,
        public string $timezone,
        private string $time,
        private array $weekdays,
    ) {}

    /**
     * @param  array<string, mixed>  $profileConfig
     */
    public static function fromProfileConfig(string $profile, array $profileConfig): self
    {
        if (! array_key_exists('cutoff', $profileConfig)) {
            throw new WorkdayConfigurationException(sprintf('The cut-off policy for profile [%s] is not configured. Add a cutoff block with timezone and time.', $profile));
        }

        $config = $profileConfig['cutoff'];

        if (! is_array($config)) {
            throw new WorkdayConfigurationException(sprintf('The cutoff config for profile [%s] must be an array.', $profile));
        }

        $timezone = $config['timezone'] ?? null;

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new WorkdayConfigurationException(sprintf('The cutoff.timezone for profile [%s] is required and must be a valid timezone identifier.', $profile));
        }

        $time = self::validateTime($profile, 'time', $config['time'] ?? null);
        $weekdays = array_key_exists('weekdays', $config) ? $config['weekdays'] : [];

        if (! is_array($weekdays)) {
            throw new WorkdayConfigurationException(sprintf('The cutoff.weekdays for profile [%s] must be an array.', $profile));
        }

        $normalizedWeekdays = [];

        foreach ($weekdays as $weekday => $override) {
            try {
                $isoWeekday = WeekdayNormalizer::toIso($weekday);
            } catch (InvalidArgumentException $exception) {
                throw new WorkdayConfigurationException(sprintf('Invalid cutoff.weekdays key [%s] for profile [%s]. Expected an ISO weekday or supported weekday name.', $weekday, $profile), previous: $exception);
            }

            if (array_key_exists($isoWeekday, $normalizedWeekdays)) {
                throw new WorkdayConfigurationException(sprintf('Duplicate normalized cutoff weekday [%d] for profile [%s].', $isoWeekday, $profile));
            }

            $normalizedWeekdays[$isoWeekday] = $override === null
                ? null
                : self::validateTime($profile, 'weekdays.'.$weekday, $override);
        }

        return new self($profile, $timezone, $time, $normalizedWeekdays);
    }

    public function cutoffAt(CarbonImmutable $date): ?CarbonImmutable
    {
        $weekday = $date->isoWeekday();
        $time = array_key_exists($weekday, $this->weekdays) ? $this->weekdays[$weekday] : $this->time;

        if ($time === null) {
            return null;
        }

        $wallTime = $date->toDateString().' '.$time.':00.000000';
        $cutoff = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $wallTime, new DateTimeZone($this->timezone));

        if ($cutoff === false || $cutoff->format('Y-m-d H:i:s.u') !== $wallTime) {
            throw new WorkdayConfigurationException(sprintf(
                'The cutoff wall time [%s] does not exist in timezone [%s] for profile [%s]. Choose an existing local cut-off time.',
                $wallTime,
                $this->timezone,
                $this->profile,
            ));
        }

        return CarbonImmutable::instance($cutoff);
    }

    private static function validateTime(string $profile, string $key, mixed $time): string
    {
        if (! is_string($time) || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time) !== 1) {
            throw new WorkdayConfigurationException(sprintf('The cutoff.%s for profile [%s] is required and must be a strict 24-hour HH:MM string.', $key, $profile));
        }

        return $time;
    }
}
