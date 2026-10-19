<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Bulk\BulkCalculator;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayResult;
use Zarbinco\LaravelWorkdays\Calendar\DeliveryPromiseAuditor;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\BulkCalculationResult;
use Zarbinco\LaravelWorkdays\Data\CutoffResult;
use Zarbinco\LaravelWorkdays\Data\DayInfo;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseComparison;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Data\DeliverySlotsResult;
use Zarbinco\LaravelWorkdays\Data\RecurringScheduleResult;
use Zarbinco\LaravelWorkdays\Data\ResolvedProfile;
use Zarbinco\LaravelWorkdays\Data\ReverseDeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\SlaResult;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Delivery\DeliveryPlanner;
use Zarbinco\LaravelWorkdays\Delivery\DeliverySlotGenerator;
use Zarbinco\LaravelWorkdays\Delivery\ReverseDeliveryPlanner;
use Zarbinco\LaravelWorkdays\Holidays\ChainHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\ConfigHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\DatabaseHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Recurring\RecurringScheduleGenerator;
use Zarbinco\LaravelWorkdays\Sla\SlaEvaluator;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\ProfileRouter;
use Zarbinco\LaravelWorkdays\Support\StorageDriver;

final class WorkdayManager
{
    public function bulkCalculate(array $operations, array $options = []): BulkCalculationResult
    {
        return (new BulkCalculator)->calculate($operations, $options);
    }

    public function profile(string $profile): BusinessDayCalculator
    {
        return $this->calculator($profile);
    }

    /** @param array<string, mixed> $context */
    public function resolveProfile(array $context): ResolvedProfile
    {
        $profiles = $this->profiles();
        $selection = $this->selectProfile($context, $profiles);
        $this->effectiveProfileConfig($selection->profile, $profiles);

        return $selection;
    }

