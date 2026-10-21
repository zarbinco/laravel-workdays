<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

use Closure;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\ResolvedProfile;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Holidays\LogicalHolidayProvider;
use Zarbinco\LaravelWorkdays\Support\CutoffPolicy;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\ProfileRouter;

/** One source view, effective definitions, adapters, provider and actual-limit calculator identities. */
final class CalculationContext
{
    private array $effective = [];

    private array $calculators = [];

    private ?HijriCalendarAdapter $hijri = null;

    private ?JalaliCalendarAdapter $jalali = null;

    private ?LogicalHolidayProvider $provider = null;

    private ?Closure $observer;

    public function __construct(public readonly WorkdayDefinitions $definitions, public readonly int $maxScanDays, ?callable $observer = null)
    {
        ProfileConfigValidator::validateMaxScanDays($maxScanDays);
        $this->observer = $observer === null ? null : Closure::fromCallable($observer);
    }

    public function hasProfile(string $profile): bool
    {
        return $this->definitions->hasProfile($profile);
    }

    public function defaultProfile(): string
    {
        return $this->definitions->defaultProfile;
    }

    public function includeStartDate(): bool
    {
        return $this->definitions->includeStartDate;
    }

    public function hijriSettings(): array
    {
        return $this->definitions->hijri;
    }

    public function configuration(string $profile): array
    {
        if (isset($this->effective[$profile])) {
            return $this->effective[$profile];
        }
        // A requested missing profile is a domain error. A persisted selected definition
        // that no longer validates is source corruption, including in Bulk collect mode.
        $persisted = $this->definitions->source === 'database' && $this->hasProfile($profile);
        try {
            $effective = (new ProfileConfigResolver($this->definitions->profile(...)))->resolve($profile);
            if ($persisted && array_key_exists('cutoff', $effective)) {
                CutoffPolicy::fromProfileConfig($profile, $effective);
            }

            return $this->effective[$profile] = $effective;
        } catch (InvalidArgumentException $error) {
            if ($persisted) {
                throw new DefinitionSourceException('Persisted Database profile ['.$profile.'] is invalid; repair the immutable definition release.', previous: $error);
            }
            throw $error;
        }
    }

    public function resolveProfile(array $context): ResolvedProfile
    {
        $selection = (new ProfileRouter($this->hasProfile(...)))->resolve($context, $this->definitions->routing());
        $this->configuration($selection->profile);

        return $selection;
    }

    public function calculator(string $profile, ?int $scanLimit = null): BusinessDayCalculator
    {
        $scanLimit ??= $this->maxScanDays;
        ProfileConfigValidator::validateMaxScanDays($scanLimit);
        if (isset($this->calculators[$profile][$scanLimit])) {
            return $this->calculators[$profile][$scanLimit];
        }
        $effective = $this->configuration($profile);
        if ($this->provider === null) {
            $this->provider = new LogicalHolidayProvider($this->configuration(...), $this->definitions->source);
            ($this->observer)?->__invoke('provider', $this->definitions->source, null);
        }
        $this->hijri ??= new HijriCalendarAdapter($this->definitions->hijri['method'], $this->definitions->hijri['adjustment']);
        $this->jalali ??= new JalaliCalendarAdapter;
        $calculator = new BusinessDayCalculator($profile, $effective, $this->definitions->includeStartDate, $scanLimit, $this->jalali, $this->hijri, $this->provider);
        ($this->observer)?->__invoke('calculator', $profile, $scanLimit);

        return $this->calculators[$profile][$scanLimit] = $calculator;
    }
}
