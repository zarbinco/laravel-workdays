<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class DeliveryStageResult
{
    /**
     * @param  list<string>  $profiles
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $profiles,
        public CarbonImmutable $readyAt,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $completedAt,
    ) {}

    /**
     * @return array{id: string, type: string, profiles: list<string>, ready_at: string, started_at: string, completed_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'profiles' => $this->profiles,
            'ready_at' => $this->readyAt->format('Y-m-d\TH:i:s.uP'),
            'started_at' => $this->startedAt->format('Y-m-d\TH:i:s.uP'),
            'completed_at' => $this->completedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
