<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class ReverseDeliveryPlanResult
{
    public CarbonImmutable $targetCompletionAt;

    public CarbonImmutable $searchStartAt;

    public ?CarbonImmutable $latestOrderAt;

    public ?CarbonImmutable $planningStartAt;

    public ?CarbonImmutable $firstStageStartsAt;

    public ?CarbonImmutable $estimatedCompletionAt;

    public function __construct(
        public string $timezone,
        CarbonImmutable $targetCompletionAt,
        CarbonImmutable $searchStartAt,
        public bool $feasible,
        ?CarbonImmutable $latestOrderAt,
        ?CarbonImmutable $planningStartAt,
        ?CarbonImmutable $firstStageStartsAt,
        ?CarbonImmutable $estimatedCompletionAt,
        public ?int $slackMicroseconds,
        public ?DeliveryPlanResult $forwardResult,
    ) {
        $this->targetCompletionAt = $targetCompletionAt->setTimezone('UTC');
        $this->searchStartAt = $searchStartAt->setTimezone('UTC');
        $this->latestOrderAt = $latestOrderAt?->setTimezone('UTC');
        $this->planningStartAt = $planningStartAt?->setTimezone('UTC');
        $this->firstStageStartsAt = $firstStageStartsAt?->setTimezone('UTC');
        $this->estimatedCompletionAt = $estimatedCompletionAt?->setTimezone('UTC');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'timezone' => $this->timezone,
            'target_completion_at' => $this->targetCompletionAt->format('Y-m-d\TH:i:s.uP'),
            'search_start_at' => $this->searchStartAt->format('Y-m-d\TH:i:s.uP'),
            'feasible' => $this->feasible,
            'latest_order_at' => $this->latestOrderAt?->format('Y-m-d\TH:i:s.uP'),
            'planning_start_at' => $this->planningStartAt?->format('Y-m-d\TH:i:s.uP'),
            'first_stage_starts_at' => $this->firstStageStartsAt?->format('Y-m-d\TH:i:s.uP'),
            'estimated_completion_at' => $this->estimatedCompletionAt?->format('Y-m-d\TH:i:s.uP'),
            'slack_microseconds' => $this->slackMicroseconds,
            'forward_result' => $this->forwardResult?->toArray(),
        ];
    }
}
