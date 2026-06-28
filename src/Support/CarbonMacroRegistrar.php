<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class CarbonMacroRegistrar
{
    /**
     * @var array<int, class-string>
     */
    private const CARBON_CLASSES = [
        Carbon::class,
        CarbonImmutable::class,
    ];

    /**
     * @var array<int, class-string>
     */
    private const NATIVE_METHOD_CLASSES = [
        Carbon::class,
        CarbonImmutable::class,
        IlluminateCarbon::class,
    ];

    public static function register(): void
    {
        if (! (bool) config('workdays.carbon_macros.enabled', true)) {
            return;
        }

        foreach (self::macros() as $name => $macro) {
            self::registerMacro($name, $macro);
        }

        if (! (bool) config('workdays.carbon_macros.short_aliases', true)) {
            return;
        }

        foreach (self::shortAliases() as $name => $macro) {
            self::registerMacro($name, $macro);
        }
    }

    /**
     * @return array<string, \Closure>
     */
    private static function macros(): array
    {
        return [
            'workdayIsBusinessDay' => self::booleanMacro('isBusinessDay'),
            'workdayIsNonWorkingDay' => self::booleanMacro('isNonWorkingDay'),
            'workdayIsHoliday' => self::booleanMacro('isHoliday'),
            'workdayIsWeekend' => self::booleanMacro('isWeekend'),
            'workdayIsCalendarHoliday' => self::booleanMacro('isCalendarHoliday'),
            'workdayIsExtraWorkingDay' => self::booleanMacro('isExtraWorkingDay'),
            'workdayNextBusinessDay' => self::dateResultMacro('nextBusinessDay'),
            'workdayPreviousBusinessDay' => self::dateResultMacro('previousBusinessDay'),
            'workdayAddBusinessDays' => self::addBusinessDaysMacro('addBusinessDays'),
            'workdaySubBusinessDays' => self::addBusinessDaysMacro('subBusinessDays'),
            'workdayDiffBusinessDaysUntil' => self::diffUntilMacro('diffBusinessDays'),
            'workdayExplain' => self::explainMacro(),
            'workdayIsBusinessTime' => self::booleanMacro('isBusinessTime'),
            'workdayWorkingWindows' => self::workingWindowsMacro(),
            'workdayNextBusinessTime' => self::dateResultMacro('nextBusinessTime'),
            'workdayPreviousBusinessTime' => self::dateResultMacro('previousBusinessTime'),
            'workdayAddBusinessMinutes' => self::addBusinessMinutesMacro(),
            'workdayAddBusinessHours' => self::addBusinessHoursMacro(),
            'workdayDiffBusinessMinutesUntil' => self::diffUntilMacro('diffBusinessMinutes'),
            'workdayDiffBusinessHoursUntil' => self::diffUntilMacro('diffBusinessHours'),
        ];
    }

    /**
     * @return array<string, \Closure>
     */
    private static function shortAliases(): array
    {
        return [
            'isBusinessDay' => self::booleanMacro('isBusinessDay'),
            'isNonWorkingDay' => self::booleanMacro('isNonWorkingDay'),
            'isWorkdayHoliday' => self::booleanMacro('isHoliday'),
            'isWorkdayWeekend' => self::booleanMacro('isWeekend'),
            'isCalendarHoliday' => self::booleanMacro('isCalendarHoliday'),
            'isExtraWorkingDay' => self::booleanMacro('isExtraWorkingDay'),
            'nextBusinessDay' => self::dateResultMacro('nextBusinessDay'),
            'previousBusinessDay' => self::dateResultMacro('previousBusinessDay'),
            'addBusinessDays' => self::addBusinessDaysMacro('addBusinessDays'),
            'subBusinessDays' => self::addBusinessDaysMacro('subBusinessDays'),
            'diffBusinessDaysUntil' => self::diffUntilMacro('diffBusinessDays'),
            'explainWorkday' => self::explainMacro(),
            'isBusinessTime' => self::booleanMacro('isBusinessTime'),
            'workingWindowsForWorkday' => self::workingWindowsMacro(),
            'nextBusinessTime' => self::dateResultMacro('nextBusinessTime'),
            'previousBusinessTime' => self::dateResultMacro('previousBusinessTime'),
            'addBusinessMinutes' => self::addBusinessMinutesMacro(),
            'addBusinessHours' => self::addBusinessHoursMacro(),
            'diffBusinessMinutesUntil' => self::diffUntilMacro('diffBusinessMinutes'),
            'diffBusinessHoursUntil' => self::diffUntilMacro('diffBusinessHours'),
        ];
    }

    private static function registerMacro(string $name, \Closure $macro): void
    {
        if (self::hasNativeMethod($name)) {
            return;
        }

        $overrideExisting = (bool) config('workdays.carbon_macros.override_existing', false);

        if (! $overrideExisting && Carbon::hasMacro($name)) {
            return;
        }

        foreach (self::CARBON_CLASSES as $class) {
            if ($overrideExisting || ! $class::hasMacro($name)) {
                $class::macro($name, $macro);
            }
        }
    }

    private static function hasNativeMethod(string $name): bool
    {
        foreach (self::NATIVE_METHOD_CLASSES as $class) {
            if (method_exists($class, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function booleanMacro(string $method): \Closure
    {
        return function (?string $profile = null) use ($method): bool {
            /** @var DateTimeInterface $this */
            return CarbonMacroRegistrar::target($profile)->{$method}($this);
        };
    }

    private static function dateResultMacro(string $method): \Closure
    {
        return function (?string $profile = null) use ($method): Carbon|CarbonImmutable {
            /** @var Carbon|CarbonImmutable $this */
            $result = CarbonMacroRegistrar::target($profile)->{$method}($this);

            return CarbonMacroRegistrar::castDateResult($this, $result);
        };
    }

    private static function addBusinessDaysMacro(string $method): \Closure
    {
        return function (int $days, ?string $profile = null) use ($method): Carbon|CarbonImmutable {
            /** @var Carbon|CarbonImmutable $this */
            $result = CarbonMacroRegistrar::target($profile)->{$method}($this, $days);

            return CarbonMacroRegistrar::castDateResult($this, $result);
        };
    }

    private static function addBusinessMinutesMacro(): \Closure
    {
        return function (int $minutes, ?string $profile = null): Carbon|CarbonImmutable {
            /** @var Carbon|CarbonImmutable $this */
            $result = CarbonMacroRegistrar::target($profile)->addBusinessMinutes($this, $minutes);

            return CarbonMacroRegistrar::castDateResult($this, $result);
        };
    }

    private static function addBusinessHoursMacro(): \Closure
    {
        return function (int|float $hours, ?string $profile = null): Carbon|CarbonImmutable {
            /** @var Carbon|CarbonImmutable $this */
            $result = CarbonMacroRegistrar::target($profile)->addBusinessHours($this, $hours);

            return CarbonMacroRegistrar::castDateResult($this, $result);
        };
    }

    private static function diffUntilMacro(string $method): \Closure
    {
        return function (string|DateTimeInterface $end, ?string $profile = null) use ($method): int|float {
            /** @var DateTimeInterface $this */
            return CarbonMacroRegistrar::target($profile)->{$method}($this, $end);
        };
    }

    private static function explainMacro(): \Closure
    {
        return function (?string $profile = null): mixed {
            /** @var DateTimeInterface $this */
            return CarbonMacroRegistrar::target($profile)->explain($this);
        };
    }

    private static function workingWindowsMacro(): \Closure
    {
        return function (?string $profile = null): array {
            /** @var DateTimeInterface $this */
            return CarbonMacroRegistrar::target($profile)->workingWindowsFor($this);
        };
    }

    public static function target(?string $profile): WorkdayManager|BusinessDayCalculator
    {
        $manager = app(WorkdayManager::class);

        if ($profile === null || $profile === '') {
            return $manager;
        }

        return $manager->profile($profile);
    }

    public static function castDateResult(Carbon|CarbonImmutable $original, CarbonImmutable $result): Carbon|CarbonImmutable
    {
        if ($original instanceof CarbonImmutable) {
            return $result;
        }

        return $original::instance($result);
    }
}
