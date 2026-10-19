<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

final readonly class BulkCalculationResult
{
    public int $total;

    public int $succeeded;

    public int $failed;

    /** @var list<BulkCalculationItemResult> */
    public array $items;

    /** @param list<BulkCalculationItemResult> $items */
    public function __construct(array $items)
    {
        $this->items = array_values(array_map(static fn (BulkCalculationItemResult $item) => $item, $items));
        $this->total = count($this->items);
        $this->succeeded = count(array_filter($this->items, static fn (BulkCalculationItemResult $item) => $item->success));
        $this->failed = $this->total - $this->succeeded;
    }

    public function toArray(): array
    {
        return ['total' => $this->total, 'succeeded' => $this->succeeded, 'failed' => $this->failed,
            'items' => array_map(static fn (BulkCalculationItemResult $item) => $item->toArray(), $this->items)];
    }
}
