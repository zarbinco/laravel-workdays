<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Support\DateNormalizer;
use Zarbinco\LaravelWorkdays\Support\WeekdayNormalizer;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class WorkdayCalculatorTest extends TestCase
{
    public function test_weekday_normalizer_supports_english_full_names(): void
    {
        $this->assertSame(1, WeekdayNormalizer::toIso('Monday'));
        $this->assertSame(4, WeekdayNormalizer::toIso('Thursday'));
        $this->assertSame(7, WeekdayNormalizer::toIso('Sunday'));
    }

    public function test_weekday_normalizer_supports_english_short_names(): void
    {
        $this->assertSame(1, WeekdayNormalizer::toIso('mon'));
        $this->assertSame(4, WeekdayNormalizer::toIso('thu'));
        $this->assertSame(7, WeekdayNormalizer::toIso('sun'));
    }

    public function test_weekday_normalizer_supports_persian_names(): void
    {
        $this->assertSame(6, WeekdayNormalizer::toIso('شنبه'));
        $this->assertSame(7, WeekdayNormalizer::toIso('یکشنبه'));
        $this->assertSame(7, WeekdayNormalizer::toIso('یک‌شنبه'));
        $this->assertSame(2, WeekdayNormalizer::toIso('سه شنبه'));
        $this->assertSame(2, WeekdayNormalizer::toIso('سه‌شنبه'));
        $this->assertSame(4, WeekdayNormalizer::toIso('پنجشنبه'));
        $this->assertSame(4, WeekdayNormalizer::toIso('پنج‌شنبه'));
        $this->assertSame(5, WeekdayNormalizer::toIso('جمعه'));
    }

    public function test_invalid_weekday_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid weekday name');

        WeekdayNormalizer::toIso('Funday');
    }

    public function test_invalid_numeric_weekday_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ISO weekday [8]');

        WeekdayNormalizer::toIso(8);
    }

    public function test_iran_profile_adds_business_days_with_thursday_friday_weekend(): void
    {
        $result = Workday::profile('iran')->addBusinessDays('2026-06-24', 2);

        $this->assertSame('2026-06-28', $result->toDateString());
    }

    public function test_global_profile_adds_business_days_with_saturday_sunday_weekend(): void
    {
        $result = Workday::profile('global')->addBusinessDays('2026-06-26', 1);

        $this->assertSame('2026-06-29', $result->toDateString());
    }

    public function test_custom_holiday_exact_gregorian_date_is_skipped(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Company holiday',
            ],
        ]);

        $result = Workday::profile('global')->addBusinessDays('2026-06-26', 1);

        $this->assertSame('2026-06-30', $result->toDateString());
    }

    public function test_is_holiday_returns_true_for_custom_holiday(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Company holiday',
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isCustomHoliday('2026-06-29'));
        $this->assertTrue($calculator->isHoliday('2026-06-29'));
        $this->assertFalse($calculator->isBusinessDay('2026-06-29'));
    }

    public function test_recurring_gregorian_holiday_is_skipped(): void
    {
        $result = Workday::profile('global')->addBusinessDays('2026-12-24', 1);

        $this->assertSame('2026-12-28', $result->toDateString());
    }

    public function test_config_accepts_jalali_holiday_section(): void
    {
        $this->assertIsArray(config('workdays.profiles.iran.holidays.jalali'));
        $this->assertIsArray(config('workdays.profiles.global.holidays.jalali'));
    }

    public function test_jalali_calendar_adapter_converts_between_gregorian_and_jalali(): void
    {
        $adapter = new JalaliCalendarAdapter();

        $this->assertSame('04-01', $adapter->monthDayFromGregorian(DateNormalizer::toImmutable('2026-06-22')));
        $this->assertSame('2026-06-22', $adapter->jalaliDateToGregorian(1405, 4, 1)->toDateString());
    }

    public function test_is_holiday_returns_true_for_recurring_gregorian_holiday(): void
    {
        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isGregorianHoliday('2026-12-25'));
        $this->assertTrue($calculator->isCalendarHoliday('2026-12-25'));
        $this->assertTrue($calculator->isHoliday('2026-12-25'));
        $this->assertFalse($calculator->isBusinessDay('2026-12-25'));
    }

    public function test_jalali_recurring_holiday_is_detected(): void
    {
        $this->setProfileConfig('iran', [
            'holidays' => [
                'jalali' => [
                    '04-01' => 'Example Jalali Holiday',
                ],
            ],
        ]);

        $calculator = Workday::profile('iran');

        $this->assertTrue($calculator->isJalaliHoliday('2026-06-22'));
        $this->assertTrue($calculator->isCalendarHoliday('2026-06-22'));
        $this->assertTrue($calculator->isHoliday('2026-06-22'));
        $this->assertFalse($calculator->isBusinessDay('2026-06-22'));
    }

    public function test_jalali_recurring_holiday_is_skipped_by_add_business_days(): void
    {
        $this->setProfileConfig('iran', [
            'holidays' => [
                'jalali' => [
                    '04-01' => 'Example Jalali Holiday',
                ],
            ],
        ]);

        $result = Workday::profile('iran')->addBusinessDays('2026-06-21', 1);

        $this->assertSame('2026-06-23', $result->toDateString());
    }

    public function test_gregorian_holiday_still_works_after_jalali_support(): void
    {
        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isGregorianHoliday('2026-12-25'));
        $this->assertTrue($calculator->isCalendarHoliday('2026-12-25'));
        $this->assertTrue($calculator->isHoliday('2026-12-25'));
    }

    public function test_is_gregorian_holiday_returns_true_only_for_gregorian_recurring_holidays(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'jalali' => [
                    '04-01' => 'Example Jalali Holiday',
                ],
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isGregorianHoliday('2026-12-25'));
        $this->assertFalse($calculator->isGregorianHoliday('2026-06-22'));
    }

    public function test_is_jalali_holiday_returns_true_only_for_jalali_recurring_holidays(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'jalali' => [
                    '04-01' => 'Example Jalali Holiday',
                ],
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isJalaliHoliday('2026-06-22'));
        $this->assertFalse($calculator->isJalaliHoliday('2026-12-25'));
    }

    public function test_is_calendar_holiday_returns_true_for_gregorian_or_jalali_recurring_holidays(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'jalali' => [
                    '04-01' => 'Example Jalali Holiday',
                ],
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isCalendarHoliday('2026-12-25'));
        $this->assertTrue($calculator->isCalendarHoliday('2026-06-22'));
    }

    public function test_is_calendar_holiday_does_not_include_custom_holidays(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Company holiday',
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isCustomHoliday('2026-06-29'));
        $this->assertFalse($calculator->isCalendarHoliday('2026-06-29'));
    }

    public function test_extra_working_day_overrides_weekend(): void
    {
        $this->setProfileConfig('global', [
            'extra_working_days' => [
                '2026-06-27' => 'Compensation working day',
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isWeekend('2026-06-27'));
        $this->assertTrue($calculator->isExtraWorkingDay('2026-06-27'));
        $this->assertTrue($calculator->isBusinessDay('2026-06-27'));
        $this->assertFalse($calculator->isHoliday('2026-06-27'));
        $this->assertFalse($calculator->isNonWorkingDay('2026-06-27'));
        $this->assertSame('2026-06-27', $calculator->addBusinessDays('2026-06-26', 1)->toDateString());
    }

    public function test_is_holiday_returns_true_for_configured_weekend(): void
    {
        $calculator = Workday::profile('iran');

        $this->assertTrue($calculator->isWeekend('2026-06-25'));
        $this->assertTrue($calculator->isHoliday('2026-06-25'));
        $this->assertFalse($calculator->isBusinessDay('2026-06-25'));
    }

    public function test_is_weekend_works_independently(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Company holiday',
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isWeekend('2026-06-27'));
        $this->assertFalse($calculator->isWeekend('2026-06-29'));
    }

    public function test_is_calendar_holiday_does_not_include_weekend_only_dates(): void
    {
        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isWeekend('2026-06-27'));
        $this->assertFalse($calculator->isCalendarHoliday('2026-06-27'));
    }

    public function test_is_custom_holiday_does_not_include_weekend_only_dates(): void
    {
        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isWeekend('2026-06-27'));
        $this->assertFalse($calculator->isCustomHoliday('2026-06-27'));
    }

    public function test_is_extra_working_day_works(): void
    {
        $this->setProfileConfig('global', [
            'extra_working_days' => [
                '2026-06-27' => 'Compensation working day',
            ],
        ]);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isExtraWorkingDay('2026-06-27'));
        $this->assertFalse($calculator->isExtraWorkingDay('2026-06-28'));
    }

    public function test_extra_working_day_overrides_jalali_holiday(): void
    {
        $this->setProfileConfig('iran', [
            'holidays' => [
                'jalali' => [
                    '04-01' => 'Example Jalali Holiday',
                ],
            ],
            'extra_working_days' => [
                '2026-06-22' => 'Compensation working day',
            ],
        ]);

        $calculator = Workday::profile('iran');

        $this->assertTrue($calculator->isJalaliHoliday('2026-06-22'));
        $this->assertTrue($calculator->isCalendarHoliday('2026-06-22'));
        $this->assertTrue($calculator->isBusinessDay('2026-06-22'));
        $this->assertFalse($calculator->isHoliday('2026-06-22'));
    }

    public function test_is_non_working_day_aliases_is_holiday(): void
    {
        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isNonWorkingDay('2026-06-27'));
        $this->assertSame($calculator->isHoliday('2026-06-27'), $calculator->isNonWorkingDay('2026-06-27'));
    }

    public function test_next_business_day_skips_weekend(): void
    {
        $result = Workday::profile('global')->nextBusinessDay('2026-06-26');

        $this->assertSame('2026-06-29', $result->toDateString());
    }

    public function test_previous_business_day_skips_weekend(): void
    {
        $result = Workday::profile('global')->previousBusinessDay('2026-06-29');

        $this->assertSame('2026-06-26', $result->toDateString());
    }

    public function test_sub_business_days_has_direct_coverage(): void
    {
        $result = Workday::profile('global')->subBusinessDays('2026-06-29', 1);

        $this->assertSame('2026-06-26', $result->toDateString());
    }

    public function test_diff_business_days_does_not_double_count_holiday_weekend_overlap(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-28' => 'Holiday on weekend',
            ],
        ]);

        $result = Workday::profile('global')->diffBusinessDays('2026-06-26', '2026-06-29');

        $this->assertSame(1, $result);
    }

    public function test_calculate_returns_business_day_result(): void
    {
        $result = Workday::profile('global')->calculate('2026-06-26', 1);

        $this->assertSame([
            'start_date' => '2026-06-26',
            'result_date' => '2026-06-29',
            'requested_business_days' => 1,
            'calendar_days' => 3,
            'skipped_dates' => [
                '2026-06-27',
                '2026-06-28',
            ],
            'profile' => 'global',
        ], $result->toArray());
    }

    public function test_include_start_date_true_counts_start_when_business_day(): void
    {
        config()->set('workdays.include_start_date', true);

        $result = Workday::profile('global')->addBusinessDays('2026-06-26', 1);

        $this->assertSame('2026-06-26', $result->toDateString());
    }

    public function test_add_business_days_rejects_negative_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Business days must be zero or greater');

        Workday::profile('global')->addBusinessDays('2026-06-26', -1);
    }

    public function test_sub_business_days_rejects_negative_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Business days must be zero or greater');

        Workday::profile('global')->subBusinessDays('2026-06-26', -1);
    }

    public function test_date_normalizer_rejects_invalid_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date [2026-02-31]');

        DateNormalizer::toImmutable('2026-02-31');
    }

    public function test_default_profile_works(): void
    {
        $result = Workday::addBusinessDays('2026-06-24', 2);

        $this->assertSame('2026-06-28', $result->toDateString());
    }

    public function test_invalid_profile_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Workdays profile [missing] is not configured.');

        Workday::profile('missing');
    }

    public function test_invalid_weekend_config_value_throws_clear_exception(): void
    {
        config()->set('workdays.profiles.invalid-weekend', [
            'weekends' => [false],
            'holidays' => [
                'gregorian' => [],
            ],
            'custom_holidays' => [],
            'extra_working_days' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid weekend value for profile [invalid-weekend]');

        Workday::profile('invalid-weekend');
    }

    public function test_invalid_jalali_month_key_throws_exception(): void
    {
        $this->setProfileConfig('iran', [
            'holidays' => [
                'jalali' => [
                    '13-01' => 'Invalid holiday',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid jalali recurring holiday key [13-01]');

        Workday::profile('iran');
    }

    public function test_invalid_jalali_day_key_throws_exception(): void
    {
        $this->setProfileConfig('iran', [
            'holidays' => [
                'jalali' => [
                    '01-32' => 'Invalid holiday',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid jalali recurring holiday key [01-32]');

        Workday::profile('iran');
    }

    public function test_invalid_gregorian_month_key_throws_exception(): void
    {
        $this->setProfileConfig('global', [
            'holidays' => [
                'gregorian' => [
                    '13-01' => 'Invalid holiday',
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid gregorian recurring holiday key [13-01]');

        Workday::profile('global');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function setProfileConfig(string $profile, array $overrides): void
    {
        $config = config('workdays');
        $config['profiles'][$profile] = array_replace_recursive($config['profiles'][$profile], $overrides);

        config()->set('workdays', $config);
    }
}
