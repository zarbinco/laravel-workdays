<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

/** Strict local boundaries for the opt-in working-hours path. */
final class WorkingTimeBoundary
{
    public static function labelAfter(string $label, int $days): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $label, 'UTC')->addDays($days)->toDateString();
    }

    public static function date(string $label, DateTimeZone $zone): ?CarbonImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $label, $zone);

        return $date !== false && $date->format('Y-m-d') === $label ? CarbonImmutable::instance($date) : null;
    }

    public static function at(string $profile, string $label, int $minute, DateTimeZone $zone, bool $dayBoundary = false): CarbonImmutable
    {
        $boundary = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        $context = sprintf('Working-hours boundary [%s] on [%s] for profile [%s]', $boundary, $label, $profile);

        if ($minute === 1440) {
            $label = self::labelAfter($label, 1);
            $minute = 0;
        }

        $wall = sprintf('%s %02d:%02d:00.000000', $label, intdiv($minute, 60), $minute % 60);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $wall, $zone);

        if ($parsed === false || ($parsed->format('Y-m-d H:i:s.u') !== $wall
            && ! ($dayBoundary && $minute === 0 && $parsed->format('Y-m-d') === $label))) {
            throw new WorkdayConfigurationException($context.' does not exist in timezone ['.$zone->getName().'].');
        }

        $local = CarbonImmutable::instance($parsed);
        $transitions = $zone->getTransitions($local->getTimestamp() - 172800, $local->getTimestamp() + 172800) ?: [];
        $wallTimestamp = $local->getTimestamp() + $local->getOffset();

        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $alternative = CarbonImmutable::createFromTimestamp($wallTimestamp - $offset, 'UTC')->setTimezone($zone);

            if ($alternative->getTimestamp() !== $local->getTimestamp()
                && $alternative->format('Y-m-d H:i:s.u') === $local->format('Y-m-d H:i:s.u')) {
                throw new WorkdayConfigurationException($context.' is ambiguous in timezone ['.$zone->getName().'].');
            }
        }

        return $local;
    }
}
