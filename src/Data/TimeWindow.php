<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

final readonly class TimeWindow
{
    public function __construct(
        public string $start,
        public string $end,
    ) {}

    public function startMinutes(): int
    {
        return $this->minutes($this->start);
    }

    public function endMinutes(): int
    {
        return $this->minutes($this->end);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
        ];
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