    /** @param array<string, mixed> $context */
    public function profileFor(array $context): BusinessDayCalculator
    {
        $profiles = $this->profiles();
        $selection = $this->selectProfile($context, $profiles);
        $effective = $this->effectiveProfileConfig($selection->profile, $profiles);

        return $this->calculator($selection->profile, profileConfig: $effective, profiles: $profiles);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $profiles
     */
    private function selectProfile(array $context, array $profiles): ResolvedProfile
    {
        return (new ProfileRouter($profiles))->resolve($context, config('workdays.profile_routing'));
    }

    /**
     * @param  array<string, mixed>|null  $profileConfig
     * @param  array<string, mixed>|null  $profiles
     */
    private function calculator(string $profile, ?int $scanLimit = null, ?array $profileConfig = null, ?array $profiles = null): BusinessDayCalculator
    {
        $profiles ??= $this->profiles();

        if (! array_key_exists($profile, $profiles)) {
            throw new InvalidArgumentException(sprintf('Workdays profile [%s] is not configured.', $profile));
        }

        $profileConfig ??= $this->effectiveProfileConfig($profile, $profiles);

        if (is_array($profiles[$profile]) && array_key_exists('extends', $profiles[$profile])) {
            // Config holidays must see the same snapshot, under the child's actual name.
            $profiles[$profile] = $profileConfig;
        }

        return new BusinessDayCalculator(
            profile: $profile,
            profileConfig: $profileConfig,
            includeStartDate: $this->includeStartDate(),
            maxScanDays: $scanLimit ?? $this->maxScanDays(),
            hijriCalendar: HijriCalendarAdapter::fromConfig(),
            holidayProvider: $this->holidayProvider($profiles),
        );
    }

    public function isBusinessDay(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isBusinessDay($date);
    }

    public function resolveCutoff(string|DateTimeInterface $receivedAt): CutoffResult
    {
        return $this->defaultCalculator()->resolveCutoff($receivedAt);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function planDelivery(string|DateTimeInterface $receivedAt, array $plan): DeliveryPlanResult
    {
        $profiles = null;
        $resolved = [];
        $resolveConfig = function (string $profile) use (&$profiles, &$resolved): array {
            $profiles ??= $this->profiles();

            return $resolved[$profile] ??= $this->effectiveProfileConfig($profile, $profiles);
        };

        return (new DeliveryPlanner(
            function (string $profile, int $scanLimit) use ($resolveConfig, &$profiles): BusinessDayCalculator {
                $effective = $resolveConfig($profile);

                return $this->calculator($profile, $scanLimit, $effective, $profiles);
            },
            $resolveConfig,
        ))->plan($receivedAt, $plan);
    }

    public function captureDeliveryPromise(string|DateTimeInterface $receivedAt, array $plan, array $options): DeliveryPromiseSnapshot
    {
        return (new DeliveryPromiseAuditor)->capture($receivedAt, $plan, $options);
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $options */
    public function reversePlanDelivery(string|DateTimeInterface $desiredCompletionAt, array $plan, array $options): ReverseDeliveryPlanResult
    {
        $profiles = $this->profiles();
        $resolved = [];
        $calculators = [];
        $provider = null;
        $hijri = null;
        $resolveConfig = function (string $profile) use ($profiles, &$resolved): array {
            return $resolved[$profile] ??= $this->effectiveProfileConfig($profile, $profiles);
        };
        $resolveCalculator = function (string $profile, int $scanLimit) use ($profiles, &$resolved, &$calculators, &$provider, &$hijri, $resolveConfig): BusinessDayCalculator {
            if (isset($calculators[$profile][$scanLimit])) {
                return $calculators[$profile][$scanLimit];
            }

            $effective = $resolveConfig($profile);
            // All selected configurations are resolved before the first provider is built.
            // Keep child names intact, so database rows never inherit from a parent profile.
            $provider ??= $this->holidayProvider(array_replace($profiles, $resolved));
            $hijri ??= HijriCalendarAdapter::fromConfig();

            return $calculators[$profile][$scanLimit] = new BusinessDayCalculator(
                profile: $profile,
                profileConfig: $effective,
                includeStartDate: $this->includeStartDate(),
                maxScanDays: $scanLimit,
                hijriCalendar: $hijri,
                holidayProvider: $provider,
            );
        };

        return (new ReverseDeliveryPlanner(
            new DeliveryPlanner($resolveCalculator, $resolveConfig), $resolveCalculator, $resolveConfig,
        ))->plan($desiredCompletionAt, $plan, $options);
    }

    public function compareDeliveryPromise(DeliveryPromiseSnapshot|array $snapshot): DeliveryPromiseComparison
    {
        return (new DeliveryPromiseAuditor)->compare($snapshot);
    }

    /** @param array<string, mixed> $options */
    public function evaluateSla(string|DateTimeInterface $startedAt, string|DateTimeInterface $evaluatedAt, array $options): SlaResult
    {
        $profiles = null;
        $effective = null;
        $resolveConfig = function (string $profile) use (&$profiles, &$effective): array {
            $profiles ??= $this->profiles();

            return $effective ??= $this->effectiveProfileConfig($profile, $profiles);
        };

        return (new SlaEvaluator(
            function (string $profile, int $limit) use ($resolveConfig, &$profiles): BusinessDayCalculator {
                $profileConfig = $resolveConfig($profile);

                return $this->calculator($profile, $limit, $profileConfig, $profiles);
            },
            $resolveConfig,
        ))->evaluate($startedAt, $evaluatedAt, $options);
    }

    /**
     * @param  array<string, mixed>  $profiles
     * @return array<string, mixed>
     */
    private function effectiveProfileConfig(string $profile, array $profiles): array
    {
        if (! array_key_exists($profile, $profiles)) {
            throw new InvalidArgumentException(sprintf('Workdays profile [%s] is not configured.', $profile));
        }

        if (is_array($profiles[$profile]) && array_key_exists('extends', $profiles[$profile])) {
            return (new ProfileConfigResolver($profiles))->resolve($profile);
        }

        return ProfileConfigValidator::validate($profile, $profiles[$profile]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function deliverySlots(string|DateTimeInterface $readyAt, array $options): DeliverySlotsResult
    {
        return (new DeliverySlotGenerator($this->profile(...)))->generate($readyAt, $options);
    }

    /** @param array<string, mixed> $options */
    public function recurringSchedule(string|DateTimeInterface $anchorDate, array $options): RecurringScheduleResult
    {
        return (new RecurringScheduleGenerator($this->profile(...)))->generate($anchorDate, $options);
    }

    public function explain(string|DateTimeInterface $date): DayInfo
    {
        return $this->defaultCalculator()->explain($date);
    }

    public function isHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isHoliday($date);
    }

    public function isNonWorkingDay(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isNonWorkingDay($date);
    }

    public function isWeekend(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isWeekend($date);
    }

    public function isCalendarHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isCalendarHoliday($date);
    }

    public function isGregorianHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isGregorianHoliday($date);
    }

    public function isJalaliHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isJalaliHoliday($date);
    }

    public function isHijriHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isHijriHoliday($date);
    }

