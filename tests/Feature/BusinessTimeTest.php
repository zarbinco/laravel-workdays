<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class BusinessTimeTest extends TestCase
{
    public function test_is_business_time_returns_true_inside_window(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertTrue(Workday::profile('global')->isBusinessTime('2026-06-29 10:00'));
    }

    public function test_facade_business_time_methods_use_default_profile(): void
    {
        config()->set('workdays.default_profile', 'global');
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertTrue(Workday::isBusinessTime('2026-06-29 10:00'));
        $this->assertSame('2026-06-29 11:00:00', Workday::addBusinessHours('2026-06-29 10:00', 1)->toDateTimeString());
    }

    public function test_is_business_time_returns_false_before_window(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertFalse(Workday::profile('global')->isBusinessTime('2026-06-29 08:59'));
    }

    public function test_is_business_time_returns_false_at_window_end(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertFalse(Workday::profile('global')->isBusinessTime('2026-06-29 17:00'));
    }

    public function test_is_business_time_returns_false_on_weekend(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertFalse(Workday::profile('global')->isBusinessTime('2026-06-27 10:00'));
    }

    public function test_is_business_time_returns_false_on_holiday(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertFalse(Workday::profile('global')->isBusinessTime('2026-12-25 10:00'));
    }

    public function test_extra_working_day_can_be_business_time(): void
    {
        $this->setGlobalWorkingHours([
            'Saturday' => [['10:00', '12:00']],
        ]);
        $this->setProfileConfig('global', [
            'extra_working_days' => [
                '2026-06-27' => 'Weekend compensation day',
            ],
        ]);

        $this->assertTrue(Workday::profile('global')->isBusinessTime('2026-06-27 10:30'));
    }

    public function test_extra_working_day_uses_fallback_hours_when_weekday_closed(): void
    {
        $this->setGlobalWorkingHours([
            'Monday' => [['09:00', '17:00']],
            'Saturday' => [],
        ]);
        $this->setProfileConfig('global', [
            'extra_working_days' => [
                '2026-06-27' => 'Weekend compensation day',
            ],
            'extra_working_day_hours' => [
                ['10:00', '14:00'],
            ],
        ]);

        $windows = Workday::profile('global')->workingWindowsFor('2026-06-27');

        $this->assertTrue(Workday::profile('global')->isBusinessTime('2026-06-27 11:00'));
        $this->assertSame([['start' => '10:00', 'end' => '14:00']], array_map(
            static fn (TimeWindow $window): array => $window->toArray(),
            $windows,
        ));
    }

    public function test_working_windows_for_regular_business_day(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $windows = Workday::profile('global')->workingWindowsFor('2026-06-29');

        $this->assertCount(1, $windows);
        $this->assertSame(['start' => '09:00', 'end' => '17:00'], $windows[0]->toArray());
    }

    public function test_working_windows_for_half_day(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours([
            'Thursday' => [['09:00', '13:00']],
        ]));

        $windows = Workday::profile('global')->workingWindowsFor('2026-07-02');

        $this->assertCount(1, $windows);
        $this->assertSame(['start' => '09:00', 'end' => '13:00'], $windows[0]->toArray());
    }

    public function test_working_windows_for_holiday_returns_empty(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame([], Workday::profile('global')->workingWindowsFor('2026-12-25'));
    }

    public function test_next_business_time_from_before_open(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame('2026-06-29 09:00:00', Workday::profile('global')->nextBusinessTime('2026-06-29 08:00')->toDateTimeString());
    }

    public function test_next_business_time_from_after_close(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame('2026-06-30 09:00:00', Workday::profile('global')->nextBusinessTime('2026-06-29 17:00')->toDateTimeString());
    }

    public function test_next_business_time_skips_weekend(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame('2026-06-29 09:00:00', Workday::profile('global')->nextBusinessTime('2026-06-26 17:00')->toDateTimeString());
    }

    public function test_next_business_time_skips_holiday(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame('2026-12-28 09:00:00', Workday::profile('global')->nextBusinessTime('2026-12-25 10:00')->toDateTimeString());
    }

    public function test_previous_business_time_returns_last_second_before_window_end(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame('2026-06-29 16:59:59', Workday::profile('global')->previousBusinessTime('2026-06-29 17:00')->toDateTimeString());
    }

    public function test_add_business_minutes_within_same_window(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame('2026-06-29 11:00:00', Workday::profile('global')->addBusinessMinutes('2026-06-29 09:30', 90)->toDateTimeString());
    }

    public function test_add_business_minutes_across_lunch_break(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours([
            'Monday' => [
                ['09:00', '12:00'],
                ['13:00', '17:00'],
            ],
        ]));

        $this->assertSame('2026-06-29 15:00:00', Workday::profile('global')->addBusinessMinutes('2026-06-29 11:00', 180)->toDateTimeString());
    }

    public function test_add_business_hours_across_half_day(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours([
            'Thursday' => [['09:00', '13:00']],
        ]));

        $this->assertSame('2026-07-03 10:00:00', Workday::profile('global')->addBusinessHours('2026-07-02 12:00', 2)->toDateTimeString());
    }

    public function test_add_business_hours_skips_weekend(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame('2026-06-29 10:00:00', Workday::profile('global')->addBusinessHours('2026-06-26 16:00', 2)->toDateTimeString());
    }

    public function test_diff_business_minutes_same_day(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame(480, Workday::profile('global')->diffBusinessMinutes('2026-06-29 09:00', '2026-06-29 17:00'));
    }

    public function test_diff_business_minutes_across_multiple_days(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame(120, Workday::profile('global')->diffBusinessMinutes('2026-06-26 16:00', '2026-06-29 10:00'));
    }

    public function test_diff_business_minutes_returns_negative_for_reversed_range(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame(-120, Workday::profile('global')->diffBusinessMinutes('2026-06-29 10:00', '2026-06-26 16:00'));
    }

    public function test_diff_business_hours_wraps_minutes(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->assertSame(1.5, Workday::profile('global')->diffBusinessHours('2026-06-29 09:00', '2026-06-29 10:30'));
    }

    public function test_missing_working_hours_config_throws_clear_exception(): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('Working hours are not configured for profile [global].');

        Workday::profile('global')->isBusinessTime('2026-06-29 10:00');
    }

    public function test_invalid_working_hours_config_throws_clear_exception(): void
    {
        $this->setGlobalWorkingHours([
            'Monday' => [['9:00', '17:00']],
        ]);

        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('Invalid working_hours.Monday start time [9:00]');

        Workday::profile('global');
    }

    public function test_working_hours_reject_end_before_start(): void
    {
        $this->setGlobalWorkingHours([
            'Monday' => [['17:00', '09:00']],
        ]);

        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('must end after it starts');

        Workday::profile('global');
    }

    public function test_working_hours_reject_overlapping_windows(): void
    {
        $this->setGlobalWorkingHours([
            'Monday' => [
                ['09:00', '12:00'],
                ['11:00', '17:00'],
            ],
        ]);

        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('must not overlap');

        Workday::profile('global');
    }

    public function test_working_hours_reject_unknown_weekday(): void
    {
        $this->setGlobalWorkingHours([
            'Funday' => [['09:00', '17:00']],
        ]);

        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('Invalid working_hours weekday [Funday]');

        Workday::profile('global');
    }

    public function test_extra_working_day_hours_reject_invalid_shape(): void
    {
        $this->setProfileConfig('global', [
            'working_hours' => [
                'Monday' => [['09:00', '17:00']],
            ],
            'extra_working_day_hours' => [
                ['09:00'],
            ],
        ]);

        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('extra_working_day_hours window [0]');

        Workday::profile('global');
    }

    public function test_all_closed_profile_fails_safely(): void
    {
        config()->set('workdays.max_scan_days', 2);
        $this->setGlobalWorkingHours([
            'Monday' => [],
            'Tuesday' => [],
            'Wednesday' => [],
            'Thursday' => [],
            'Friday' => [],
            'Saturday' => [],
            'Sunday' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve a business time within [2] calendar days for profile [global].');

        Workday::profile('global')->nextBusinessTime('2026-06-29 08:00');
    }

    public function test_negative_business_minutes_are_rejected(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Business minutes must be zero or greater.');

        Workday::profile('global')->addBusinessMinutes('2026-06-29 09:00', -1);
    }

    public function test_business_hours_must_convert_to_whole_minutes(): void
    {
        $this->setGlobalWorkingHours($this->standardWorkingHours());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Business hours must convert to whole minutes.');

        Workday::profile('global')->addBusinessHours('2026-06-29 09:00', 1.333);
    }

    public function test_business_hours_respect_imported_official_iran_holiday(): void
    {
        $this->loadWorkdayMigrations();
        $this->setIranWorkingHours();
        config()->set('workdays.storage.driver', 'database');
        Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->createTemporaryIranOfficialCalendarFixture(),
        ]);

        $this->assertFalse(Workday::profile('iran')->isBusinessTime('2026-03-21 10:00'));
    }

    public function test_business_hours_respect_extra_working_day_over_imported_holiday(): void
    {
        $this->loadWorkdayMigrations();
        $this->setIranWorkingHours();
        config()->set('workdays.storage.driver', 'database');
        Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->createTemporaryIranOfficialCalendarFixture(),
        ]);
        WorkdaySpecialDate::create([
            'profile' => 'iran',
            'date' => '2026-03-21',
            'type' => 'working_day',
            'title' => 'Official compensation day',
            'is_active' => true,
        ]);

        $this->assertTrue(Workday::profile('iran')->isBusinessTime('2026-03-21 10:00'));
    }

    /**
     * @param  array<string, array<int, array<int, string>>>  $overrides
     * @return array<string, array<int, array<int, string>>>
     */
    private function standardWorkingHours(array $overrides = []): array
    {
        return array_replace([
            'Monday' => [['09:00', '17:00']],
            'Tuesday' => [['09:00', '17:00']],
            'Wednesday' => [['09:00', '17:00']],
            'Thursday' => [['09:00', '17:00']],
            'Friday' => [['09:00', '17:00']],
        ], $overrides);
    }

    /**
     * @param  array<string, array<int, array<int, string>>>  $workingHours
     */
    private function setGlobalWorkingHours(array $workingHours): void
    {
        $this->setProfileConfig('global', [
            'working_hours' => $workingHours,
        ]);
    }

    private function setIranWorkingHours(): void
    {
        $this->setProfileConfig('iran', [
            'working_hours' => [
                'Saturday' => [['09:00', '17:00']],
                'Sunday' => [['09:00', '17:00']],
                'Monday' => [['09:00', '17:00']],
                'Tuesday' => [['09:00', '17:00']],
                'Wednesday' => [['09:00', '17:00']],
                'Thursday' => [['09:00', '13:00']],
                'Friday' => [],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function setProfileConfig(string $profile, array $overrides): void
    {
        $config = config('workdays');
        $config['profiles'][$profile] = array_replace_recursive($config['profiles'][$profile], $overrides);

        config()->set('workdays', $config);
    }

    private function loadWorkdayMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }
}
