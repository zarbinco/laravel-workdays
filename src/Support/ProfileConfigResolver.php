<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Closure;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;

/** Resolves only one selected hierarchy; snapshots never outlive this operation. */
final readonly class ProfileConfigResolver
{
    private Closure $readProfile;

    /** @param callable(string): array $readProfile Raw layers, loaded only along the selected ancestry. */
    public function __construct(callable $readProfile)
    {
        $this->readProfile = Closure::fromCallable($readProfile);
    }

    /** @return array<string, mixed> */
    public function resolve(string $profile): array
    {
        return $this->resolveChain($profile, []);
    }

    /**
     * @param  list<string>  $ancestors
     * @return array<string, mixed>
     */
    private function resolveChain(string $profile, array $ancestors): array
    {
        $chain = [...$ancestors, $profile];
        $context = 'Profile inheritance ['.implode(' -> ', $chain).']';

        if (in_array($profile, $ancestors, true)) {
            throw new WorkdayConfigurationException($context.' contains a cycle.');
        }

        if (count($chain) > 16) {
            throw new WorkdayConfigurationException($context.' exceeds the maximum depth of 16 profiles.');
        }

        try {
            $layer = ($this->readProfile)($profile);
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException($context.': '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($layer)) {
            throw new WorkdayConfigurationException($context.' must contain array profile definitions.');
        }

        try {
            $parent = [];

            if (array_key_exists('extends', $layer)) {
                if (! is_string($layer['extends']) || trim($layer['extends']) === '') {
                    throw new WorkdayConfigurationException('The extends value must be a non-empty configured profile name.');
                }

                $parent = $this->resolveChain($layer['extends'], $chain);
            }

            unset($layer['extends']);
            $effective = $this->merge($parent, $layer);

            return ProfileConfigValidator::validate($profile, $effective);
        } catch (InvalidArgumentException $exception) {
            throw new WorkdayConfigurationException($context.': '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  array<string, mixed>  $child
     * @return array<string, mixed>
     */
    private function merge(array $parent, array $child): array
    {
        // Unspecified fields inherit; other fields replace as whole values, never indexed recursion.
        $effective = array_replace($parent, $child);

        foreach (['custom_holidays', 'extra_working_days', 'special_working_hours'] as $section) {
            if (array_key_exists($section, $child)) {
                $effective[$section] = $this->mergeMap($parent[$section] ?? [], $child[$section]);
            }
        }

        if (array_key_exists('holidays', $child)) {
            $effective['holidays'] = $this->mergeMap($parent['holidays'] ?? [], $child['holidays']);

            if (is_array($child['holidays']) && $child['holidays'] !== []) {
                foreach ($child['holidays'] as $calendar => $entries) {
                    $effective['holidays'][$calendar] = $this->mergeMap($parent['holidays'][$calendar] ?? [], $entries);
                }
            }
        }

        if (array_key_exists('working_hours', $child)) {
            $hours = $child['working_hours'];

            if (is_array($hours)) {
                $hours = $this->normalizeWeekdays($hours, 'working_hours');
            }

            $effective['working_hours'] = $this->mergeMap($parent['working_hours'] ?? [], $hours);
        }

        if (array_key_exists('cutoff', $child) && is_array($child['cutoff'])) {
            $cutoff = $child['cutoff'];
            $parentCutoff = is_array($parent['cutoff'] ?? null) ? $parent['cutoff'] : [];
            $effective['cutoff'] = array_replace($parentCutoff, $cutoff);

            if (array_key_exists('weekdays', $cutoff)) {
                $parentWeekdays = array_key_exists('weekdays', $parentCutoff) ? $parentCutoff['weekdays'] : [];
                $effective['cutoff']['weekdays'] = $this->mergeCutoffWeekdays($parentWeekdays, $cutoff['weekdays']);
            }
        }

        return $effective;
    }

    private function mergeMap(mixed $parent, mixed $child): mixed
    {
        return is_array($child) && $child !== [] && is_array($parent) ? array_replace($parent, $child) : $child;
    }

    /**
     * @param  array<array-key, mixed>  $weekdays
     * @return array<int, mixed>
     */
    private function normalizeWeekdays(array $weekdays, string $section): array
    {
        $normalized = [];

        foreach ($weekdays as $weekday => $value) {
            $iso = WeekdayNormalizer::toIso($weekday);

            if (array_key_exists($iso, $normalized)) {
                throw new WorkdayConfigurationException(sprintf('Duplicate normalized %s weekday [%d] in one profile layer.', $section, $iso));
            }

            $normalized[$iso] = $value;
        }

        return $normalized;
    }

    private function mergeCutoffWeekdays(mixed $parent, mixed $child): mixed
    {
        if (! is_array($child) || $child === []) {
            return $child;
        }

        // Cutoff validation remains lazy. Keep a malformed map intact so CutoffPolicy
        // rejects it when used, rather than accidentally repairing it during inheritance.
        try {
            $childNormalized = $this->normalizeWeekdays($child, 'cutoff.weekdays');
        } catch (InvalidArgumentException) {
            return $child;
        }

        if (! is_array($parent)) {
            return $parent;
        }

        try {
            $parentNormalized = $this->normalizeWeekdays($parent, 'cutoff.weekdays');
        } catch (InvalidArgumentException) {
            return $parent;
        }

        return array_replace($parentNormalized, $childNormalized);
    }
}
