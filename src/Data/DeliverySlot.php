<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class DeliverySlot
{
    public CarbonImmutable $startsAt;

    public CarbonImmutable $endsAt;

    public function __construct(CarbonImmutable $startsAt, CarbonImmutable $endsAt, private string $timezone)
    {
        $this->startsAt = $startsAt->setTimezone('UTC');
        $this->endsAt = $endsAt->setTimezone('UTC');
    }

    /**
     * @return array{starts_at: string, ends_at: string, local_starts_at: string, local_ends_at: string}
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->format('Y-m-d\TH:i:s.uP'),
            'ends_at' => $this->endsAt->format('Y-m-d\TH:i:s.uP'),
            'local_starts_at' => $this->startsAt->setTimezone($this->timezone)->format('Y-m-d\TH:i:s.uP'),
            'local_ends_at' => $this->endsAt->setTimezone($this->timezone)->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
