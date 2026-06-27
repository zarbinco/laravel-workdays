<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Iran Workdays Preset
    |--------------------------------------------------------------------------
    |
    | This preset is config-based. It provides recurring Jalali and Hijri
    | holidays commonly used for Iran workday calculations, but it does not
    | generate an exact year-by-year official Iran calendar.
    |
    | Lunar Hijri holidays may differ by official moon-sighting and country
    | rules. Add exact official overrides to custom_holidays when needed.
    | Government bridge holidays are not included.
    |
    | Some companies only treat Friday as a weekend or treat Thursday as a
    | half-day. Edit weekends for your organization.
    |
    */

    'default_profile' => 'iran',

    'include_start_date' => false,

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
                'gregorian' => [],

                'jalali' => [
                    '01-01' => 'Nowruz',
                    '01-02' => 'Nowruz Holiday',
                    '01-03' => 'Nowruz Holiday',
                    '01-04' => 'Nowruz Holiday',
                    '01-12' => 'Islamic Republic Day',
                    '01-13' => 'Nature Day',
                    '03-14' => 'Demise of Imam Khomeini',
                    '03-15' => 'Khordad 15 Uprising',
                    '11-22' => 'Islamic Revolution Victory Day',
                    '12-29' => 'Oil Nationalization Day',
                ],

                'hijri' => [
                    '01-09' => "Tasu'a",
                    '01-10' => 'Ashura',
                    '02-20' => 'Arbaeen',
                    '02-28' => 'Demise of Prophet Muhammad and Martyrdom of Imam Hasan',
                    '02-30' => 'Martyrdom of Imam Reza',
                    '03-08' => 'Martyrdom of Imam Hasan al-Askari',
                    '03-17' => 'Birth of Prophet Muhammad and Imam Jafar al-Sadiq',
                    '06-03' => 'Martyrdom of Fatimah',
                    '07-13' => 'Birth of Imam Ali',
                    '07-27' => "Prophet Muhammad's Mab'ath",
                    '08-15' => 'Birth of Imam Mahdi',
                    '09-21' => 'Martyrdom of Imam Ali',
                    '10-01' => 'Eid al-Fitr',
                    '10-02' => 'Eid al-Fitr Holiday',
                    '10-25' => 'Martyrdom of Imam Jafar al-Sadiq',
                    '12-10' => 'Eid al-Adha',
                    '12-18' => 'Eid al-Ghadir',
                ],
            ],

            'custom_holidays' => [
                // Add exact Gregorian company/government/bridge holidays here.
                // Example:
                // '2026-06-25' => 'Company holiday',
            ],

            'extra_working_days' => [
                // Add exact Gregorian compensation working days here.
                // Example:
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

                'jalali' => [],

                'hijri' => [],
            ],

            'custom_holidays' => [],

            'extra_working_days' => [],
        ],
    ],
];
