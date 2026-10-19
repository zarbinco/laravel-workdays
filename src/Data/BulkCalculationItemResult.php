<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

use Carbon\CarbonImmutable;

final readonly class BulkCalculationItemResult
{
    public mixed $result;

    public ?array $error;

    public function __construct(public string $id, public string $operation, public bool $success, mixed $result, ?array $error)
    {
        $this->result = is_array($result) ? array_values(array_map(static fn ($value) => $value, $result)) : $result;
        $this->error = $error === null ? null : ['type' => $error['type'], 'message' => $error['message']];
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'operation' => $this->operation, 'success' => $this->success,
            'result' => $this->serialize($this->result), 'error' => $this->error];
    }

    private function serialize(mixed $value): mixed
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if (is_array($value)) {
            return array_map($this->serialize(...), $value);
        }

        return is_object($value) ? $value->toArray() : $value;
    }
}
