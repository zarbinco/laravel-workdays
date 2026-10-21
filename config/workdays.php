<?php

declare(strict_types=1);

// Select one complete operational source; connection settings are bootstrap only.
return [
    'driver' => env('WORKDAYS_DRIVER', 'config'),
    'database' => [
        'connection' => env('WORKDAYS_DB_CONNECTION'),
    ],
    'max_scan_days' => 3660,
    'carbon_macros' => [
        'enabled' => true,
        'short_aliases' => true,
        'override_existing' => false,
    ],
    'iran_official' => [
        'enabled' => false,
        'year' => null,
        'profile' => null,
        'calendar_path' => null,
    ],
    'definitions' => [
        'default_profile' => 'iran',
        'include_start_date' => false,
        'hijri' => [
            'method' => 'umm_al_qura',
            'adjustment' => 0,
        ],
        'profile_routing' => null,
        'profiles' => [
            'iran' => [
                'weekends' => [
                    0 => 'Thursday',
                    1 => 'Friday',
                ],
                'holidays' => [
                    'gregorian' => [
                    ],
                    'jalali' => [
                    ],
                    'hijri' => [
                    ],
                ],
                'custom_holidays' => [
                ],
                'extra_working_days' => [
                ],
            ],
            'global' => [
                'weekends' => [
                    0 => 'Saturday',
                    1 => 'Sunday',
                ],
                'holidays' => [
                    'gregorian' => [
                        '01-01' => 'New Year',
                        '12-25' => 'Christmas',
                    ],
                    'jalali' => [
                    ],
                    'hijri' => [
                    ],
                ],
                'custom_holidays' => [
                ],
                'extra_working_days' => [
                ],
            ],
        ],
    ],
];
