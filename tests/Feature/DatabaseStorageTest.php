<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DatabaseStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
    }

    public function test_migrations_create_workday_holiday_rules_table(): void
    {
        $this->assertTrue(Schema::hasTable('workday_holiday_rules'));
        $this->assertTrue(Schema::hasColumns('workday_holiday_rules', [
            'id',
            'profile',
            'calendar_type',
            'month',
            'day',
            'title',
            'is_active',
            'meta',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_migrations_create_workday_special_dates_table(): void
    {
        $this->assertTrue(Schema::hasTable('workday_special_dates'));
        $this->assertTrue(Schema::hasColumns('workday_special_dates', [
            'id',
            'profile',
            'date',
            'type',
            'title',
            'is_active',
            'meta',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_models_cast_meta_and_date_correctly(): void
    {
        $rule = WorkdayHolidayRule::create([
            'profile' => 'global',
            'calendar_type' => 'gregorian',
            'month' => 6,
            'day' => 29,
            'title' => 'Rule holiday',
            'is_active' => 1,
            'meta' => ['source' => 'test'],
        ])->refresh();

        $specialDate = WorkdaySpecialDate::create([
            'profile' => 'global',
            'date' => '2026-06-29',
            'type' => 'holiday',
            'title' => 'Special holiday',
            'is_active' => 1,
            'meta' => ['source' => 'test'],
        ])->refresh();

        $this->assertTrue($rule->is_active);
        $this->assertSame(['source' => 'test'], $rule->meta);
        $this->assertTrue($specialDate->is_active);
        $this->assertInstanceOf(CarbonImmutable::class, $specialDate->date);
        $this->assertSame(['source' => 'test'], $specialDate->meta);
    }

    public function test_config_storage_remains_default(): void
    {
        $this->assertSame('config', config('workdays.storage.driver'));
    }

    public function test_database_storage_detects_gregorian_recurring_holiday_from_database(): void
    {
        $this->setStorageDriver('database');
        $this->createRule('global', 'gregorian', 6, 29);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isGregorianHoliday('2026-06-29'));
        $this->assertTrue($calculator->isCalendarHoliday('2026-06-29'));
    }

    public function test_database_storage_detects_jalali_recurring_holiday_from_database(): void
    {
        $this->setStorageDriver('database');
        $this->createRule('global', 'jalali', 4, 1);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isJalaliHoliday('2026-06-22'));
        $this->assertTrue($calculator->isCalendarHoliday('2026-06-22'));
    }

    public function test_database_storage_detects_hijri_recurring_holiday_from_database(): void
    {
        $this->setStorageDriver('database');
        $date = (new HijriCalendarAdapter)->hijriDateToGregorian(1446, 8, 15);
        $this->createRule('global', 'hijri', 8, 15);

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isHijriHoliday($date));
        $this->assertTrue($calculator->isCalendarHoliday($date));
    }

    public function test_database_storage_detects_exact_custom_holiday_from_database(): void
    {
        $this->setStorageDriver('database');
        $this->createSpecialDate('global', '2026-06-29', 'holiday');

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isCustomHoliday('2026-06-29'));
        $this->assertTrue($calculator->isHoliday('2026-06-29'));
        $this->assertFalse($calculator->isBusinessDay('2026-06-29'));
    }

    public function test_database_storage_detects_exact_extra_working_day_from_database(): void
    {
        $this->setStorageDriver('database');
        $this->createSpecialDate('global', '2026-06-29', 'working_day');

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isExtraWorkingDay('2026-06-29'));
        $this->assertTrue($calculator->isBusinessDay('2026-06-29'));
        $this->assertFalse($calculator->isHoliday('2026-06-29'));
    }

    public function test_database_extra_working_day_overrides_weekend(): void
    {
        $this->setStorageDriver('database');
        $this->createSpecialDate('global', '2026-06-27', 'working_day');

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isWeekend('2026-06-27'));
        $this->assertTrue($calculator->isBusinessDay('2026-06-27'));
        $this->assertFalse($calculator->isHoliday('2026-06-27'));
    }

    public function test_database_extra_working_day_overrides_database_holiday(): void
    {
        $this->setStorageDriver('database');
        $this->createSpecialDate('global', '2026-06-29', 'holiday');
        $this->createSpecialDate('global', '2026-06-29', 'working_day');

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isCustomHoliday('2026-06-29'));
        $this->assertTrue($calculator->isExtraWorkingDay('2026-06-29'));
        $this->assertTrue($calculator->isBusinessDay('2026-06-29'));
        $this->assertFalse($calculator->isHoliday('2026-06-29'));
    }

    public function test_chain_storage_detects_config_holiday(): void
    {
        $this->setStorageDriver('chain');

        $this->assertTrue(Workday::profile('global')->isGregorianHoliday('2026-12-25'));
    }

    public function test_chain_storage_detects_database_holiday(): void
    {
        $this->setStorageDriver('chain');
        $this->createRule('global', 'gregorian', 6, 29);

        $this->assertTrue(Workday::profile('global')->isGregorianHoliday('2026-06-29'));
    }

    public function test_chain_storage_database_working_day_overrides_config_holiday(): void
    {
        $this->setStorageDriver('chain');
        $this->createSpecialDate('global', '2026-12-25', 'working_day');

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isGregorianHoliday('2026-12-25'));
        $this->assertTrue($calculator->isBusinessDay('2026-12-25'));
        $this->assertFalse($calculator->isHoliday('2026-12-25'));
    }

    public function test_chain_storage_config_extra_working_day_overrides_database_holiday(): void
    {
        $this->setStorageDriver('chain');
        $this->setProfileConfig('global', [
            'extra_working_days' => [
                '2026-06-29' => 'Compensation working day',
            ],
        ]);
        $this->createSpecialDate('global', '2026-06-29', 'holiday');

        $calculator = Workday::profile('global');

        $this->assertTrue($calculator->isCustomHoliday('2026-06-29'));
        $this->assertTrue($calculator->isExtraWorkingDay('2026-06-29'));
        $this->assertTrue($calculator->isBusinessDay('2026-06-29'));
        $this->assertFalse($calculator->isHoliday('2026-06-29'));
    }

    public function test_config_storage_ignores_database_holidays(): void
    {
        $this->setStorageDriver('config');
        $this->createRule('global', 'gregorian', 6, 29);
        $this->createSpecialDate('global', '2026-06-29', 'holiday');

        $calculator = Workday::profile('global');

        $this->assertFalse($calculator->isGregorianHoliday('2026-06-29'));
        $this->assertFalse($calculator->isCustomHoliday('2026-06-29'));
    }

    public function test_database_storage_ignores_config_holidays_except_weekends_profile_and_hijri_config(): void
    {
        $this->setStorageDriver('database');

        $calculator = Workday::profile('global');

        $this->assertFalse($calculator->isGregorianHoliday('2026-12-25'));
        $this->assertFalse($calculator->isCalendarHoliday('2026-12-25'));
        $this->assertTrue($calculator->isWeekend('2026-06-27'));
    }

    public function test_explain_returns_database_custom_holiday_reason(): void
    {
        $this->setStorageDriver('database');
        $this->createSpecialDate('global', '2026-06-29', 'holiday', 'Database company holiday');

        $info = Workday::profile('global')->explain('2026-06-29');
        $reason = $info->toArray()['reasons'][0];

        $this->assertFalse($info->isBusinessDay);
        $this->assertTrue($info->isCustomHoliday);
        $this->assertSame('custom_holiday', $reason['type']);
        $this->assertSame('Database company holiday', $reason['title']);
        $this->assertSame('database', $reason['source']);
        $this->assertSame('2026-06-29', $reason['key']);
    }

    public function test_explain_returns_database_extra_working_day_reason(): void
    {
        $this->setStorageDriver('database');
        $this->createSpecialDate('global', '2026-06-27', 'working_day', 'Database working day');

        $info = Workday::profile('global')->explain('2026-06-27');
        $reasons = $info->toArray()['reasons'];
        $extraWorkingDayReason = $this->reason($reasons, 'extra_working_day');
        $weekendReason = $this->reason($reasons, 'weekend');

        $this->assertTrue($info->isBusinessDay);
        $this->assertFalse($info->isNonWorkingDay);
        $this->assertTrue($info->isWeekend);
        $this->assertTrue($info->isExtraWorkingDay);
        $this->assertSame('Database working day', $extraWorkingDayReason['title']);
        $this->assertSame('database', $extraWorkingDayReason['source']);
        $this->assertTrue($weekendReason['overridden']);
        $this->assertSame('extra_working_day', $weekendReason['overridden_by']);
    }

    public function test_explain_with_chain_storage_prefers_database_detail_when_both_exist(): void
    {
        $this->setStorageDriver('chain');
        $this->setProfileConfig('global', [
            'custom_holidays' => [
                '2026-06-29' => 'Config holiday',
            ],
        ]);
        $this->createSpecialDate('global', '2026-06-29', 'holiday', 'Database holiday');

        $info = Workday::profile('global')->explain('2026-06-29');
        $reason = $this->reason($info->toArray()['reasons'], 'custom_holiday');

        $this->assertFalse($info->isBusinessDay);
        $this->assertTrue($info->isCustomHoliday);
        $this->assertSame('Database holiday', $reason['title']);
        $this->assertSame('database', $reason['source']);
    }

    private function setStorageDriver(string $driver): void
    {
        config()->set('workdays.storage.driver', $driver);
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

    private function createRule(string $profile, string $calendarType, int $month, int $day): WorkdayHolidayRule
    {
        return WorkdayHolidayRule::create([
            'profile' => $profile,
            'calendar_type' => $calendarType,
            'month' => $month,
            'day' => $day,
            'title' => 'Database holiday',
            'is_active' => true,
        ]);
    }

    private function createSpecialDate(string $profile, string $date, string $type, string $title = 'Database special date'): WorkdaySpecialDate
    {
        return WorkdaySpecialDate::create([
            'profile' => $profile,
            'date' => $date,
            'type' => $type,
            'title' => $title,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $reasons
     * @return array<string, mixed>
     */
    private function reason(array $reasons, string $type): array
    {
        foreach ($reasons as $reason) {
            if (($reason['type'] ?? null) === $type) {
                return $reason;
            }
        }

        $this->fail(sprintf('Day reason [%s] was not found.', $type));
    }
}
