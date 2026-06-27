<?php

declare(strict_types=1);

return [
    'default_profile' => 'iran',

    'include_start_date' => false,

    'max_scan_days' => 3660,

    'storage' => [
        'driver' => 'config',
    ],

    'hijri' => [
        'method' => 'umm_al_qura',
        'adjustment' => 0,
    ],

    'profiles' => [
        'iran' => [
            'weekends' => ['Thursday', 'Friday'],

            'holidays' => [
                'gregorian' => [
                    // '01-01' => 'New Year',
                ],

                'jalali' => [
                    // '01-01' => 'Nowruz',
                    // '04-01' => 'Example Jalali Holiday',
                ],

                'hijri' => [
                    // '01-09' => 'Tasu’a',
                    // '01-10' => 'Ashura',
                ],
            ],

            'custom_holidays' => [
                // '2026-06-25' => 'Company holiday',
            ],

            'extra_working_days' => [
                // '2026-06-26' => 'Compensation working day',
            ],
        ],

        'global' => [
            'weekends' => ['Saturday', 'Sunday'],

            'holidays' => [
                'gregorian' => [
                    '01-01' => 'New Year',
                    '12-25' => 'Christmas',
                ],

                'jalali' => [
                    // '01-01' => 'Nowruz',
                ],

                'hijri' => [
                    // '01-09' => 'Tasu’a',
                    // '01-10' => 'Ashura',
                ],
            ],

            'custom_holidays' => [],

            'extra_working_days' => [],
        ],
    ],
];
