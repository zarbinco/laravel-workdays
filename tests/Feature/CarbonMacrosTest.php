<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\Artisan;
use Zarbinco\LaravelWorkdays\Data\DayInfo;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Support\CarbonMacroRegistrar;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class CarbonMacrosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetCarbonMacros();
        CarbonMacroRegistrar::register();
    }

    protected function tearDown(): void
    {
        $this->resetCarbonMacros();

        parent::tearDown();
    }

    public function test_carbon_macros_are_registered_by_default(): void
    {
        $this->assertTrue(Carbon::hasMacro('workdayIsBusinessDay'));
        $this->assertTrue(Carbon::hasMacro('isBusinessDay'));
    }

    public function test_carbon_immutable_macros_are_registered_by_default(): void
    {
        $this->assertTrue(CarbonImmutable::hasMacro('workdayIsBusinessDay'));
        $this->assertTrue(CarbonImmutable::hasMacro('isBusinessDay'));
    }

    public function test_carbon_macros_can_be_disabled(): void
    {
        $this->resetCarbonMacros();
        config()->set('workdays.carbon_macros.enabled', false);

        CarbonMacroRegistrar::register();

        $this->assertFalse(Carbon::hasMacro('workdayIsBusinessDay'));
        $this->assertFalse(CarbonImmutable::hasMacro('workdayIsBusinessDay'));
    }

    public function test_short_aliases_can_be_disabled(): void
    {
        $this->resetCarbonMacros();
        config()->set('workdays.carbon_macros.short_aliases', false);

        CarbonMacroRegistrar::register();

        $this->assertTrue(Carbon::hasMacro('workdayIsBusinessDay'));
        $this->assertFalse(Carbon::hasMacro('isBusinessDay'));
    }

    public function test_macros_do_not_override_existing_macro_by_default(): void
    {
        $this->resetCarbonMacros();
        Carbon::macro('workdayIsBusinessDay', fn (): string => 'existing');
        config()->set('workdays.carbon_macros.override_existing', false);

        CarbonMacroRegistrar::register();

        $this->assertSame('existing', CarbonImmutable::parse('2026-06-29')->workdayIsBusinessDay());
    }

    public function test_workday_prefixed_is_business_day_macro(): void
    {
        $this->assertTrue(CarbonImmutable::parse('2026-06-29')->workdayIsBusinessDay('global'));
        $this->assertFalse(CarbonImmutable::parse('2026-06-27')->workdayIsBusinessDay('global'));
    }

    public function test_short_is_business_day_alias_when_enabled(): void
    {
        $this->assertTrue(CarbonImmutable::parse('2026-06-29')->isBusinessDay('global'));
    }

    public function test_macro_uses_default_profile_when_profile_is_null(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Global custom holiday',
            ],
        ]);
        config()->set('workdays.default_profile', 'global');

        $this->assertFalse(CarbonImmutable::parse('2026-06-29')->workdayIsBusinessDay());
    }

    public function test_macro_uses_given_profile(): void
    {
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Global custom holiday',
            ],
        ]);
        config()->set('workdays.default_profile', 'global');

        $this->assertTrue(CarbonImmutable::parse('2026-06-29')->workdayIsBusinessDay('iran'));
    }

    public function test_add_business_days_macro_returns_expected_date(): void
    {
        $result = CarbonImmutable::parse('2026-06-26')->workdayAddBusinessDays(1, 'global');

        $this->assertSame('2026-06-29', $result->toDateString());
    }

    public function test_sub_business_days_macro_returns_expected_date(): void
    {
        $result = CarbonImmutable::parse('2026-06-29')->workdaySubBusinessDays(1, 'global');

        $this->assertSame('2026-06-26', $result->toDateString());
    }

    public function test_next_business_day_macro_returns_expected_date(): void
    {
        $result = CarbonImmutable::parse('2026-06-26')->workdayNextBusinessDay('global');

        $this->assertSame('2026-06-29', $result->toDateString());
    }

    public function test_previous_business_day_macro_returns_expected_date(): void
    {
        $result = CarbonImmutable::parse('2026-06-29')->workdayPreviousBusinessDay('global');

        $this->assertSame('2026-06-26', $result->toDateString());
    }

    public function test_diff_business_days_until_macro(): void
    {
        $diff = CarbonImmutable::parse('2026-06-26')->workdayDiffBusinessDaysUntil('2026-06-29', 'global');

        $this->assertSame(1, $diff);
    }

    public function test_explain_workday_macro_returns_day_info(): void
    {
        $info = CarbonImmutable::parse('2026-06-27')->workdayExplain('global');

        $this->assertInstanceOf(DayInfo::class, $info);
        $this->assertFalse($info->isBusinessDay);
        $this->assertTrue($info->isWeekend);
    }

    public function test_is_business_time_macro(): void
    {
        $this->setGlobalWorkingHours();

        $this->assertTrue(CarbonImmutable::parse('2026-06-29 10:00')->workdayIsBusinessTime('global'));
    }

    public function test_working_windows_macro(): void
    {
        $this->setGlobalWorkingHours();

        $windows = CarbonImmutable::parse('2026-06-29')->workdayWorkingWindows('global');

        $this->assertContainsOnlyInstancesOf(TimeWindow::class, $windows);
        $this->assertSame(['start' => '09:00', 'end' => '17:00'], $windows[0]->toArray());
    }

    public function test_add_business_minutes_macro(): void
    {
        $this->setGlobalWorkingHours();

        $result = CarbonImmutable::parse('2026-06-29 09:30')->workdayAddBusinessMinutes(90, 'global');

        $this->assertSame('2026-06-29 11:00:00', $result->toDateTimeString());
    }

    public function test_add_business_hours_macro(): void
    {
        $this->setGlobalWorkingHours();

        $result = CarbonImmutable::parse('2026-06-29 09:30')->workdayAddBusinessHours(1.5, 'global');

        $this->assertSame('2026-06-29 11:00:00', $result->toDateTimeString());
    }

    public function test_diff_business_minutes_until_macro(): void
    {
        $this->setGlobalWorkingHours();

        $diff = CarbonImmutable::parse('2026-06-29 09:00')->workdayDiffBusinessMinutesUntil('2026-06-29 11:00', 'global');

        $this->assertSame(120, $diff);
    }

    public function test_diff_business_hours_until_macro(): void
    {
        $this->setGlobalWorkingHours();

        $diff = CarbonImmutable::parse('2026-06-29 09:00')->workdayDiffBusinessHoursUntil('2026-06-29 10:30', 'global');

        $this->assertSame(1.5, $diff);
    }

    public function test_short_business_time_aliases_when_enabled(): void
    {
        $this->setGlobalWorkingHours();

        $datetime = CarbonImmutable::parse('2026-06-29 09:00');

        $this->assertTrue($datetime->isBusinessTime('global'));
        $this->assertSame('2026-06-29 10:00:00', $datetime->addBusinessHours(1, 'global')->toDateTimeString());
        $this->assertSame(60, $datetime->diffBusinessMinutesUntil('2026-06-29 10:00', 'global'));
    }

    public function test_carbon_mutable_macro_returns_carbon_instance_without_mutating_original(): void
    {
        $date = Carbon::parse('2026-06-26');
        $result = $date->workdayAddBusinessDays(1, 'global');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertNotInstanceOf(CarbonImmutable::class, $result);
        $this->assertSame('2026-06-29', $result->toDateString());
        $this->assertSame('2026-06-26', $date->toDateString());
    }

    public function test_carbon_immutable_macro_returns_carbon_immutable_instance(): void
    {
        $date = CarbonImmutable::parse('2026-06-26');
        $result = $date->workdayAddBusinessDays(1, 'global');

        $this->assertInstanceOf(CarbonImmutable::class, $result);
        $this->assertSame('2026-06-29', $result->toDateString());
        $this->assertSame('2026-06-26', $date->toDateString());
    }

    public function test_macros_work_with_illuminate_support_carbon(): void
    {
        $date = IlluminateCarbon::parse('2026-06-26');
        $result = $date->workdayAddBusinessDays(1, 'global');

        $this->assertInstanceOf(IlluminateCarbon::class, $result);
        $this->assertSame('2026-06-29', $result->toDateString());
        $this->assertSame('2026-06-26', $date->toDateString());
    }

    public function test_macros_work_with_imported_official_iran_holiday(): void
    {
        $this->loadWorkdayMigrations();
        config()->set('workdays.storage.driver', 'database');
        Artisan::call('workdays:import-iran-calendar', [
            'year' => 1405,
            '--calendar-path' => $this->createTemporaryIranOfficialCalendarFixture(),
        ]);

        $this->assertFalse(CarbonImmutable::parse('2026-03-21')->workdayIsBusinessDay('iran'));
    }

    private function resetCarbonMacros(): void
    {
        Carbon::resetMacros();
    }

    private function setGlobalWorkingHours(): void
    {
        $this->setProfileConfig('global', [
            'working_hours' => [
                'Monday' => [['09:00', '17:00']],
                'Tuesday' => [['09:00', '17:00']],
                'Wednesday' => [['09:00', '17:00']],
                'Thursday' => [['09:00', '17:00']],
                'Friday' => [['09:00', '17:00']],
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
