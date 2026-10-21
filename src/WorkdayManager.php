<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Zarbinco\LaravelWorkdays\Bulk\BulkCalculator;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayResult;
use Zarbinco\LaravelWorkdays\Calendar\DeliveryPromiseAuditor;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContext;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
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
use Zarbinco\LaravelWorkdays\Recurring\RecurringScheduleGenerator;
use Zarbinco\LaravelWorkdays\Sla\SlaEvaluator;

/** Long-lived service; each public operation opens its own source view and context. */
final readonly class WorkdayManager
{
    public function __construct(private CalculationContextFactory $contexts) {}

    public function bulkCalculate(array $operations, array $options = []): BulkCalculationResult
    {
        return (new BulkCalculator($this->contexts))->calculate($operations, $options);
    }

    public function profile(string $profile): BusinessDayCalculator
    {
        return $this->contexts->open()->calculator($profile);
    }

    public function resolveProfile(array $context): ResolvedProfile
    {
        return $this->contexts->open()->resolveProfile($context);
    }

    public function profileFor(array $context): BusinessDayCalculator
    {
        $operation = $this->contexts->open();
        $selection = $operation->resolveProfile($context);

        return $operation->calculator($selection->profile);
    }

    public function isBusinessDay(string|DateTimeInterface $date): bool
    {
        return $this->defaultCalculator()->isBusinessDay($date);
    }

    public function resolveCutoff(string|DateTimeInterface $receivedAt): CutoffResult
    {
        return $this->defaultCalculator()->resolveCutoff($receivedAt);
    }

    public function planDelivery(string|DateTimeInterface $receivedAt, array $plan): DeliveryPlanResult
    {
        return $this->planner($this->contexts->open())->plan($receivedAt, $plan);
    }

    public function deliverySlots(string|DateTimeInterface $readyAt, array $options): DeliverySlotsResult
    {
        $context = $this->contexts->open();

        return (new DeliverySlotGenerator($context->calculator(...), $context->maxScanDays))->generate($readyAt, $options);
    }

    public function evaluateSla(string|DateTimeInterface $startedAt, string|DateTimeInterface $evaluatedAt, array $options): SlaResult
    {
        $context = $this->contexts->open();

        return (new SlaEvaluator($context->calculator(...), $context->configuration(...), $context->maxScanDays))->evaluate($startedAt, $evaluatedAt, $options);
    }

    public function recurringSchedule(string|DateTimeInterface $anchorDate, array $options): RecurringScheduleResult
    {
        $context = $this->contexts->open();

        return (new RecurringScheduleGenerator($context->calculator(...), $context->maxScanDays))->generate($anchorDate, $options);
    }

    public function reversePlanDelivery(string|DateTimeInterface $desiredCompletionAt, array $plan, array $options): ReverseDeliveryPlanResult
    {
        $context = $this->contexts->open();

        return (new ReverseDeliveryPlanner($this->planner($context), $context->calculator(...), $context->configuration(...), $context->maxScanDays))->plan($desiredCompletionAt, $plan, $options);
    }

    public function captureDeliveryPromise(string|DateTimeInterface $receivedAt, array $plan, array $options): DeliveryPromiseSnapshot
    {
        return (new DeliveryPromiseAuditor($this->contexts->open()))->capture($receivedAt, $plan, $options);
    }

    public function compareDeliveryPromise(DeliveryPromiseSnapshot|array $snapshot): DeliveryPromiseComparison
    {
        return (new DeliveryPromiseAuditor($this->contexts->open()))->compare($snapshot);
    }

    private function planner(CalculationContext $context): DeliveryPlanner
    {
        return new DeliveryPlanner($context->calculator(...), $context->configuration(...), $context->maxScanDays, $context->hasProfile(...));
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
        $context = $this->contexts->open();

        return $context->calculator($context->defaultProfile());
    }
}
