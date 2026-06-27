<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkdayHolidayRule extends Model
{
    protected $table = 'workday_holiday_rules';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }
}
