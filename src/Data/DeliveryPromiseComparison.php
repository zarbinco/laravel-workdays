<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Calendar\CalendarFingerprint;

final readonly class DeliveryPromiseComparison
{
    public CarbonImmutable $originalEstimatedCompletionAt;

    public ?CarbonImmutable $currentEstimatedCompletionAt;

    /** @var list<array<string, mixed>> */
    public array $changedCalendarDays;

    /** @param list<array<string, mixed>> $changedCalendarDays */
    public function __construct(
        public string $status,
        public string $originalCalendarVersion,
        public ?string $currentCalendarVersion,
        public ?bool $versionChanged,
        public ?bool $estimateChanged,
        public string $direction,
        CarbonImmutable $originalEstimatedCompletionAt,
        ?CarbonImmutable $currentEstimatedCompletionAt,
        public int|float|null $deltaSeconds,
        array $changedCalendarDays,
        public bool $runtimeChanged,
        public ?string $error,
        public ?bool $configurationChanged,
        public ?bool $holidayInputsChanged,
        public ?bool $databaseRulesChanged = null,
    ) {
        $this->originalEstimatedCompletionAt = $originalEstimatedCompletionAt->setTimezone('UTC');
        $this->currentEstimatedCompletionAt = $currentEstimatedCompletionAt?->setTimezone('UTC');
        $this->changedCalendarDays = json_decode(CalendarFingerprint::comparisonJson($changedCalendarDays), true, 34, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'original_calendar_version' => $this->originalCalendarVersion,
            'current_calendar_version' => $this->currentCalendarVersion,
            'version_changed' => $this->versionChanged,
            'estimate_changed' => $this->estimateChanged,
            'direction' => $this->direction,
            'original_estimated_completion_at' => $this->originalEstimatedCompletionAt->format('Y-m-d\TH:i:s.uP'),
            'current_estimated_completion_at' => $this->currentEstimatedCompletionAt?->format('Y-m-d\TH:i:s.uP'),
            'delta_seconds' => $this->deltaSeconds,
            'changed_calendar_days' => $this->changedCalendarDays,
            'runtime_changed' => $this->runtimeChanged,
            'error' => $this->error,
            'configuration_changed' => $this->configurationChanged,
            'holiday_inputs_changed' => $this->holidayInputsChanged,
            'database_rules_changed' => $this->databaseRulesChanged,
        ];
    }
}
