<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

/** Optional, operation-local optimization; ordinary holiday providers need no range support. */
interface RangePrefetchableHolidayProviderInterface extends HolidayProviderInterface
{
    /** @param list<string> $profiles At most 50 selected profile names; range is at most 62 inclusive date labels. */
    public function prefetch(array $profiles, string $from, string $through): void;
}
