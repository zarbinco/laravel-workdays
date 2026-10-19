<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class SlaResult
{
    public CarbonImmutable $startedAt;

    public CarbonImmutable $evaluatedAt;

    public ?CarbonImmutable $dueAt;

    public function __construct(
        public string $profile,
        public string $timezone,
        CarbonImmutable $startedAt,
        CarbonImmutable $evaluatedAt,
        public int $targetMinutes,
        public int $consumedWorkingMinutes,
        public int $pausedWorkingMinutes,
        public int $remainingWorkingMinutes,
        public int $overdueWorkingMinutes,
        ?CarbonImmutable $dueAt,
        public bool $paused,
        public bool $breached,
        public string $status,
    ) {
        $this->startedAt = $startedAt->setTimezone('UTC');
        $this->evaluatedAt = $evaluatedAt->setTimezone('UTC');
        $this->dueAt = $dueAt?->setTimezone('UTC');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'timezone' => $this->timezone,
            'started_at' => $this->startedAt->format('Y-m-d\TH:i:s.uP'),
            'evaluated_at' => $this->evaluatedAt->format('Y-m-d\TH:i:s.uP'),
            'target_minutes' => $this->targetMinutes,
            'consumed_working_minutes' => $this->consumedWorkingMinutes,
            'paused_working_minutes' => $this->pausedWorkingMinutes,
            'remaining_working_minutes' => $this->remainingWorkingMinutes,
            'overdue_working_minutes' => $this->overdueWorkingMinutes,
            'due_at' => $this->dueAt?->format('Y-m-d\TH:i:s.uP'),
            'paused' => $this->paused,
            'breached' => $this->breached,
            'status' => $this->status,
        ];
    }
}
