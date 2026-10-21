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
                        '01-09' => 'Tasu\'a',
                        '01-10' => 'Ashura',
                        '02-20' => 'Arbaeen',
                        '02-28' => 'Demise of Prophet Muhammad and Martyrdom of Imam Hasan',
                        '02-30' => 'Martyrdom of Imam Reza',
                        '03-08' => 'Martyrdom of Imam Hasan al-Askari',
                        '03-17' => 'Birth of Prophet Muhammad and Imam Jafar al-Sadiq',
                        '06-03' => 'Martyrdom of Fatimah',
                        '07-13' => 'Birth of Imam Ali',
                        '07-27' => 'Prophet Muhammad\'s Mab\'ath',
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
