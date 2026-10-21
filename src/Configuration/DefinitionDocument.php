<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

use InvalidArgumentException;
use JsonException;
use Zarbinco\LaravelWorkdays\Support\CutoffPolicy;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Support\ProfileRouter;

/** A validated complete raw release. Its transfer budget includes the versioned JSON wrapper. */
final readonly class DefinitionDocument
{
    public const FORMAT_VERSION = 1;

    public const MAX_BYTES = 4194304;

    public const MAX_DEPTH = 32;

    public const MAX_NODES = 100000;

    public const MAX_PROFILES = 10000;

    private function __construct(public array $definitions, public string $contentHash) {}

    public static function fromArray(array $definitions): self
    {
        $definitions = self::data($definitions);
        if (array_diff(array_keys($definitions), ['default_profile', 'include_start_date', 'hijri', 'profile_routing', 'profiles']) !== []
            || ! is_array($definitions['profiles'] ?? null) || $definitions['profiles'] === []
            || count($definitions['profiles']) > self::MAX_PROFILES) {
            throw new InvalidArgumentException('Definitions require supported shared settings and 1–10000 raw profiles.');
        }
        $profiles = $definitions['profiles'];
        foreach ($profiles as $name => $profile) {
            self::profileName((string) $name);
            self::rawProfile((string) $name, $profile);
        }
        $settings = $definitions;
        unset($settings['profiles']);
        $view = new WorkdayDefinitions($settings,
            static fn (string $name): array => $profiles[$name],
            static fn (string $name): bool => array_key_exists($name, $profiles));
        if (! $view->hasProfile($view->defaultProfile)) {
            throw new InvalidArgumentException('definitions.default_profile must reference a published profile.');
        }
        $resolver = new ProfileConfigResolver($view->profile(...));
        foreach (array_keys($profiles) as $name) {
            $name = (string) $name;
            $effective = $resolver->resolve($name);
            if (array_key_exists('cutoff', $effective)) {
                CutoffPolicy::fromProfileConfig($name, $effective);
            }
        }
        if (($definitions['profile_routing'] ?? null) !== null) {
            (new ProfileRouter($view->hasProfile(...)))->resolve([], $view->routing());
        }
        $document = new self($definitions, self::hash($definitions, 'release'));
        $document->toJson(); // Every publishable document must fit its own export/import policy.

        return $document;
    }

    public static function fromJson(string $json): self
    {
        $payload = self::decode($json);
        if (! is_array($payload) || count($payload) !== 2
            || array_diff(array_keys($payload), ['format_version', 'definitions']) !== []
            || ($payload['format_version'] ?? null) !== self::FORMAT_VERSION || ! is_array($payload['definitions'] ?? null)) {
            throw new InvalidArgumentException('Definition export requires format_version 1 and a raw definitions document only.');
        }

        return self::fromArray($payload['definitions']);
    }

    public function toJson(): string
    {
        $json = self::encode(self::data(['format_version' => self::FORMAT_VERSION, 'definitions' => $this->definitions]));
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Definition export exceeds the 4 MiB size limit.');
        }

        return $json;
    }

    /** @internal Immutable row checksums use a separate domain from promise fingerprints. */
    public static function hash(array $value, string $domain): string
    {
        return hash('sha256', 'laravel-workdays/definitions/v1/'.$domain."\n".self::encode(self::canonical($value)));
    }

    /** @internal Strict JSON for trusted persisted rows and versioned transfer documents. */
    public static function decode(string $json): mixed
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Definition JSON exceeds the 4 MiB size limit.');
        }
        try {
            $decoded = json_decode($json, true, self::MAX_DEPTH + 5, JSON_THROW_ON_ERROR);
            // json_decode silently accepts repeated object members; definitions do not.
            $stack = [];
            $length = strlen($json);
            for ($offset = 0; $offset < $length; $offset++) {
                $token = $json[$offset];
                if ($token === '"') {
                    $start = $offset;
                    // Syntax/escape validity was checked by json_decode. Scan strings
                    // linearly, without PCRE backtracking/JIT limits on large values.
                    while (++$offset < $length) {
                        if ($json[$offset] === '\\') {
                            $offset++;
                        } elseif ($json[$offset] === '"') {
                            break;
                        }
                    }
                    if ($stack !== []) {
                        $index = count($stack) - 1;
                        if ($stack[$index]['object'] && $stack[$index]['key']) {
                            $key = 'k:'.json_decode(substr($json, $start, $offset - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                            if (isset($stack[$index]['seen'][$key])) {
                                throw new InvalidArgumentException('Duplicate JSON object member in definition data.');
                            }
                            $stack[$index]['seen'][$key] = true;
                        }
                    }

                    continue;
                }
                if ($token === '{' || $token === '[') {
                    $stack[] = ['object' => $token === '{', 'key' => $token === '{', 'seen' => []];
                } elseif ($token === '}' || $token === ']') {
                    array_pop($stack);
                } elseif ($stack !== []) {
                    $index = count($stack) - 1;
                    if ($token === ',') {
                        $stack[$index]['key'] = $stack[$index]['object'];
                    } elseif ($token === ':') {
                        $stack[$index]['key'] = false;
                    }
                }
            }

            return self::data($decoded);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Invalid definition JSON: '.$error->getMessage(), previous: $error);
        }
    }

    /** @internal Exact raw ownership is stored, without canonical map reordering. */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, self::MAX_DEPTH + 5);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Definition data is not valid JSON: '.$error->getMessage(), previous: $error);
        }
    }

    /** @internal Bounded detached native JSON data; metadata may use a smaller envelope. */
    public static function data(mixed $input): mixed
    {
        $nodes = 0;
        $bytes = 0;
        $copy = function (mixed $value, int $depth) use (&$copy, &$nodes, &$bytes): mixed {
            if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
                throw new InvalidArgumentException('Definition data exceeds depth 32 or 100000 nodes.');
            }
            $bytes += is_string($value) ? strlen($value) : 1;
            if ($bytes > self::MAX_BYTES) {
                throw new InvalidArgumentException('Definition data exceeds the 4 MiB size limit.');
            }
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $item) {
                    $bytes += strlen((string) $key);
                    $result[$key] = $copy($item, $depth + 1);
                }

                return $result;
            }
            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)
                && (! is_float($value) || ! is_finite($value))) {
                throw new InvalidArgumentException('Definitions accept finite JSON scalars and arrays only; objects, resources and callbacks are forbidden.');
            }
            if (is_string($value) && preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('Definition strings must be valid UTF-8.');
            }

            return $value;
        };

        return $copy($input, 0);
    }

    public static function profileName(string $name): void
    {
        if (trim($name) === '' || strlen($name) > 255 || preg_match('//u', $name) !== 1 || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Profile names must be nonblank valid UTF-8, at most 255 bytes, without NUL.');
        }
    }

    /** @internal Raw partial children are checked structurally, then validated after inheritance. */
    public static function rawProfile(string $name, mixed $profile): void
    {
        $allowed = ['extends', 'weekends', 'holidays', 'custom_holidays', 'extra_working_days', 'working_hours', 'extra_working_day_hours', 'special_working_hours', 'seasonal_working_hours', 'allow_overnight_working_hours', 'cutoff'];
        if (! is_array($profile) || array_diff(array_keys($profile), $allowed) !== []) {
            throw new InvalidArgumentException('Profile ['.$name.'] contains unsupported fields or is not an array.');
        }
        if (isset($profile['cutoff']) && is_array($profile['cutoff']) && array_diff(array_keys($profile['cutoff']), ['timezone', 'time', 'weekdays']) !== []) {
            throw new InvalidArgumentException('Profile ['.$name.'] cutoff contains unsupported fields.');
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }
        if (! $list) {
            ksort($value, SORT_STRING);

            return (object) $value;
        }

        return $value;
    }
}
