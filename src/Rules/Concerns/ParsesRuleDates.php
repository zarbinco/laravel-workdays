<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Rules\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Zarbinco\LaravelWorkdays\Support\DateNormalizer;

trait ParsesRuleDates
{
    private function parseDateValue(mixed $value): ?CarbonImmutable
    {
        $datetime = $this->parseDateTimeValue($value);

        return $datetime?->startOfDay();
    }

    private function parseDateTimeValue(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                return DateNormalizer::toImmutable($value);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        $strictDateTime = $this->parseStrictDateTime($value);

        if ($strictDateTime !== null || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $value) === 1) {
            return $strictDateTime;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseStrictDateTime(string $value): ?CarbonImmutable
    {
        $formats = [
            'Y-m-d H:i',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i',
            'Y-m-d\TH:i:s',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        return null;
    }

    private function messageText(string $message, string $attribute): string
    {
        return str_replace(':attribute', str_replace('_', ' ', $attribute), $message);
    }
}
