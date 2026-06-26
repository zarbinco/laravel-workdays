<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class DateNormalizer
{
    public static function toImmutable(string|DateTimeInterface $date): CarbonImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date)->startOfDay();
        }

        $date = trim($date);

        if ($date === '') {
            throw new InvalidArgumentException('Date cannot be empty. Expected a Gregorian date in Y-m-d format.');
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('Invalid date [%s]. Expected a Gregorian date in Y-m-d format.', $date));
        }

        return $parsed;
    }
}
