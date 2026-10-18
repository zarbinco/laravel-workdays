<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Strict absolute timestamps for the opt-in cut-off API only.
 */
final class CutoffDateTimeNormalizer
{
    public static function toImmutable(string|DateTimeInterface $receivedAt, string $timezone): CarbonImmutable
    {
        if ($receivedAt instanceof DateTimeInterface) {
            return CarbonImmutable::instance($receivedAt)->setTimezone($timezone);
        }

        $pattern = '/\A(?<date>[0-9]{4}-[0-9]{2}-[0-9]{2})(?<separator>[ T])(?<hour>[0-9]{2}):(?<minute>[0-9]{2})(?::(?<second>[0-9]{2})(?:\.(?<microsecond>[0-9]{1,6}))?)?(?<offset>Z|[+-][0-9]{2}:[0-9]{2})?\z/';

        if (preg_match($pattern, $receivedAt, $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            self::throwInvalidTimestamp();
        }

        $offset = $parts['offset'];

        if (($parts['separator'] === 'T') !== ($offset !== null)) {
            self::throwInvalidTimestamp();
        }

        [$year, $month, $day] = array_map('intval', explode('-', $parts['date']));

        if (! checkdate($month, $day, $year)
            || (int) $parts['hour'] > 23
            || (int) $parts['minute'] > 59
            || (int) ($parts['second'] ?? '00') > 59) {
            self::throwInvalidTimestamp();
        }

        if ($offset !== null && $offset !== 'Z'
            && ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 4, 2) > 59)) {
            self::throwInvalidTimestamp();
        }

        $wallTime = $parts['date'].' '.$parts['hour'].':'.$parts['minute'].':'.($parts['second'] ?? '00')
            .'.'.str_pad($parts['microsecond'] ?? '', 6, '0');

        $parsed = $offset === null
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $wallTime, new DateTimeZone($timezone))
            : DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $wallTime.($offset === 'Z' ? '+00:00' : $offset));

        // PHP normally adjusts impossible local times during a DST gap. Reject that adjustment.
        if ($parsed === false || $parsed->format('Y-m-d H:i:s.u') !== $wallTime) {
            self::throwInvalidTimestamp();
        }

        return CarbonImmutable::instance($parsed)->setTimezone($timezone);
    }

    private static function throwInvalidTimestamp(): never
    {
        throw new InvalidArgumentException(
            'Invalid cut-off receivedAt. Supply an absolute Gregorian Y-m-d H:i[:s[.microseconds]] timestamp in the policy timezone, an ISO 8601 datetime with Z or an explicit ±HH:MM offset, or a DateTimeInterface instance.',
        );
    }
}
