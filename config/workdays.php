<?php

declare(strict_types=1);

return [
    'default_profile' => 'iran',

    'include_start_date' => false,

    'profiles' => [
        'iran' => [
            'weekends' => ['Thursday', 'Friday'],

            'holidays' => [
                'gregorian' => [
                    // '01-01' => 'New Year',
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
            ],

            'custom_holidays' => [],

            'extra_working_days' => [],
        ],
    ],
];
