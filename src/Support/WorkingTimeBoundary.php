<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

/** Strict local wall-clock boundaries shared by every working-time consumer. */
final class WorkingTimeBoundary
{
    public static function labelAfter(string $label, int $days): string
    {
        self::validateLabel($label);
        if ($days < -3652058 || $days > 3652058) {
            throw new InvalidArgumentException('Unsupported working-time date range; supported civil years are 0001 through 9999.');
        }
        $next = CarbonImmutable::createFromFormat('!Y-m-d', $label, 'UTC')->addDays($days)->toDateString();
        self::validateLabel($next);

        return $next;
    }

    public static function date(string $label, DateTimeZone $zone): ?CarbonImmutable
    {
        self::validateLabel($label);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $label, $zone);

        return $date !== false && $date->format('Y-m-d') === $label ? CarbonImmutable::instance($date) : null;
    }

    /** A split civil date cannot be represented by one ordered list of daily slices. */
    public static function assertRepresentableDate(string $profile, string $label, DateTimeZone $zone): void
    {
        self::validateLabel($label);
        $base = CarbonImmutable::createFromFormat('!Y-m-d', $label, 'UTC')->getTimestamp();
        $transitions = $zone->getTransitions($base - 172800, $base + 259200) ?: [];

        foreach (array_slice($transitions, 1) as $transition) {
            $after = CarbonImmutable::createFromTimestamp($transition['ts'], 'UTC')->setTimezone($zone);
            $before = $after->subSecond();
            $first = $after->toDateString();
            $last = $before->toDateString();

            if ($first < $last && $label >= $first && $label <= $last) {
                throw new WorkdayConfigurationException(sprintf(
                    'Working-time date [%s] for profile [%s] cannot be represented because timezone [%s] moves backward across a civil-date label.',
                    $label, $profile, $zone->getName(),
                ));
            }
        }
    }

    public static function at(string $profile, string $label, int $minute, DateTimeZone $zone, bool $dayBoundary = false): CarbonImmutable
    {
        [$boundary, $failure] = self::resolve($label, $minute, $zone, $dayBoundary);
        if ($boundary === null) {
            throw new WorkdayConfigurationException(sprintf(
                'Working-hours boundary [%02d:%02d] on [%s] for profile [%s] %s in timezone [%s].',
                intdiv($minute, 60), $minute % 60, $label, $profile, $failure, $zone->getName(),
            ));
        }

        return $boundary;
    }

    /** Generated slot-grid candidates may be absent; configured endpoints must use at(). */
    public static function candidateAt(string $profile, string $label, int $minute, DateTimeZone $zone, bool $dayBoundary = false): ?CarbonImmutable
    {
        return self::resolve($label, $minute, $zone, $dayBoundary)[0];
    }

    /** @return array{?CarbonImmutable, string} */
    private static function resolve(string $label, int $minute, DateTimeZone $zone, bool $dayBoundary): array
    {
        self::validateLabel($label);
        if ($minute < 0 || $minute > 1440) {
            throw new InvalidArgumentException('Working-hours boundary minutes must be between 0 and 1440.');
        }

        if ($minute === 1440) {
            $label = self::labelAfter($label, 1);
            $minute = 0;
        }

        $wall = sprintf('%s %02d:%02d:00.000000', $label, intdiv($minute, 60), $minute % 60);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $wall, $zone);

        if ($parsed === false || ($parsed->format('Y-m-d H:i:s.u') !== $wall
            && ! ($dayBoundary && $minute === 0 && $parsed->format('Y-m-d') === $label))) {
            return [null, 'does not exist'];
        }

        $local = CarbonImmutable::instance($parsed);
        WorkingTime::micros($local);
        $transitions = $zone->getTransitions($local->getTimestamp() - 172800, $local->getTimestamp() + 172800) ?: [];
        $wallTimestamp = $local->getTimestamp() + $local->getOffset();

        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $alternative = CarbonImmutable::createFromTimestamp($wallTimestamp - $offset, 'UTC')->setTimezone($zone);

            if ($alternative->getTimestamp() !== $local->getTimestamp()
                && $alternative->format('Y-m-d H:i:s.u') === $local->format('Y-m-d H:i:s.u')) {
                return [null, 'is ambiguous'];
            }
        }

        return [$local, ''];
    }

    private static function validateLabel(string $label): void
    {
        if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $label)
            || ! checkdate((int) substr($label, 5, 2), (int) substr($label, 8, 2), (int) substr($label, 0, 4))) {
            throw new InvalidArgumentException('Unsupported working-time civil date ['.$label.']; supported years are 0001 through 9999.');
        }
    }
}
