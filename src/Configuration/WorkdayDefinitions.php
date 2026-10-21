<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

use Closure;
use InvalidArgumentException;
use Throwable;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

/** Source-neutral operational settings and lazy raw named profiles, without bootstrap settings. */
final class WorkdayDefinitions
{
    private Closure $readProfile;

    private Closure $profileExists;

    private array $raw = [];

    private array $existence = [];

    private mixed $routing;

    private ?InvalidArgumentException $routingError = null;

    public readonly string $defaultProfile;

    public readonly bool $includeStartDate;

    public readonly array $hijri;

    /** @param callable(string): mixed $readProfile @param callable(string): bool $profileExists */
    public function __construct(array $settings, callable $readProfile, callable $profileExists, public readonly string $source = 'config', public readonly ?int $revisionId = null)
    {
        if (! in_array($source, ['config', 'database'], true) || ($revisionId !== null && $revisionId < 1)) {
            throw new DefinitionSourceException('Definition source metadata is invalid.');
        }
        if (array_diff(array_keys($settings), ['default_profile', 'include_start_date', 'hijri', 'profile_routing']) !== []) {
            throw new WorkdayConfigurationException('Operational definitions contain unsupported settings.');
        }
        if (! is_string($settings['default_profile'] ?? null) || trim($settings['default_profile']) === '') {
            throw new WorkdayConfigurationException('definitions.default_profile must be a non-empty profile name.');
        }
        $this->defaultProfile = $settings['default_profile'];
        if (! array_key_exists('include_start_date', $settings) || ! is_bool($settings['include_start_date'])) {
            throw new WorkdayConfigurationException('definitions.include_start_date is required and must be a native boolean.');
        }
        $this->includeStartDate = $settings['include_start_date'];
        $hijri = $settings['hijri'] ?? null;
        if (! is_array($hijri) || array_diff(array_keys($hijri), ['method', 'adjustment']) !== []
            || ! is_string($hijri['method'] ?? null) || ! is_int($hijri['adjustment'] ?? null)) {
            throw new WorkdayConfigurationException('definitions.hijri requires only a string method and native integer adjustment.');
        }
        new HijriCalendarAdapter($hijri['method'], $hijri['adjustment']);
        $this->hijri = ['method' => $hijri['method'], 'adjustment' => $hijri['adjustment']];
        try {
            $this->routing = self::detach($settings['profile_routing'] ?? null, 'definitions.profile_routing');
        } catch (InvalidArgumentException $error) {
            $this->routing = null;
            $this->routingError = $error;
        }
        $this->readProfile = Closure::fromCallable($readProfile);
        $this->profileExists = Closure::fromCallable($profileExists);
    }

    public function hasProfile(string $name): bool
    {
        if (array_key_exists($name, $this->existence)) {
            return $this->existence[$name];
        }
        try {
            $exists = ($this->profileExists)($name);
        } catch (DefinitionSourceException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DefinitionSourceException('Unable to read profile existence ['.$name.'] from the selected source.', previous: $error);
        }
        if (! is_bool($exists)) {
            throw new DefinitionSourceException('The selected source must return a boolean profile-existence result.');
        }

        return $this->existence[$name] = $exists;
    }

    public function profile(string $name): array
    {
        if (! $this->hasProfile($name)) {
            throw new WorkdayConfigurationException('Workdays profile ['.$name.'] is not configured.');
        }
        if (! array_key_exists($name, $this->raw)) {
            try {
                $profile = ($this->readProfile)($name);
            } catch (WorkdayConfigurationException|DefinitionSourceException $error) {
                throw $error;
            } catch (Throwable $error) {
                throw new DefinitionSourceException('Unable to read profile ['.$name.'] from the selected source.', previous: $error);
            }
            if (! is_array($profile)) {
                throw new WorkdayConfigurationException('Definition profile ['.$name.'] must be an array.');
            }
            $this->raw[$name] = self::detach($profile, 'Definition profile ['.$name.']');
        }

        return $this->raw[$name];
    }

    public function routing(): mixed
    {
        if ($this->routingError !== null) {
            throw $this->routingError;
        }

        return $this->routing;
    }

    /** Copy data without preserving references or retaining executable/mutable objects. */
    public static function detach(mixed $value, string $label): mixed
    {
        $nodes = 0;
        $copy = function (mixed $entry, int $depth) use (&$copy, &$nodes, $label): mixed {
            if ($depth > 32 || ++$nodes > 100000) {
                throw new WorkdayConfigurationException($label.' exceeds the definition depth/node budget.');
            }
            if (is_array($entry)) {
                $result = [];
                foreach ($entry as $key => $item) {
                    $result[$key] = $copy($item, $depth + 1);
                }

                return $result;
            }
            if ($entry !== null && ! is_scalar($entry)) {
                throw new WorkdayConfigurationException($label.' must contain only arrays and scalar/null data; objects, resources and callbacks are forbidden.');
            }

            return $entry;
        };

        return $copy($value, 0);
    }
}