    public function isCustomHoliday(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isCustomHoliday($date);
    }

    public function isExtraWorkingDay(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isExtraWorkingDay($date);
    }

    public function isBusinessTime(string|DateTimeInterface $datetime): bool
    {
        return $this->defaultCalculator()->isBusinessTime($datetime);
    }

    /**
     * @return array<int, TimeWindow>
     */
    public function workingWindowsFor(string|DateTimeInterface $date): array
    {
        return $this->defaultCalculator()->workingWindowsFor($date);
    }

    public function nextBusinessTime(string|DateTimeInterface $datetime): CarbonImmutable
    {
        return $this->defaultCalculator()->nextBusinessTime($datetime);
    }

    public function previousBusinessTime(string|DateTimeInterface $datetime): CarbonImmutable
    {
        return $this->defaultCalculator()->previousBusinessTime($datetime);
    }

    public function addBusinessMinutes(string|DateTimeInterface $datetime, int $minutes): CarbonImmutable
    {
        return $this->defaultCalculator()->addBusinessMinutes($datetime, $minutes);
    }

    public function addBusinessHours(string|DateTimeInterface $datetime, int|float $hours): CarbonImmutable
    {
        return $this->defaultCalculator()->addBusinessHours($datetime, $hours);
    }

    public function diffBusinessMinutes(string|DateTimeInterface $startDate, string|DateTimeInterface $endDate): int
    {
        return $this->defaultCalculator()->diffBusinessMinutes($startDate, $endDate);
    }

    public function diffBusinessHours(string|DateTimeInterface $startDate, string|DateTimeInterface $endDate): float
    {
        return $this->defaultCalculator()->diffBusinessHours($startDate, $endDate);
    }

    public function addBusinessDays(string|DateTimeInterface $date, int $days): CarbonImmutable
    {
        return $this->defaultCalculator()->addBusinessDays($date, $days);
    }

    public function subBusinessDays(string|DateTimeInterface $date, int $days): CarbonImmutable
    {
        return $this->defaultCalculator()->subBusinessDays($date, $days);
    }

    public function nextBusinessDay(string|DateTimeInterface $date): CarbonImmutable
    {
        return $this->defaultCalculator()->nextBusinessDay($date);
    }

    public function previousBusinessDay(string|DateTimeInterface $date): CarbonImmutable
    {
        return $this->defaultCalculator()->previousBusinessDay($date);
    }

    public function diffBusinessDays(string|DateTimeInterface $startDate, string|DateTimeInterface $endDate): int
    {
        return $this->defaultCalculator()->diffBusinessDays($startDate, $endDate);
    }

    public function calculate(string|DateTimeInterface $date, int $businessDays): BusinessDayResult
    {
        return $this->defaultCalculator()->calculate($date, $businessDays);
    }

    private function defaultCalculator(): BusinessDayCalculator
    {
        $profile = config('workdays.default_profile');

        if (! is_string($profile) || $profile === '') {
            throw new InvalidArgumentException('The workdays default_profile config value must be a non-empty string.');
        }

        return $this->profile($profile);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function profiles(): array
    {
        $profiles = config('workdays.profiles', []);

        if (! is_array($profiles)) {
            throw new InvalidArgumentException('The workdays profiles config value must be an array.');
        }

        return $profiles;
    }

    private function includeStartDate(): bool
    {
        return ProfileConfigValidator::validateIncludeStartDate(config('workdays.include_start_date', false));
    }

    private function maxScanDays(): int
    {
        return ProfileConfigValidator::validateMaxScanDays(config('workdays.max_scan_days', 3660));
    }

    /**
     * @param  array<string, array<string, mixed>>  $profiles
     */
    private function holidayProvider(array $profiles): HolidayProviderInterface
    {
        $driver = config('workdays.storage.driver', 'config');

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('The workdays storage.driver config value must be a non-empty string.');
        }

        $configProvider = new ConfigHolidayProvider($profiles);

        return match ($driver) {
            StorageDriver::CONFIG => $configProvider,
            StorageDriver::DATABASE => new DatabaseHolidayProvider,
            StorageDriver::CHAIN => new ChainHolidayProvider($configProvider, new DatabaseHolidayProvider),
            default => throw new InvalidArgumentException(StorageDriver::unsupportedMessage($driver)),
        };
    }
}
