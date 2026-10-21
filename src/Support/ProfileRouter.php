<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Closure;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Data\ResolvedProfile;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

/** Selects configured names only; never constructs a calculator or holiday provider. */
final readonly class ProfileRouter
{
    private Closure $profileExists;

    /** @param callable(string): bool $profileExists Does not enumerate or hydrate a registry. */
    public function __construct(callable $profileExists)
    {
        $this->profileExists = Closure::fromCallable($profileExists);
    }

    /** @param array<array-key, mixed> $context */
    public function resolve(array $context, mixed $routing): ResolvedProfile
    {
        $this->validateMap($context, 'Profile routing context', false);

        if (! is_array($routing) || array_diff(array_keys($routing), ['fallback_profile', 'rules']) !== []
            || ! array_key_exists('fallback_profile', $routing) || ! array_key_exists('rules', $routing)) {
            throw new WorkdayConfigurationException('The workdays.profile_routing configuration requires only fallback_profile and rules. Configure routing explicitly before calling resolveProfile() or profileFor().');
        }

        $this->validateTarget($routing['fallback_profile'], 'Profile routing fallback_profile');
        $rules = $routing['rules'];

        if (! is_array($rules) || ! array_is_list($rules)) {
            throw new WorkdayConfigurationException('Profile routing rules must be an ordered list.');
        }

        $ids = [];

        foreach ($rules as $index => $rule) {
            $label = sprintf('Profile routing rule [%d]', $index);

            if (! is_array($rule) || count($rule) !== 3 || array_diff(array_keys($rule), ['id', 'match', 'profile']) !== []
                || ! array_key_exists('id', $rule) || ! array_key_exists('match', $rule) || ! array_key_exists('profile', $rule)) {
                throw new WorkdayConfigurationException($label.' requires exactly id, match and profile.');
            }

            if (! is_string($rule['id']) || trim($rule['id']) === '' || array_key_exists($rule['id'], $ids)) {
                throw new WorkdayConfigurationException($label.' id must be a unique non-empty string.');
            }

            $ids[$rule['id']] = true;
            $this->validateMap($rule['match'], $label.' match', true);
            $this->validateTarget($rule['profile'], $label.' profile');
        }

        $selected = new ResolvedProfile($routing['fallback_profile'], 'fallback', null);
        $specificity = 0;

        foreach ($rules as $rule) {
            foreach ($rule['match'] as $key => $value) {
                if (! array_key_exists($key, $context) || $context[$key] !== $value) {
                    continue 2;
                }
            }

            // A strict increase leaves the first configured rule in place on ties.
            if (count($rule['match']) > $specificity) {
                $selected = new ResolvedProfile($rule['profile'], 'rule', $rule['id']);
                $specificity = count($rule['match']);
            }
        }

        return $selected;
    }

    private function validateTarget(mixed $profile, string $label): void
    {
        if (! is_string($profile) || trim($profile) === '' || ! ($this->profileExists)($profile)) {
            throw new WorkdayConfigurationException($label.' must reference an existing configured profile name.');
        }
    }

    private function validateMap(mixed $map, string $label, bool $configuration): void
    {
        $error = null;

        if (! is_array($map) || ($configuration && $map === [])) {
            $error = $label.' must be '.($configuration ? 'a non-empty' : 'an').' associative identifier/value map.';
        } else {
            foreach ($map as $key => $value) {
                if (! is_string($key) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
                    $error = $label.' keys must be identifier strings matching [A-Za-z_][A-Za-z0-9_]*.';

                    break;
                }

                if (! is_int($value) && (! is_string($value) || $value === '')) {
                    $error = $label.' values must be non-empty strings or integers, without coercion.';

                    break;
                }
            }
        }

        if ($error !== null) {
            throw $configuration ? new WorkdayConfigurationException($error) : new InvalidArgumentException($error);
        }
    }
}
