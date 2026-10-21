<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class DatabaseStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        config()->set('workdays.definitions.default_profile', 'global');
        config()->set('workdays.definitions.profiles', ['global' => $this->profile()]);
    }

    public function test_migration_creates_only_revision_schema_and_empty_singleton_state(): void
    {
        $this->assertTrue(Schema::hasColumns('workday_definition_state', ['id', 'active_revision_id']));
        $this->assertTrue(Schema::hasColumns('workday_definition_revisions', ['id', 'settings_json', 'settings_hash', 'schema_version', 'content_hash', 'profile_count', 'metadata_json', 'created_at']));
        $this->assertTrue(Schema::hasColumns('workday_definition_profiles', ['revision_id', 'name_hash', 'profile_name', 'position', 'definition_json', 'content_hash']));
        $this->assertFalse(Schema::hasTable('workday_special_dates'));
        $this->assertFalse(Schema::hasTable('workday_holiday_rules'));
        $this->assertNull(app(DatabaseDefinitionRepository::class)->current());
        $this->assertSame(1, $this->app['db']->table('workday_definition_state')->count());
        $this->assertSame(0, $this->app['db']->table('workday_definition_revisions')->count());
    }

    public function test_migration_down_and_up_are_explicit_and_disposable(): void
    {
        $this->assertSame(':memory:', $this->app['db']->connection()->getConfig('database'));
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
        $migration->down();
        $this->assertFalse(Schema::hasTable('workday_definition_state'));
        $this->assertFalse(Schema::hasTable('workday_definition_profiles'));
        $this->assertFalse(Schema::hasTable('workday_definition_revisions'));
        $migration->up();
        $this->assertNull(app(DatabaseDefinitionRepository::class)->current());
    }

    public function test_raw_arrays_and_metadata_replace_unrestricted_eloquent_model_writes(): void
    {
        $repository = app(DatabaseDefinitionRepository::class);
        $definitions = config('workdays.definitions');
        $published = $repository->publish($definitions, null, ['actor' => 'fixture-editor', 'reason' => 'Initial fixture']);
        $current = $repository->current();
        $this->assertSame($published->revisionId, $current->revisionId);
        $this->assertSame($definitions, $current->definitions);
        $this->assertSame(['actor' => 'fixture-editor', 'reason' => 'Initial fixture'], $current->metadata);
        $this->assertFalse(class_exists('Zarbinco\\LaravelWorkdays\\Models\\WorkdayHolidayRule'));
        $this->assertFalse(class_exists('Zarbinco\\LaravelWorkdays\\Models\\WorkdaySpecialDate'));
    }

    public function test_config_storage_remains_default(): void
    {
        $this->assertSame('config', config('workdays.driver'));
    }

    public function test_database_source_detects_gregorian_recurring_holiday(): void
    {
        $profile = $this->profile();
        $profile['holidays']['gregorian']['06-29'] = 'Recurring holiday';
        $this->publish(['global' => $profile]);
        $this->assertTrue(Workday::profile('global')->isGregorianHoliday('2026-06-29'));
        $this->assertTrue(Workday::profile('global')->isCalendarHoliday('2026-06-29'));
    }

    public function test_database_source_detects_jalali_recurring_holiday(): void
    {
        $profile = $this->profile();
        $profile['holidays']['jalali']['04-01'] = 'Jalali holiday';
        $this->publish(['global' => $profile]);
        $this->assertTrue(Workday::profile('global')->isJalaliHoliday('2026-06-22'));
        $this->assertTrue(Workday::profile('global')->isCalendarHoliday('2026-06-22'));
    }

    public function test_database_source_detects_hijri_recurring_holiday(): void
    {
        $profile = $this->profile();
        $profile['holidays']['hijri']['08-15'] = 'Hijri holiday';
        $this->publish(['global' => $profile]);
        $date = (new HijriCalendarAdapter('umm_al_qura', 0))->hijriDateToGregorian(1446, 8, 15);
        $this->assertTrue(Workday::profile('global')->isHijriHoliday($date));
        $this->assertTrue(Workday::profile('global')->isCalendarHoliday($date));
    }

    public function test_database_source_detects_exact_custom_holiday(): void
    {
        $profile = $this->profile();
        $profile['custom_holidays']['2026-06-29'] = 'Company holiday';
        $this->publish(['global' => $profile]);
        $calculator = Workday::profile('global');
        $this->assertTrue($calculator->isCustomHoliday('2026-06-29'));
        $this->assertTrue($calculator->isHoliday('2026-06-29'));
        $this->assertFalse($calculator->isBusinessDay('2026-06-29'));
    }

    public function test_database_source_detects_extra_working_day_and_overrides_weekend_and_holiday(): void
    {
        $profile = $this->profile();
        $profile['custom_holidays']['2026-07-04'] = 'Saturday holiday';
        $profile['extra_working_days']['2026-07-04'] = 'Extra Saturday';
        $this->publish(['global' => $profile]);
        $calculator = Workday::profile('global');
        $this->assertTrue($calculator->isExtraWorkingDay('2026-07-04'));
        $this->assertTrue($calculator->isWeekend('2026-07-04'));
        $this->assertTrue($calculator->isCustomHoliday('2026-07-04'));
        $this->assertTrue($calculator->isBusinessDay('2026-07-04'));
        $reasons = $calculator->explain('2026-07-04')->toArray()['reasons'];
        foreach (['weekend', 'custom_holiday'] as $type) {
            $reason = $this->reason($reasons, $type);
            $this->assertTrue($reason['overridden']);
            $this->assertSame('extra_working_day', $reason['overridden_by']);
        }
        $this->assertSame('database', $this->reason($reasons, 'extra_working_day')['source']);
    }

    public function test_database_does_not_validate_or_use_malformed_config_operational_data(): void
    {
        $this->publish(['database-only' => $this->profile()], 'database-only');
        config()->set('workdays.definitions', ['profiles' => new \stdClass, 'hijri' => false]);
        $this->assertTrue(Workday::profile('database-only')->isBusinessDay('2026-06-29'));
        $this->expectException(InvalidArgumentException::class);
        Workday::profile('config-only');
    }

    public function test_config_does_not_use_database_reopening_or_holiday_reasons(): void
    {
        $profile = $this->profile();
        $profile['extra_working_days']['2026-06-29'] = 'Database reopening';
        $this->publish(['global' => $profile]);
        config()->set('workdays.driver', 'config');
        config()->set('workdays.definitions.profiles.global.custom_holidays', ['2026-06-29' => 'Config holiday']);
        $info = Workday::profile('global')->explain('2026-06-29')->toArray();
        $this->assertFalse($info['is_business_day']);
        $this->assertSame('Config holiday', $this->reason($info['reasons'], 'custom_holiday')['title']);
        $this->assertSame('config', $this->reason($info['reasons'], 'custom_holiday')['source']);
        $this->assertSame([], array_values(array_filter($info['reasons'], static fn (array $reason): bool => $reason['type'] === 'extra_working_day')));
    }

    public function test_database_explanation_preserves_custom_holiday_title_source_and_key(): void
    {
        $profile = $this->profile();
        $profile['custom_holidays']['2026-06-29'] = 'Database company holiday';
        $this->publish(['global' => $profile]);
        $reason = $this->reason(Workday::profile('global')->explain('2026-06-29')->toArray()['reasons'], 'custom_holiday');
        $this->assertSame('Database company holiday', $reason['title']);
        $this->assertSame('database', $reason['source']);
        $this->assertSame('2026-06-29', $reason['key']);
    }

    public function test_materialized_profile_keeps_negative_and_outside_hint_dates_without_hot_path_sql(): void
    {
        $profile = $this->profile();
        $profile['custom_holidays'] = ['2026-11-03' => 'Named closure', '2026-12-02' => 'Outside closure'];
        $profile['extra_working_days'] = ['2026-11-07' => 'Reopening'];
        $this->publish(['global' => $profile]);
        $calculator = Workday::profile('global');
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        foreach (['2026-11-02' => true, '2026-11-03' => false, '2026-11-07' => true, '2026-12-02' => false] as $date => $expected) {
            $this->assertSame($expected, $calculator->isBusinessDay($date));
        }
        $this->assertSame('Named closure', $this->reason($calculator->explain('2026-11-03')->toArray()['reasons'], 'custom_holiday')['title']);
        $this->assertSame([], $connection->getQueryLog());
        $operations = array_map(static fn (string $date): array => ['id' => $date, 'operation' => 'is_business_day', 'profile' => 'global', 'date' => $date], ['2026-11-02', '2026-11-03', '2026-12-02']);
        $plain = Workday::bulkCalculate($operations)->toArray();
        $hint = Workday::bulkCalculate($operations, ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']])->toArray();
        $this->assertSame($plain, $hint);
    }

    public function test_corrupt_persisted_profile_escapes_bulk_collect_and_prefetch(): void
    {
        $this->publish(['global' => $this->profile()]);
        $this->app['db']->table('workday_definition_profiles')->where('profile_name', 'global')->update(['definition_json' => '{broken']);
        $this->expectException(DefinitionSourceException::class);
        Workday::bulkCalculate([['id' => 'corrupt', 'operation' => 'is_business_day', 'profile' => 'global', 'date' => '2026-11-02']], ['on_error' => 'collect', 'prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']]);
    }

    public function test_thirty_profiles_keep_exact_holiday_membership_without_old_bit_groups(): void
    {
        $profiles = [];
        $operations = [];
        foreach (range(0, 29) as $index) {
            $profiles['profile'.$index] = $this->profile();
            $operations[] = ['id' => 'item'.$index, 'operation' => 'is_business_day', 'profile' => 'profile'.$index, 'date' => '2026-11-02'];
        }
        $profiles['profile29']['custom_holidays']['2026-11-02'] = 'Exact closure';
        $profiles['profile26']['holidays']['gregorian']['11-02'] = 'Recurring closure';
        $this->publish($profiles, 'profile0');
        foreach ([[], ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-02']]] as $options) {
            $result = Workday::bulkCalculate($operations, $options);
            $this->assertSame(30, $result->succeeded);
            foreach ($result->items as $index => $item) {
                $this->assertSame(! in_array($index, [26, 29], true), $item->result);
            }
        }
    }

    public function test_database_profile_case_accent_and_numeric_names_are_exactly_distinct(): void
    {
        $profiles = [];
        $names = ['GLOBAL', 'global', 'cafe', 'café', '0', '00'];
        foreach ($names as $index => $name) {
            $profiles[$name] = $this->profile();
            if ($index % 2 === 1) {
                $profiles[$name]['custom_holidays']['2026-11-02'] = 'Only '.$name;
            }
        }
        $repository = $this->publish($profiles);
        $this->assertSame($profiles, $repository->current()->definitions['profiles']);
        foreach ($names as $index => $name) {
            $this->assertSame($index % 2 === 0, Workday::profile($name)->isBusinessDay('2026-11-02'));
        }
    }

    public function test_timestamp_holiday_key_cannot_create_duplicate_date_rows(): void
    {
        $repository = $this->publish(['global' => $this->profile()]);
        $before = $repository->current();
        $definitions = $before->definitions;
        $definitions['profiles']['global']['custom_holidays'] = ['2026-11-02' => 'Date only', '2026-11-02 12:00:00' => 'Invalid duplicate timestamp'];
        try {
            $repository->publish($definitions, $before->revisionId);
            $this->fail('Exact holiday keys must remain strict dates.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('custom_holidays', $error->getMessage());
        }
        $this->assertSame($before->revisionId, $repository->current()->revisionId);
        $this->assertTrue(Workday::profile('global')->isBusinessDay('2026-11-02'));
    }

    private function publish(array $profiles, string $default = 'global'): DatabaseDefinitionRepository
    {
        $definitions = config('workdays.definitions');
        $definitions['profiles'] = $profiles;
        $definitions['default_profile'] = $default;
        $repository = app(DatabaseDefinitionRepository::class);
        $repository->publish($definitions, null);
        config()->set('workdays.driver', 'database');

        return $repository;
    }

    private function profile(): array
    {
        return ['weekends' => ['Saturday', 'Sunday'], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => []];
    }

    private function reason(array $reasons, string $type): array
    {
        foreach ($reasons as $reason) {
            if ($reason['type'] === $type) {
                return $reason;
            }
        }
        $this->fail('Day reason ['.$type.'] was not found.');
    }
}
