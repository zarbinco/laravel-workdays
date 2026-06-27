<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkdaySpecialDate extends Model
{
    protected $table = 'workday_special_dates';

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
            'date' => 'immutable_date',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }
}
