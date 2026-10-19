<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;
use Zarbinco\LaravelWorkdays\Calendar\CalendarFingerprint;
use Zarbinco\LaravelWorkdays\Calendar\DeliveryPromiseCodec;

final readonly class DeliveryPromiseSnapshot
{
    public CarbonImmutable $receivedAt;

    /** @var array<string, mixed> */
    public array $plan;

    public DeliveryPlanResult $originalResult;

    /** @var array<string, mixed> */
    public array $calendarEvidence;

    /** @var array<string, mixed> */
    public array $runtimeMetadata;

    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $calendarEvidence
     * @param  array<string, mixed>  $runtimeMetadata
     */
    public function __construct(
        public int $schemaVersion,
        public string $calendarVersion,
        CarbonImmutable $receivedAt,
        array $plan,
        public int $horizonDays,
        DeliveryPlanResult $originalResult,
        array $calendarEvidence,
        array $runtimeMetadata,
    ) {
        $this->receivedAt = $receivedAt->setTimezone('UTC');
        $this->plan = self::detached($plan);
        $this->calendarEvidence = self::detached($calendarEvidence);
        $this->runtimeMetadata = self::detached($runtimeMetadata);
        $stages = [];

        foreach ($originalResult->stages as $stage) {
            $stages[] = new DeliveryStageResult($stage->id, $stage->type, self::detached($stage->profiles), $stage->readyAt, $stage->startedAt, $stage->completedAt);
        }

        $this->originalResult = new DeliveryPlanResult($originalResult->timezone, $originalResult->receivedAt, $originalResult->planningStartAt, $originalResult->cutoffResult, $stages, $originalResult->estimatedCompletionAt);
        $payload = [
            'schema_version' => $schemaVersion,
            'calendar_version' => $calendarVersion,
            'received_at' => $this->receivedAt->format('Y-m-d\TH:i:s.uP'),
            'plan' => $this->plan,
            'horizon_days' => $horizonDays,
            'original_result' => $this->originalResult->toArray(),
            'calendar_evidence' => $this->calendarEvidence,
            'runtime_metadata' => $this->runtimeMetadata,
        ];
        CalendarFingerprint::assertJson($payload);
        $this->payload = $payload + ['integrity_hash' => CalendarFingerprint::integrity($payload)];
        CalendarFingerprint::assertJson($this->payload);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return DeliveryPromiseCodec::restore($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function detached(array $value): array
    {
        CalendarFingerprint::assertJson($value);

        return json_decode(CalendarFingerprint::canonical($value), true, 34, JSON_THROW_ON_ERROR);
    }
}
