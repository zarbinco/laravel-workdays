<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class DeliveryPlanResult
{
    /**
     * @param  list<DeliveryStageResult>  $stages
     */
    public function __construct(
        public string $timezone,
        public CarbonImmutable $receivedAt,
        public CarbonImmutable $planningStartAt,
        public ?CutoffResult $cutoffResult,
        public array $stages,
        public CarbonImmutable $estimatedCompletionAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'received_at' => $this->receivedAt->format('Y-m-d\TH:i:s.uP'),
            'planning_start_at' => $this->planningStartAt->format('Y-m-d\TH:i:s.uP'),
            'cutoff_result' => $this->cutoffResult?->toArray(),
            'stages' => array_map(static fn (DeliveryStageResult $stage): array => $stage->toArray(), $this->stages),
            'estimated_completion_at' => $this->estimatedCompletionAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
