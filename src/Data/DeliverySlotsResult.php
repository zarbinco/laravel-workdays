<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class DeliverySlotsResult
{
    public CarbonImmutable $readyAt;

    public CarbonImmutable $eligibleFromAt;

    /**
     * @param  list<DeliverySlot>  $slots
     */
    public function __construct(
        public string $profile,
        public string $timezone,
        CarbonImmutable $readyAt,
        CarbonImmutable $eligibleFromAt,
        public int $requestedDays,
        public array $slots,
    ) {
        $this->readyAt = $readyAt->setTimezone('UTC');
        $this->eligibleFromAt = $eligibleFromAt->setTimezone('UTC');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'timezone' => $this->timezone,
            'ready_at' => $this->readyAt->format('Y-m-d\TH:i:s.uP'),
            'eligible_from_at' => $this->eligibleFromAt->format('Y-m-d\TH:i:s.uP'),
            'requested_days' => $this->requestedDays,
            'slots' => array_map(static fn (DeliverySlot $slot): array => $slot->toArray(), $this->slots),
        ];
    }
}
