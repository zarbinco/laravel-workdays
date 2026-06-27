<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Data;

final readonly class DayReason
{
    public function __construct(
        public string $type,
        public ?string $title = null,
        public ?string $source = null,
        public ?string $calendar = null,
        public ?string $key = null,
        public bool $overridden = false,
        public ?string $overriddenBy = null,
    ) {}

    /**
     * @return array{type: string, title: string|null, source: string|null, calendar: string|null, key: string|null, overridden: bool, overridden_by: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'source' => $this->source,
            'calendar' => $this->calendar,
            'key' => $this->key,
            'overridden' => $this->overridden,
            'overridden_by' => $this->overriddenBy,
        ];
    }
}
