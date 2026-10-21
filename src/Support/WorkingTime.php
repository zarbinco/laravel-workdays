<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkingTimeSearchException;

/** Exact elapsed interval arithmetic; the callback owns effective schedule selection. */
final readonly class WorkingTime
{
    public const MINUTE = 60000000;

    /** @param Closure(CarbonImmutable): list<array{CarbonImmutable, CarbonImmutable}> $intervals */
    public function __construct(private string $profile, private int $maxScanDays, private Closure $intervals) {}

    public static function micros(CarbonImmutable $instant): int
    {
        $seconds = $instant->getTimestamp();
        if ($seconds < -62135596800 || $seconds > 253402300799) {
            throw new InvalidArgumentException('Unsupported working-time instant; supported UTC years are 0001 through 9999.');
        }

        return $seconds * 1000000 + (int) $instant->format('u');
    }

    public static function fromMicros(int $micros, DateTimeZone|string $zone = 'UTC'): CarbonImmutable
    {
        $seconds = intdiv($micros, 1000000);
        $fraction = $micros % 1000000;
        if ($fraction < 0) {
            $seconds--;
            $fraction += 1000000;
        }
        if ($seconds < -62135596800 || $seconds > 253402300799) {
            throw new InvalidArgumentException('Unsupported working-time instant; supported UTC years are 0001 through 9999.');
        }

        return CarbonImmutable::createFromTimestamp($seconds, 'UTC')->setMicrosecond($fraction)->setTimezone($zone);
    }

    public static function elapsed(CarbonImmutable|int $start, CarbonImmutable|int $end): int
    {
        $a = $start instanceof CarbonImmutable ? self::micros($start) : $start;
        $b = $end instanceof CarbonImmutable ? self::micros($end) : $end;
        if (min($a, $b) < -62135596800000000 || max($a, $b) > 253402300799999999) {
            throw new InvalidArgumentException('Unsupported working-time microsecond range.');
        }

        return $b - $a;
    }

    public static function minutes(int $minutes): int
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException('Business minutes must be zero or greater.');
        }
        if ($minutes > intdiv(PHP_INT_MAX, self::MINUTE)) {
            throw new InvalidArgumentException('Business minutes must be non-negative and safely representable as integer microseconds.');
        }

        return $minutes * self::MINUTE;
    }

    public function seek(CarbonImmutable $instant, int $direction): CarbonImmutable
    {
        $point = self::micros($instant);
        $firstLabel = $instant->toDateString();
        $budget = $this->dateBudget($firstLabel, $direction);
        for ($offset = 0; $offset <= $budget; $offset++) {
            $date = WorkingTimeBoundary::date(WorkingTimeBoundary::labelAfter($firstLabel, $offset * $direction), $instant->getTimezone());
            if ($date === null) {
                continue;
            }
            $intervals = ($this->intervals)($date);
            if ($direction < 0) {
                $intervals = array_reverse($intervals);
            }
            foreach ($intervals as [$start, $end]) {
                $opening = self::micros($start);
                $closing = self::micros($end);
                if ($point >= $opening && $point < $closing) {
                    return $instant;
                }
                if ($direction > 0 && $point < $opening) {
                    return $start->setTimezone($instant->getTimezone());
                }
                if ($direction < 0 && $point >= $closing) {
                    return self::fromMicros($closing - 1, $instant->getTimezone());
                }
            }
        }
        $this->unavailable();
    }

    public function consume(CarbonImmutable $instant, int $minutes, ?CarbonImmutable $completionEnd = null): CarbonImmutable
    {
        $remaining = self::minutes($minutes);
        $point = self::micros($instant);
        if ($remaining === 0) {
            return $instant;
        }
        $firstLabel = $instant->toDateString();
        $budget = $this->dateBudget($firstLabel, 1);
        $limit = $completionEnd === null ? null : self::micros($completionEnd);
        for ($offset = 0; $offset <= $budget; $offset++) {
            $label = WorkingTimeBoundary::labelAfter($firstLabel, $offset);
            $date = WorkingTimeBoundary::date($label, $instant->getTimezone());
            if ($date === null) {
                continue;
            }
            if ($limit !== null && self::micros($date) >= $limit) {
                break;
            }
            foreach (($this->intervals)($date) as [$start, $end]) {
                $opening = max($point, self::micros($start));
                $closing = self::micros($end);
                if ($limit !== null) {
                    $closing = min($closing, $limit);
                }
                if ($closing <= $opening) {
                    continue;
                }
                $available = $closing - $opening;
                if ($remaining <= $available) {
                    return self::fromMicros($opening + $remaining, $instant->getTimezone());
                }
                $remaining -= $available;
                $point = $closing;
            }
        }
        $this->unavailable();
    }

    /** The evaluation timezone has already been fixed from the original first argument. */
    public function measure(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $a = self::micros($start);
        $b = self::micros($end);
        if ($a === $b) {
            return 0;
        }
        $sign = $a < $b ? 1 : -1;
        if ($sign < 0) {
            [$start, $end, $a, $b] = [$end, $start, $b, $a];
        }
        $firstLabel = $start->toDateString();
        $budget = $this->dateBudget($firstLabel, 1);
        $lastLabel = WorkingTimeBoundary::labelAfter($firstLabel, $budget);
        $endLabel = $end->toDateString();
        if ($endLabel < $firstLabel) {
            throw new WorkdayConfigurationException(sprintf(
                'Working-time range [%s, %s] for profile [%s] cannot be represented because timezone [%s] moves backward across a civil-date label.',
                $firstLabel, $endLabel, $this->profile, $start->getTimezone()->getName(),
            ));
        }
        if ($endLabel > $lastLabel) {
            $exclusiveLabel = WorkingTimeBoundary::labelAfter($lastLabel, 1);
            $exclusive = WorkingTimeBoundary::date($exclusiveLabel, $start->getTimezone());
            if ($endLabel !== $exclusiveLabel || $exclusive === null || ! $exclusive->equalTo($end)) {
                $this->unavailable();
            }
        }
        $total = 0;
        for ($offset = 0; $offset <= $budget; $offset++) {
            $label = WorkingTimeBoundary::labelAfter($firstLabel, $offset);
            if ($label > $endLabel) {
                break;
            }
            $date = WorkingTimeBoundary::date($label, $start->getTimezone());
            if ($date === null) {
                continue;
            }
            if (self::micros($date) >= $b) {
                break;
            }
            foreach (($this->intervals)($date) as [$opening, $closing]) {
                $overlap = min($b, self::micros($closing)) - max($a, self::micros($opening));
                if ($overlap > 0) {
                    if ($overlap > PHP_INT_MAX - $total) {
                        throw new InvalidArgumentException('Working-time overlap exceeds the supported integer range.');
                    }
                    $total += $overlap;
                }
            }
        }

        return $sign * $total;
    }

    /** Internal representational margin never enlarges the caller's date-label budget. */
    private function dateBudget(string $firstLabel, int $direction): int
    {
        $origin = new DateTimeImmutable($firstLabel.'T00:00:00Z');
        $edge = new DateTimeImmutable($direction > 0 ? '9999-12-31T00:00:00Z' : '0001-01-01T00:00:00Z');

        return min($this->maxScanDays, (int) $origin->diff($edge)->format('%a'));
    }

    private function unavailable(): never
    {
        throw new WorkingTimeSearchException(sprintf(
            'Unable to resolve a business time within [%d] calendar days for profile [%s]. Check working_hours, holidays, extra working days, and max_scan_days config.',
            $this->maxScanDays, $this->profile,
        ));
    }
}
