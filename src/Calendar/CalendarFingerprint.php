<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Calendar;

use InvalidArgumentException;
use JsonException;

/** Bounded JSON canonicalization; a content checksum, never an authentication signature. */
final class CalendarFingerprint
{
    public const MAX_BYTES = 2097152;

    public const MAX_DEPTH = 32;

    public const MAX_NODES = 100000;

    public static function hash(array $evidence): string
    {
        return hash('sha256', "laravel-workdays/calendar-evidence/v1\n".self::canonical($evidence));
    }

    /** The caller supplies the payload WITHOUT integrity_hash. */
    public static function integrity(array $payload): string
    {
        return hash('sha256', "laravel-workdays/delivery-promise/v1\n".self::canonical($payload));
    }

    public static function assertJson(array $value): void
    {
        self::canonical($value);
    }

    /** @internal Incremental lower-bound budget for materialization, before allocating JSON. */
    public static function assertBudget(array $value, int &$nodes, int &$bytes): void
    {
        self::normalize($value, 0, $nodes, $bytes);
    }

    public static function canonical(array $value): string
    {
        return self::encode($value, self::MAX_BYTES, self::MAX_NODES);
    }

    /** @internal Two valid evidence views plus bounded diagnostic field lists. */
    public static function comparisonJson(array $value): string
    {
        return self::encode($value, 5242880, 210000);
    }

    private static function encode(array $value, int $maxBytes, int $maxNodes): string
    {
        $nodes = 0;
        $bytes = 0;
        $normalized = self::normalize($value, 0, $nodes, $bytes, $maxBytes, $maxNodes);

        try {
            $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION, self::MAX_DEPTH + 2);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid snapshot JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException($maxBytes === self::MAX_BYTES ? 'Snapshot payload exceeds the 2 MiB size limit.' : 'Comparison diagnostics exceed the 5 MiB size limit.');
        }

        return $json;
    }

    private static function normalize(mixed $value, int $depth, int &$nodes, int &$bytes, int $maxBytes = self::MAX_BYTES, int $maxNodes = self::MAX_NODES): mixed
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > $maxNodes) {
            throw new InvalidArgumentException('Snapshot JSON exceeds the depth or node limit.');
        }

        if (is_string($value)) {
            $bytes += strlen($value);
        }

        if ($bytes > $maxBytes) {
            throw new InvalidArgumentException($maxBytes === self::MAX_BYTES ? 'Snapshot payload exceeds the 2 MiB size limit.' : 'Comparison diagnostics exceed the 5 MiB size limit.');
        }

        if (is_array($value)) {
            $list = array_is_list($value);
            $normalized = [];

            foreach ($value as $key => $item) {
                // Reject shared large-string expansions before allocating their encoded JSON.
                $bytes += $list ? 0 : strlen((string) $key);
                $normalized[$key] = self::normalize($item, $depth + 1, $nodes, $bytes, $maxBytes, $maxNodes);
            }

            if (! $list) {
                ksort($normalized, SORT_STRING);

                return (object) $normalized;
            }

            return $normalized;
        }

        if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)
            && (! is_float($value) || ! is_finite($value))) {
            throw new InvalidArgumentException('Snapshot values must be JSON scalars or arrays; objects, resources and non-finite numbers are unsupported.');
        }

        return $value;
    }
}
