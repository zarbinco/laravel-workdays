<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTime;
use Error;
use Hekmatinasser\Jalali\Exceptions\InvalidUnitException;
use Hekmatinasser\Verta\Verta;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use Zarbinco\LaravelWorkdays\Bulk\BulkCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\BulkCalculationItemResult;
use Zarbinco\LaravelWorkdays\Data\BulkCalculationResult;
use Zarbinco\LaravelWorkdays\Data\TimeWindow;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Holidays\DatabaseHolidayProvider;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class BulkCalculationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.default_profile', 'warehouse');
        config()->set('workdays.storage.driver', 'config');
        config()->set('workdays.include_start_date', false);
        config()->set('workdays.max_scan_days', 3660);
        config()->set('workdays.hijri', ['method' => 'mathematical', 'adjustment' => 0]);
        $warehouse = $this->profile();
        $warehouse['custom_holidays'] = ['2026-11-05' => 'Config Thursday closure'];
        $warehouse['extra_working_days'] = ['2026-11-07' => 'Config extra Saturday'];
        $warehouse['extra_working_day_hours'] = [['10:00', '14:00']];
        config()->set('workdays.profiles', [
            'warehouse' => $warehouse,
            'carrier' => array_replace($this->profile(), ['working_hours' => $this->hours([['12:00', '18:00']])]),
            'child' => ['extends' => 'warehouse', 'working_hours' => ['Thursday' => [['10:00', '15:00']]]],
            'night' => array_replace($this->profile(), ['allow_overnight_working_hours' => true,
                'working_hours' => ['Monday' => [['22:00', '06:00']]], 'custom_holidays' => ['2026-11-03' => 'Receiving closure']]),
        ]);
    }

    #[DataProvider('storageModes')]
    public function test_all_ten_operation_types_preserve_typed_standalone_results_and_serialization_with_each_prefetch_mode(string $driver): void
    {
        $this->storage($driver);
        if ($driver !== 'config') {
            $this->seedHoliday('warehouse', '2026-11-03', 'holiday');
            $this->seedHoliday('warehouse', '2026-11-07', 'working_day');
            $this->seedHoliday('warehouse', '2026-11-02', 'holiday', false);
            $this->seedHoliday('child', '2026-11-04', 'holiday');
            WorkdayHolidayRule::create(['profile' => 'warehouse', 'calendar_type' => 'gregorian', 'month' => 11, 'day' => 6, 'title' => 'Annual Friday closure', 'is_active' => true]);
        }
        $operations = $this->allOperations();
        $expected = array_map(fn (array $operation): mixed => $this->individual($operation), $operations);

        foreach ([[], ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']], ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-02']]] as $options) {
            $result = Workday::bulkCalculate($operations, $options);

            $this->assertInstanceOf(BulkCalculationResult::class, $result);
            $this->assertSame(10, $result->total);
            $this->assertSame(10, $result->succeeded);
            $this->assertSame(0, $result->failed);
            $this->assertCount(10, $result->items);
            foreach ($result->items as $index => $item) {
                $this->assertInstanceOf(BulkCalculationItemResult::class, $item);
                $this->assertSame($operations[$index]['id'], $item->id);
                $this->assertSame($operations[$index]['operation'], $item->operation);
                $this->assertTrue($item->success, json_encode($item->error));
                $this->assertNull($item->error);
                $this->assertSame(get_debug_type($expected[$index]), get_debug_type($item->result));
                $this->assertSame($this->serialize($expected[$index]), $this->serialize($item->result));
                $this->assertSame($this->serialize($expected[$index]), $item->toArray()['result']);
            }
            $this->assertSame($result->toArray(), json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public static function storageModes(): array
    {
        return [['config'], ['database'], ['chain']];
    }

    #[DataProvider('storageModes')]
    public function test_special_seasonal_overnight_and_twenty_four_hour_windows_keep_individual_semantics(string $driver): void
    {
        $this->storage($driver);
        config()->set('workdays.profiles.warehouse.special_working_hours', ['2026-11-02' => [['10:00', '13:00']]]);
        config()->set('workdays.profiles.warehouse.seasonal_working_hours', [['from' => '2026-11-03', 'through' => '2026-11-03', 'weekdays' => ['Tuesday' => [['09:00', '20:00']]]]]);
        $operations = [
            ['id' => 'special', 'operation' => 'working_windows', 'profile' => 'warehouse', 'date' => '2026-11-02'],
            ['id' => 'season', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => '2026-11-03T16:00:00.123456Z', 'minutes' => 180],
            ['id' => 'overnight', 'operation' => 'add_business_minutes', 'profile' => 'night', 'datetime' => '2026-11-02T23:59:30.123456Z', 'minutes' => 1],
            ['id' => '24-close', 'operation' => 'working_windows', 'profile' => 'night', 'date' => '2026-11-02'],
            ['id' => 'carry', 'operation' => 'working_windows', 'profile' => 'night', 'date' => '2026-11-03'],
        ];

        $this->assertParity($operations, ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-03']]);
    }

    #[DataProvider('dstParityCases')]
    public function test_dst_and_independent_offsets_preserve_the_underlying_operation_results(string $timezone, string $start, string $evaluation, int $target, array $hours): void
    {
        config()->set('workdays.profiles.dst', array_replace($this->profile(), ['weekends' => [], 'allow_overnight_working_hours' => true, 'working_hours' => $hours]));
        $operations = [
            ['id' => 'sla', 'operation' => 'evaluate_sla', 'started_at' => $start, 'evaluated_at' => $evaluation,
                'options' => ['profile' => 'dst', 'timezone' => $timezone, 'target_minutes' => $target]],
            ['id' => 'windows', 'operation' => 'working_windows', 'profile' => 'dst', 'date' => CarbonImmutable::parse($start)->setTimezone($timezone)],
            ['id' => 'arithmetic', 'operation' => 'add_business_minutes', 'profile' => 'dst', 'datetime' => CarbonImmutable::parse($start)->setTimezone($timezone), 'minutes' => $target],
        ];

        $this->assertParity($operations);
    }

    public static function dstParityCases(): array
    {
        return [
            'New York spring' => ['America/New_York', '2026-03-07T22:00:00-05:00', '2026-03-08T06:00:00-04:00', 420, ['Saturday' => [['22:00', '06:00']]]],
            'New York fall' => ['America/New_York', '2026-10-31T22:00:00-04:00', '2026-11-01T06:00:00-05:00', 540, ['Saturday' => [['22:00', '06:00']]]],
            'Santiago midnight gap' => ['America/Santiago', '2026-09-05T22:00:00-04:00', '2026-09-06T06:00:00-03:00', 420, ['Saturday' => [['22:00', '06:00']]]],
            'Tehran offsets' => ['Asia/Tehran', '2026-11-02T09:00:12.123456+03:30', '2026-11-02T11:00:12.123456+03:30', 120, ['Monday' => [['09:00', '17:00']]]],
        ];
    }

    public function test_jalali_recurrence_and_existing_gregorian_jalali_hijri_holidays_match_individual_calls(): void
    {
        config()->set('workdays.profiles.warehouse.holidays.gregorian', ['11-02' => 'Annual Gregorian closure']);
        config()->set('workdays.profiles.warehouse.holidays.jalali', ['04-01' => 'Annual Jalali closure']);
        config()->set('workdays.profiles.warehouse.holidays.hijri', ['08-15' => 'Annual Hijri closure']);
        $hijri = (new HijriCalendarAdapter('mathematical', 0))->hijriDateToGregorian(1446, 8, 15)->toDateString();
        $operations = [
            ['id' => 'gregorian', 'operation' => 'is_business_day', 'profile' => 'warehouse', 'date' => '2026-11-02'],
            ['id' => 'jalali', 'operation' => 'is_business_day', 'profile' => 'warehouse', 'date' => '2026-06-22'],
            ['id' => 'hijri', 'operation' => 'is_business_day', 'profile' => 'warehouse', 'date' => $hijri],
            ['id' => 'jalali-months', 'operation' => 'recurring_schedule', 'anchor_date' => '2025-09-22',
                'options' => ['profile' => 'warehouse', 'timezone' => 'Asia/Tehran', 'frequency' => 'monthly', 'calendar' => 'jalali', 'interval' => 1, 'count' => 8, 'adjustment' => 'next']],
        ];

        $this->assertParity($operations);
    }

    public function test_skipped_civil_dates_and_disconnected_reverse_fold_regions_keep_existing_semantics(): void
    {
        config()->set('workdays.profiles.everyday', ['weekends' => [], 'holidays' => []]);
        $plan = ['timezone' => 'America/New_York', 'profile_timezones' => ['everyday' => 'America/New_York'], 'stages' => [['id' => 'transit', 'type' => 'business_days', 'profile' => 'everyday', 'days' => 1]]];
        $operations = [
            ['id' => 'apia', 'operation' => 'recurring_schedule', 'anchor_date' => '2011-12-23', 'options' => [
                'profile' => 'everyday', 'timezone' => 'Pacific/Apia', 'frequency' => 'weekly', 'interval' => 1, 'count' => 3, 'adjustment' => 'next']],
            ['id' => 'fold', 'operation' => 'reverse_plan_delivery', 'desired_completion_at' => '2026-11-02T01:15:00-05:00', 'plan' => $plan, 'options' => ['lookback_days' => 2]],
            ['id' => 'another-reverse', 'operation' => 'reverse_plan_delivery', 'desired_completion_at' => '2026-11-02T16:00:00Z', 'plan' => $this->workPlan(), 'options' => ['lookback_days' => 1]],
        ];

        $this->assertParity($operations);
    }

    public function test_collect_mode_keeps_successes_failures_and_input_order_without_fake_results(): void
    {
        $operations = [
            $this->dayItem('first', '2026-11-02'),
            ['id' => 'negative', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => '2026-11-02T10:00:00Z', 'minutes' => -1],
            ['id' => 'missing', 'operation' => 'add_business_days', 'profile' => 'warehouse', 'date' => '2026-11-02'],
            ['id' => 'unknown-profile', 'operation' => 'is_business_day', 'profile' => 'missing', 'date' => '2026-11-02'],
            $this->dayItem('last', '2026-11-07'),
        ];

        $result = Workday::bulkCalculate($operations);

        $this->assertSame(5, $result->total);
        $this->assertSame(2, $result->succeeded);
        $this->assertSame(3, $result->failed);
        $this->assertSame(['first', 'negative', 'missing', 'unknown-profile', 'last'], array_map(static fn (BulkCalculationItemResult $item): string => $item->id, $result->items));
        $this->assertTrue($result->items[0]->success);
        $this->assertTrue($result->items[4]->success);
        foreach ([1, 2, 3] as $index) {
            $item = $result->items[$index];
            $this->assertFalse($item->success);
            $this->assertNull($item->result);
            $this->assertSame(['type', 'message'], array_keys($item->error));
            $this->assertNotEmpty($item->error['type']);
            $this->assertNotEmpty($item->error['message']);
            $this->assertArrayNotHasKey('trace', $item->error);
        }
        $expected = $this->individualFailure($operations[1]);
        $this->assertSame($expected::class, $result->items[1]->error['type']);
        $this->assertSame($expected->getMessage(), $result->items[1]->error['message']);
    }

    public function test_throw_mode_identifies_first_failed_item_and_retains_underlying_exception_context(): void
    {
        try {
            Workday::bulkCalculate([$this->dayItem('ok'), [
                'id' => 'broken-budget', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => '2026-11-02T10:00:00Z', 'minutes' => -1,
            ], $this->dayItem('unreached')], ['on_error' => 'throw']);
            $this->fail('Throw mode did not stop on an invalid item.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('broken-budget', $exception->getMessage());
            $this->assertStringContainsString('add_business_minutes', $exception->getMessage());
            $this->assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            $this->assertSame('Business minutes must be zero or greater.', $exception->getPrevious()->getMessage());
        }
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_invalid_envelope_is_rejected_before_any_database_calculation(array $operations, array $options): void
    {
        config()->set('workdays.storage.driver', 'database');
        $this->expectException(WorkdayConfigurationException::class);

        Workday::bulkCalculate($operations, $options);
    }

    public static function invalidEnvelopes(): array
    {
        $valid = ['id' => 'first', 'operation' => 'is_business_day', 'profile' => 'warehouse', 'date' => '2026-11-02'];
        $cases = [[[], []], [['named' => $valid], []], [[$valid, null], []], [[$valid, false], []], [[$valid, []], []],
            [[$valid, ['second', 'is_business_day']], []], [[$valid, $valid], []],
        ];
        foreach (['', null, false, 1, [], str_repeat('x', 129), str_repeat('ژ', 129), "bad\xFF"] as $id) {
            $cases[] = [[$valid, array_replace($valid, ['id' => $id])], []];
        }
        foreach (['unsupported', 'capture_delivery_promise', 'compare_delivery_promise', null, false] as $operation) {
            $cases[] = [[$valid, array_replace($valid, ['id' => 'second', 'operation' => $operation])], []];
        }
        $cases[] = [[$valid, array_replace($valid, ['id' => 'second']) + ['minutes' => 1]], []];
        $cases[] = [[$valid, array_replace($valid, ['id' => 'second']) + ['unknown' => true]], []];
        foreach ([['unknown' => true], ['on_error' => 'ignore'], ['on_error' => null], ['on_error' => false], ['prefetch' => null], ['prefetch' => []],
            ['prefetch' => ['from' => '2026-11-01']], ['prefetch' => ['through' => '2026-11-30']],
            ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30', 'extra' => true]],
            ['prefetch' => ['from' => 'today', 'through' => '2026-11-30']], ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-02-30']],
            ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-01']], ['prefetch' => ['from' => '2026-01-01', 'through' => '2026-03-04']],
            ['prefetch' => ['from' => '2026-11-01T00:00:00Z', 'through' => '2026-11-30']],
        ] as $options) {
            $cases[] = [[$valid], $options];
        }

        return $cases;
    }

    public function test_one_thousand_items_and_one_hundred_twenty_eight_unicode_characters_are_allowed_without_truncation(): void
    {
        $operations = [];
        foreach (range(0, 999) as $index) {
            $operations[] = $this->dayItem($index === 0 ? str_repeat('ژ', 128) : 'item-'.$index);
        }

        $result = Workday::bulkCalculate($operations);

        $this->assertSame(1000, $result->total);
        $this->assertSame(1000, $result->succeeded);
        $this->assertCount(1000, $result->items);
        $this->assertSame(str_repeat('ژ', 128), $result->items[0]->id);
        $this->assertSame('item-999', $result->items[999]->id);
    }

    public function test_one_thousand_and_one_items_are_rejected_before_any_calculation(): void
    {
        $operations = array_map(fn (int $index): array => $this->dayItem('item-'.$index), range(0, 1000));
        $this->expectException(WorkdayConfigurationException::class);

        Workday::bulkCalculate($operations);
    }

    public function test_fifty_selected_children_are_allowed_without_counting_their_shared_parent_as_an_extra_profile(): void
    {
        $profiles = ['base' => $this->profile()];
        $operations = [];
        foreach (range(0, 49) as $index) {
            $name = 'child'.$index;
            $profiles[$name] = ['extends' => 'base'];
            $operations[] = $this->dayItem('item-'.$index, profile: $name);
        }
        config()->set('workdays.profiles', $profiles);

        $result = Workday::bulkCalculate($operations);

        $this->assertSame(50, $result->succeeded);
        $this->assertSame(0, $result->failed);
    }

    public function test_fifty_one_profiles_referenced_inside_delivery_stages_exceed_the_batch_limit(): void
    {
        $profiles = [];
        foreach (range(0, 50) as $index) {
            $profiles['p'.$index] = $this->profile();
        }
        config()->set('workdays.profiles', $profiles);
        $operations = [];
        foreach ([range(0, 31), range(32, 50)] as $batch => $indexes) {
            $mapping = [];
            $stages = [];
            foreach ($indexes as $index) {
                $mapping['p'.$index] = 'UTC';
                $stages[] = ['id' => 'stage-'.$index, 'type' => 'business_days', 'profile' => 'p'.$index, 'days' => 0];
            }
            $operations[] = ['id' => 'plan-'.$batch, 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T10:00:00Z',
                'plan' => ['timezone' => 'UTC', 'profile_timezones' => $mapping, 'stages' => $stages]];
        }
        $this->expectException(WorkdayConfigurationException::class);

        Workday::bulkCalculate($operations);
    }

    public function test_unused_profile_timezone_mappings_do_not_consume_the_distinct_profile_limit(): void
    {
        $plan = $this->workPlan();
        foreach (range(1, 60) as $index) {
            $plan['profile_timezones']['unused'.$index] = 'UTC';
            config()->set('workdays.profiles.unused'.$index, ['extends' => 'missing-parent']);
        }

        $this->assertParity([['id' => 'plan', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T10:00:00Z', 'plan' => $plan]]);
    }

    public function test_malformed_domain_arguments_are_collected_and_arbitrary_payload_callbacks_are_never_executed(): void
    {
        $executed = false;
        $callback = function () use (&$executed): string {
            $executed = true;

            return '2026-11-02';
        };
        $result = Workday::bulkCalculate([
            ['id' => 'bad-date', 'operation' => 'is_business_day', 'profile' => 'warehouse', 'date' => $callback],
            ['id' => 'string-duration', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => '2026-11-02T10:00:00Z', 'minutes' => '60'],
            ['id' => 'bad-slots', 'operation' => 'delivery_slots', 'ready_at' => '2026-11-02T10:00:00Z', 'options' => 'invalid'],
            $this->dayItem('valid'),
        ]);

        $this->assertFalse($executed);
        $this->assertSame(1, $result->succeeded);
        $this->assertSame(3, $result->failed);
        $this->assertTrue($result->items[3]->success);
    }

    public function test_recognized_bounded_calculation_failure_is_collected_without_changing_its_type_or_message(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $operation = ['id' => 'long-work', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T09:00:00Z', 'plan' => $this->workPlan()];
        $operation['plan']['stages'][0]['minutes'] = 100000;
        $failure = $this->individualFailure($operation);

        $result = Workday::bulkCalculate([$operation, $this->dayItem('valid')]);

        $this->assertFalse($result->items[0]->success);
        $this->assertSame($failure::class, $result->items[0]->error['type']);
        $this->assertSame($failure->getMessage(), $result->items[0]->error['message']);
        $this->assertTrue($result->items[1]->success);
    }

    #[DataProvider('errorModes')]
    public function test_missing_tables_are_visible_infrastructure_failures_even_in_collect_mode(string $mode): void
    {
        config()->set('workdays.storage.driver', 'database');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migrations');

        Workday::bulkCalculate([$this->dayItem('cannot-read')], ['on_error' => $mode, 'prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']]);
    }

    public static function errorModes(): array
    {
        return [['collect'], ['throw']];
    }

    public function test_unexpected_internal_type_error_is_not_collected_as_a_business_failure(): void
    {
        $bulk = new BulkCalculator(static function (): never {
            throw new \TypeError('Unexpected construction programming error.');
        });
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Unexpected construction programming error.');

        $bulk->calculate([$this->dayItem('first')], ['on_error' => 'collect']);
    }

    public function test_two_hundred_items_across_five_profiles_match_standalone_with_only_grouped_prefetch_queries(): void
    {
        $this->storage('database');
        $profiles = [];
        foreach (range(0, 3) as $index) {
            $profiles['p'.$index] = $this->profile() + ['extra_working_day_hours' => [['10:00', '14:00']]];
        }
        $profiles['p4'] = ['extends' => 'p0', 'working_hours' => ['Thursday' => [['09:00', '12:00']]]];
        config()->set('workdays.profiles', $profiles);
        foreach (range(0, 4) as $index) {
            $profile = 'p'.$index;
            $this->seedHoliday($profile, '2026-11-03', 'holiday');
            $this->seedHoliday($profile, '2026-11-07', 'working_day');
            $this->seedHoliday($profile, '2026-11-02', 'holiday', false);
            WorkdayHolidayRule::create(['profile' => $profile, 'calendar_type' => 'gregorian', 'month' => 11, 'day' => 11, 'title' => 'Annual closure', 'is_active' => true]);
        }
        $operations = [];
        foreach (range(0, 199) as $index) {
            $profile = 'p'.($index % 5);
            $date = sprintf('2026-11-%02d', $index % 30 + 1);
            $common = ['id' => 'item-'.$index, 'profile' => $profile];
            $operations[] = $common + match ($index % 4) {
                0 => ['operation' => 'is_business_day', 'date' => $date],
                1 => ['operation' => 'working_windows', 'date' => $date],
                2 => ['operation' => 'add_business_minutes', 'datetime' => $date.'T09:00:00Z', 'minutes' => 60],
                3 => ['operation' => 'resolve_cutoff', 'received_at' => $date.'T13:30:00Z'],
            };
        }
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $expected = array_map(fn (array $item): mixed => $this->serialize($this->individual($item)), $operations);
        $standaloneQueries = $this->holidayQueries($connection->getQueryLog());
        $connection->flushQueryLog();

        $actual = Workday::bulkCalculate($operations, ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']]);
        $bulkQueries = $this->holidayQueries($connection->getQueryLog());
        $connection->disableQueryLog();

        $this->assertSame(200, $actual->succeeded);
        $this->assertSame(0, $actual->failed);
        $this->assertSame($expected, array_map(fn (BulkCalculationItemResult $item): mixed => $this->serialize($item->result), $actual->items));
        $this->assertGreaterThan(200, count($standaloneQueries));
        $this->assertLessThanOrEqual(2, count($bulkQueries));
        $this->assertCount(1, array_values(array_filter($bulkQueries, static fn (array $query): bool => str_contains($query['query'], 'workday_holiday_rules'))));
        $this->assertCount(1, array_values(array_filter($bulkQueries, static fn (array $query): bool => str_contains($query['query'], 'workday_special_dates'))));
    }

    public function test_prefetch_includes_model_created_rows_on_through_and_single_date_boundaries(): void
    {
        $this->storage('database');
        $this->seedHoliday('warehouse', '2026-11-30', 'holiday');
        $this->seedHoliday('warehouse', '2026-11-07', 'holiday');
        $this->seedHoliday('warehouse', '2026-11-07', 'working_day');
        foreach ([['2026-11-01', '2026-11-30', '2026-11-30', false], ['2026-11-30', '2026-11-30', '2026-11-30', false],
            ['2026-11-01', '2026-11-07', '2026-11-07', true], ['2026-11-07', '2026-11-07', '2026-11-07', true],
        ] as [$from, $through, $date, $expected]) {
            $operation = $this->dayItem('boundary', $date);
            $result = Workday::bulkCalculate([$operation], ['prefetch' => ['from' => $from, 'through' => $through]]);
            $this->assertSame($expected, $this->individual($operation));
            $this->assertSame($expected, $result->items[0]->result);
        }
    }

    public function test_prefetched_negative_dates_and_metadata_are_retained_and_outside_dates_fall_back_lazily(): void
    {
        $this->storage('database');
        $holiday = $this->seedHoliday('warehouse', '2026-11-03', 'holiday');
        $holiday->update(['title' => 'Named active closure']);
        $this->seedHoliday('warehouse', '2026-11-07', 'working_day');
        $this->seedHoliday('warehouse', '2026-11-02', 'holiday', false);
        $this->seedHoliday('warehouse', '2026-12-02', 'holiday');
        $provider = new DatabaseHolidayProvider;
        $provider->prefetch(['warehouse'], '2026-11-01', '2026-11-30');
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $this->assertFalse($provider->hasCustomHoliday('warehouse', CarbonImmutable::parse('2026-11-02', 'UTC')));
        $this->assertFalse($provider->hasExtraWorkingDay('warehouse', CarbonImmutable::parse('2026-11-02', 'UTC')));
        $this->assertSame('Named active closure', $provider->customHoliday('warehouse', CarbonImmutable::parse('2026-11-03', 'UTC'))->name);
        $this->assertSame('database', $provider->customHoliday('warehouse', CarbonImmutable::parse('2026-11-03', 'UTC'))->source);
        $this->assertTrue($provider->hasExtraWorkingDay('warehouse', CarbonImmutable::parse('2026-11-07', 'UTC')));
        $this->assertCount(0, $this->holidayQueries($connection->getQueryLog()));
        $this->assertTrue($provider->hasCustomHoliday('warehouse', CarbonImmutable::parse('2026-12-02', 'UTC')));
        $this->assertTrue($provider->hasCustomHoliday('warehouse', CarbonImmutable::parse('2026-12-02', 'UTC')));
        $this->assertCount(1, $this->holidayQueries($connection->getQueryLog()));
    }

    public function test_narrow_prefetch_falls_back_and_new_batch_observes_calendar_changes(): void
    {
        $this->storage('database');
        $this->seedHoliday('warehouse', '2026-12-02', 'holiday');
        $operations = [$this->dayItem('empty', '2026-11-02'), $this->dayItem('outside', '2026-12-02')];
        $options = ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-03']];

        $before = Workday::bulkCalculate($operations, $options);
        $this->seedHoliday('warehouse', '2026-11-02', 'holiday');
        $after = Workday::bulkCalculate($operations, $options);

        $this->assertTrue($before->items[0]->result);
        $this->assertFalse($before->items[1]->result);
        $this->assertFalse($after->items[0]->result);
        $this->assertFalse($after->items[1]->result);
    }

    public function test_unused_malformed_special_date_in_prefetched_interval_is_validated_only_when_accessed(): void
    {
        $this->storage('database');
        $this->app['db']->table('workday_special_dates')->insert(['profile' => 'warehouse', 'date' => '2026-11-20', 'type' => 'corrupt-type', 'title' => 'Unused bad row', 'is_active' => true]);
        $options = ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']];

        $unused = Workday::bulkCalculate([$this->dayItem('valid', '2026-11-02')], $options);
        $accessed = Workday::bulkCalculate([$this->dayItem('bad-row', '2026-11-20'), $this->dayItem('valid', '2026-11-02')], $options);

        $this->assertSame(1, $unused->succeeded);
        $this->assertFalse($accessed->items[0]->success);
        $this->assertStringContainsString('corrupt-type', $accessed->items[0]->error['message']);
        $this->assertTrue($accessed->items[1]->success);
    }

    public function test_malformed_recurring_rule_for_another_selected_profile_does_not_abort_unrelated_valid_items(): void
    {
        $this->storage('database');
        config()->set('workdays.profiles.bad', $this->profile());
        WorkdayHolidayRule::create(['profile' => 'bad', 'calendar_type' => 'unsupported', 'month' => 1, 'day' => 1, 'title' => 'Bad active rule', 'is_active' => true]);
        WorkdayHolidayRule::create(['profile' => 'warehouse', 'calendar_type' => 'also-unsupported', 'month' => 1, 'day' => 1, 'title' => 'Inactive bad rule', 'is_active' => false]);

        $result = Workday::bulkCalculate([$this->dayItem('valid'), $this->dayItem('bad-profile', profile: 'bad'), $this->dayItem('still-valid')],
            ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']]);

        $this->assertTrue($result->items[0]->success);
        $this->assertFalse($result->items[1]->success);
        $this->assertStringContainsString('unsupported', $result->items[1]->error['message']);
        $this->assertTrue($result->items[2]->success);
    }

    public function test_config_only_prefetch_uses_no_database_queries_and_sixty_two_inclusive_labels_are_allowed(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $result = Workday::bulkCalculate([$this->dayItem('config')], ['prefetch' => ['from' => '2026-01-01', 'through' => '2026-03-03']]);

        $this->assertSame(1, $result->succeeded);
        $this->assertCount(0, $this->holidayQueries($connection->getQueryLog()));
        $this->assertSame(1, config('workdays.max_scan_days'));
    }

    public function test_global_horizon_snapshot_survives_configuration_changes_during_first_database_read(): void
    {
        $this->storage('database');
        config()->set('workdays.max_scan_days', 1);
        $dayPlan = ['timezone' => 'UTC', 'profile_timezones' => ['carrier' => 'UTC'], 'stages' => [['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 2]]];
        $operations = [
            $this->dayItem('prime'),
            ['id' => 'plan', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T10:00:00Z', 'plan' => $dayPlan],
            ['id' => 'slots', 'operation' => 'delivery_slots', 'ready_at' => '2026-11-02T10:00:00Z', 'options' => [
                'profile' => 'warehouse', 'timezone' => 'UTC', 'days' => 3, 'duration_minutes' => 60, 'step_minutes' => 60, 'max_slots' => 10]],
            ['id' => 'recurrence', 'operation' => 'recurring_schedule', 'anchor_date' => '2026-11-02', 'options' => [
                'profile' => 'warehouse', 'timezone' => 'UTC', 'frequency' => 'weekly', 'interval' => 1, 'count' => 2, 'adjustment' => 'next']],
            ['id' => 'sla', 'operation' => 'evaluate_sla', 'started_at' => '2026-11-02T09:00:00Z', 'evaluated_at' => '2026-11-04T09:00:00Z',
                'options' => ['profile' => 'warehouse', 'timezone' => 'UTC', 'target_minutes' => 60]],
            ['id' => 'reverse', 'operation' => 'reverse_plan_delivery', 'desired_completion_at' => '2026-11-04T16:00:00Z', 'plan' => $dayPlan, 'options' => ['lookback_days' => 2]],
        ];
        $changed = false;
        $this->app['db']->listen(static function (QueryExecuted $query) use (&$changed): void {
            if (! $changed && str_contains($query->sql, 'workday_')) {
                $changed = true;
                config()->set('workdays.max_scan_days', 31);
            }
        });

        $oldSnapshot = Workday::bulkCalculate($operations);
        $newSnapshot = Workday::bulkCalculate($operations);

        $this->assertTrue($changed);
        $this->assertSame(1, $oldSnapshot->succeeded);
        $this->assertSame(5, $oldSnapshot->failed);
        $this->assertSame(6, $newSnapshot->succeeded);
        $this->assertSame(0, $newSnapshot->failed);
        $this->assertSame(31, config('workdays.max_scan_days'));
    }

    public function test_profile_include_start_and_hijri_settings_are_frozen_for_one_batch_and_refreshed_for_the_next(): void
    {
        $this->storage('chain');
        config()->set('workdays.profiles.calendar', ['weekends' => [], 'holidays' => ['hijri' => ['08-15' => 'Hijri closure']]]);
        $hijri = (new HijriCalendarAdapter('mathematical', 0))->hijriDateToGregorian(1446, 8, 15)->toDateString();
        $operations = [$this->dayItem('prime'),
            ['id' => 'windows', 'operation' => 'working_windows', 'profile' => 'warehouse', 'date' => '2026-11-02'],
            ['id' => 'days', 'operation' => 'add_business_days', 'profile' => 'carrier', 'date' => '2026-11-02', 'days' => 1],
            $this->dayItem('hijri', $hijri, 'calendar'),
        ];
        $original = array_map(fn (array $item): mixed => $this->serialize($this->individual($item)), $operations);
        $changed = false;
        $this->app['db']->listen(static function (QueryExecuted $query) use (&$changed): void {
            if (! $changed && str_contains($query->sql, 'workday_')) {
                $changed = true;
                config()->set('workdays.include_start_date', true);
                config()->set('workdays.hijri.adjustment', 1);
                config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-02' => 'New closure']);
                config()->set('workdays.profiles.warehouse.working_hours.Monday', [['10:00', '11:00']]);
            }
        });

        $before = Workday::bulkCalculate($operations);
        $after = Workday::bulkCalculate($operations);

        $this->assertSame($original, array_map(fn (BulkCalculationItemResult $item): mixed => $this->serialize($item->result), $before->items));
        $this->assertFalse($after->items[0]->result);
        $this->assertSame([], $after->items[1]->result);
        $this->assertSame('2026-11-02', $after->items[2]->result->toDateString());
        $this->assertTrue($after->items[3]->result);
    }

    public function test_datetime_inputs_are_detached_before_database_callbacks_can_change_them(): void
    {
        $this->storage('database');
        $input = new DateTime('2026-11-02T10:00:12.123456Z');
        $changed = false;
        $this->app['db']->listen(static function (QueryExecuted $query) use (&$changed, $input): void {
            if (! $changed && str_contains($query->sql, 'workday_')) {
                $changed = true;
                $input->modify('+1 month');
            }
        });

        $result = Workday::bulkCalculate([$this->dayItem('prime'), [
            'id' => 'copied-input', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => $input, 'minutes' => 60,
        ]]);

        $this->assertTrue($changed);
        $this->assertSame('2026-12-02', $input->format('Y-m-d'));
        $this->assertSame('2026-11-02T11:00:12.123456+00:00', $this->serialize($result->items[1]->result));
    }

    public function test_operation_local_calculator_and_provider_construction_counts_do_not_grow_per_item(): void
    {
        $events = [];
        $bulk = new BulkCalculator(static function (string $kind, ?string $identity, ?int $limit) use (&$events): void {
            $events[] = [$kind, $identity, $limit];
        });
        $operations = array_map(fn (int $index): array => $this->dayItem('item-'.$index), range(0, 199));

        $first = $bulk->calculate($operations);
        $firstEvents = $events;
        $events = [];
        $second = $bulk->calculate($operations);

        $this->assertSame(200, $first->succeeded);
        $this->assertSame(200, $second->succeeded);
        $this->assertSame($firstEvents, $events);
        $this->assertCount(1, array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'calculator')));
        $this->assertCount(1, array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'provider')));
        $this->assertContains(['calculator', 'warehouse', 3660], $events);
        $this->assertContains(['provider', 'config', null], $events);
    }

    public function test_result_order_types_and_arrays_are_readonly_without_mutating_caller_operations(): void
    {
        $operations = [$this->dayItem('boolean'), ['id' => 'windows', 'operation' => 'working_windows', 'profile' => 'warehouse', 'date' => '2026-11-02']];
        $original = $operations;

        $result = Workday::bulkCalculate($operations);

        $this->assertSame($original, $operations);
        $this->assertSame(['total', 'succeeded', 'failed', 'items'], array_keys($result->toArray()));
        $this->assertSame(['id', 'operation', 'success', 'result', 'error'], array_keys($result->items[0]->toArray()));
        $this->assertTrue($result->items[0]->result);
        $this->assertIsArray($result->items[1]->result);
        $this->assertInstanceOf(TimeWindow::class, $result->items[1]->result[0]);
        $this->expectException(Error::class);

        $result->items[] = $result->items[0];
    }

    public function test_individual_item_properties_are_immutable(): void
    {
        $result = Workday::bulkCalculate([$this->dayItem('one')]);
        $this->expectException(Error::class);

        $result->items[0]->success = false;
    }

    public function test_bulk_calls_do_not_change_phase_six_snapshot_schema_or_frozen_original_result(): void
    {
        $plan = $this->workPlan();
        $snapshot = Workday::captureDeliveryPromise('2026-11-02T10:00:00Z', $plan, ['horizon_days' => 1]);
        $before = $snapshot->toArray();

        Workday::bulkCalculate($this->allOperations());

        $this->assertSame($before, $snapshot->toArray());
        $this->assertFalse(Workday::compareDeliveryPromise($snapshot)->versionChanged);
        $this->assertArrayNotHasKey('batch', $snapshot->toArray());
    }

    public function test_inherited_profiles_keep_config_rules_cutoff_and_child_database_identity_without_routing(): void
    {
        $this->storage('chain');
        $this->seedHoliday('warehouse', '2026-11-02', 'holiday');
        config()->set('workdays.profile_routing', ['invalid' => true]);
        $operations = [$this->dayItem('parent', '2026-11-02'), $this->dayItem('child', '2026-11-02', 'child'), $this->dayItem('inherited-holiday', '2026-11-05', 'child'),
            ['id' => 'override', 'operation' => 'working_windows', 'profile' => 'child', 'date' => '2026-11-12'],
            ['id' => 'cutoff', 'operation' => 'resolve_cutoff', 'profile' => 'child', 'received_at' => '2026-11-02T13:00:00Z'],
        ];

        $this->assertParity($operations, ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']]);
        $actual = Workday::bulkCalculate($operations);
        $this->assertFalse($actual->items[0]->result);
        $this->assertTrue($actual->items[1]->result);
        $this->assertFalse($actual->items[2]->result);
        $this->assertSame([['start' => '10:00', 'end' => '15:00']], $actual->items[3]->toArray()['result']);
        $this->assertSame('child', $actual->items[4]->result->profile);
    }

    public function test_calculator_cache_keeps_actual_scan_limit_variants_distinct(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $events = [];
        $bulk = new BulkCalculator(static function (string $kind, ?string $identity, ?int $limit) use (&$events): void {
            if ($kind === 'calculator') {
                $events[] = [$identity, $limit];
            }
        });
        $plan = $this->workPlan();
        $plan['profile_timezones']['warehouse'] = 'Pacific/Kiritimati';
        $plan['stages'][0]['minutes'] = 0;

        $result = $bulk->calculate([$this->dayItem('scan-one'),
            ['id' => 'scan-two', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T00:01:00Z', 'plan' => $plan],
            ['id' => 'still-scan-one', 'operation' => 'add_business_days', 'profile' => 'warehouse', 'date' => '2026-11-02', 'days' => 2],
        ]);

        $this->assertTrue($result->items[0]->success);
        $this->assertTrue($result->items[1]->success);
        $this->assertFalse($result->items[2]->success);
        $this->assertContains(['warehouse', 1], $events);
        $this->assertContains(['warehouse', 2], $events);
        $this->assertCount(2, $events);
    }

    #[DataProvider('unexpectedConstructionErrors')]
    public function test_unknown_runtime_infrastructure_and_internal_errors_remain_visible(string $class): void
    {
        $bulk = new BulkCalculator(static function () use ($class): never {
            throw new $class('Unexpected internal construction failure.');
        });
        $this->expectException($class);
        $this->expectExceptionMessage('Unexpected internal construction failure.');

        $bulk->calculate([$this->dayItem('first')], ['on_error' => 'collect']);
    }

    public static function unexpectedConstructionErrors(): array
    {
        return [[RuntimeException::class], [\PDOException::class], [Error::class], [InvalidArgumentException::class]];
    }

    public function test_numeric_string_profile_name_zero_is_preserved_with_database_prefetch(): void
    {
        $this->storage('database');
        config()->set('workdays.profiles', ['0' => $this->profile()]);
        $this->seedHoliday('0', '2026-11-02', 'holiday');
        $operation = $this->dayItem('zero', profile: '0');

        $this->assertParity([$operation], ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-02']]);
        $this->assertFalse(Workday::bulkCalculate([$operation])->items[0]->result);
    }

    public function test_prefetch_membership_handles_profiles_beyond_the_first_twenty_five_bit_group(): void
    {
        $this->storage('database');
        $profiles = [];
        $operations = [];
        foreach (range(0, 29) as $index) {
            $name = 'profile'.$index;
            $profiles[$name] = $this->profile();
            $operations[] = $this->dayItem('item-'.$index, profile: $name);
        }
        config()->set('workdays.profiles', $profiles);
        $this->seedHoliday('profile29', '2026-11-02', 'holiday');
        WorkdayHolidayRule::create(['profile' => 'profile26', 'calendar_type' => 'gregorian', 'month' => 11, 'day' => 2, 'title' => 'Second membership group', 'is_active' => true]);

        $this->assertParity($operations, ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-02']]);
        $result = Workday::bulkCalculate($operations, ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-02']]);
        $this->assertFalse($result->items[26]->result);
        $this->assertFalse($result->items[29]->result);
        $this->assertTrue($result->items[28]->result);
    }

    public function test_sqlite_case_insensitive_profile_equality_maps_prefetched_rows_to_both_configured_aliases(): void
    {
        $this->storage('database');
        $connection = $this->app['db']->connection();
        $this->assertSame(':memory:', $connection->getConfig('database'));
        $connection->statement('DROP TABLE workday_holiday_rules');
        $connection->statement('DROP TABLE workday_special_dates');
        $connection->statement('CREATE TABLE workday_holiday_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, profile TEXT COLLATE NOCASE NOT NULL, calendar_type TEXT NOT NULL, month INTEGER NOT NULL, day INTEGER NOT NULL, title TEXT, is_active INTEGER NOT NULL DEFAULT 1, meta TEXT, created_at TEXT, updated_at TEXT)');
        $connection->statement('CREATE TABLE workday_special_dates (id INTEGER PRIMARY KEY AUTOINCREMENT, profile TEXT COLLATE NOCASE NOT NULL, date DATE NOT NULL, type TEXT NOT NULL, title TEXT, is_active INTEGER NOT NULL DEFAULT 1, meta TEXT, created_at TEXT, updated_at TEXT)');
        config()->set('workdays.profiles', ['WAREHOUSE' => $this->profile(), 'warehouse' => $this->profile()]);
        $this->seedHoliday('warehouse', '2026-11-02', 'holiday');
        WorkdayHolidayRule::create(['profile' => 'warehouse', 'calendar_type' => 'gregorian', 'month' => 11, 'day' => 3, 'title' => 'Case-insensitive rule', 'is_active' => true]);
        $operations = [$this->dayItem('upper-holiday', profile: 'WAREHOUSE'), $this->dayItem('lower-holiday'),
            $this->dayItem('upper-rule', '2026-11-03', 'WAREHOUSE'), $this->dayItem('lower-rule', '2026-11-03')];

        $this->assertParity($operations, ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-03']]);
        $actual = Workday::bulkCalculate($operations, ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-03']]);
        $this->assertSame([false, false, false, false], array_map(static fn (BulkCalculationItemResult $item): bool => $item->result, $actual->items));
    }

    public function test_duplicate_raw_timestamp_rows_keep_existing_first_match_in_prefetch_and_lazy_fallback(): void
    {
        $this->storage('database');
        $first = $this->seedHoliday('warehouse', '2026-11-02', 'holiday');
        $first->update(['title' => 'First matching row']);
        $this->app['db']->table('workday_special_dates')->insert(['profile' => 'warehouse', 'date' => '2026-11-02 12:00:00', 'type' => 'holiday', 'title' => 'Later matching row', 'is_active' => true]);
        $this->app['db']->table('workday_special_dates')->insert(['profile' => 'warehouse', 'date' => '2026-11-02', 'type' => 'holiday', 'title' => 'Date-only matching row', 'is_active' => true]);
        $date = CarbonImmutable::parse('2026-11-02', 'UTC');
        $lazy = new DatabaseHolidayProvider;
        $covered = new DatabaseHolidayProvider;
        $covered->prefetch(['warehouse'], '2026-11-02', '2026-11-02');
        $outside = new DatabaseHolidayProvider;
        $outside->prefetch(['warehouse'], '2026-11-01', '2026-11-01');
        $expected = $lazy->customHoliday('warehouse', $date);
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $this->assertContains($expected->name, ['First matching row', 'Later matching row', 'Date-only matching row']);
        $this->assertSame($expected->name, $covered->customHoliday('warehouse', $date)->name);
        $this->assertSame($expected->name, $covered->customHoliday('warehouse', $date)->name);
        $this->assertCount(1, $this->holidayQueries($connection->getQueryLog()));
        $this->assertSame($expected->name, $outside->customHoliday('warehouse', $date)->name);
        $this->assertSame('database', $outside->customHoliday('warehouse', $date)->source);
        $this->assertCount(2, $this->holidayQueries($connection->getQueryLog()));
    }

    public function test_collected_error_messages_are_bounded_valid_utf8_without_traces(): void
    {
        $result = Workday::bulkCalculate([$this->dayItem('invalid-utf8', profile: "missing\xFF"), $this->dayItem('long-error', profile: str_repeat('نام', 1000))]);

        foreach ($result->items as $item) {
            $this->assertFalse($item->success);
            $this->assertSame(1, preg_match('//u', $item->error['message']));
            $this->assertLessThanOrEqual(2048, mb_strlen($item->error['message'], 'UTF-8'));
            $this->assertSame(['type', 'message'], array_keys($item->error));
        }
        json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        $this->addToAssertionCount(1);
    }

    public function test_application_invalid_argument_wrapped_by_an_advanced_engine_still_escapes_collect_mode(): void
    {
        $bulk = new BulkCalculator(static function (string $kind): void {
            if ($kind === 'calculator') {
                throw new InvalidArgumentException('Application callback infrastructure failed.');
            }
        });
        try {
            $bulk->calculate([['id' => 'advanced-engine', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T10:00:00Z', 'plan' => $this->workPlan()]], ['on_error' => 'collect']);
            $this->fail('An application infrastructure exception was collected as an item failure.');
        } catch (InvalidArgumentException $exception) {
            $leaf = $exception;
            while ($leaf->getPrevious() !== null) {
                $leaf = $leaf->getPrevious();
            }
            $this->assertSame(InvalidArgumentException::class, $leaf::class);
            $this->assertSame('Application callback infrastructure failed.', $leaf->getMessage());
        }
    }

    #[DataProvider('advancedInvalidHorizonCases')]
    public function test_advanced_engine_error_type_message_and_precedence_match_standalone_with_invalid_global_horizon(string $operation, mixed $limit, string $domain): void
    {
        config()->set('workdays.max_scan_days', $limit);
        $item = array_values(array_filter($this->allOperations(), static fn (array $candidate): bool => $candidate['operation'] === $operation))[0];
        $item['id'] = 'invalid-horizon-'.$operation;
        if ($domain === 'malformed options') {
            switch ($operation) {
                case 'plan_delivery': $item['plan']['stages'][0]['minutes'] = -1;
                    break;
                case 'delivery_slots': $item['options']['duration_minutes'] = 0;
                    break;
                case 'recurring_schedule': $item['options']['interval'] = 0;
                    break;
                case 'evaluate_sla': $item['options']['target_minutes'] = 0;
                    break;
                case 'reverse_plan_delivery': $item['plan']['stages'][0]['id'] = '';
                    break;
            }
        } elseif ($domain === 'malformed date') {
            $field = match ($operation) {
                'plan_delivery' => 'received_at', 'delivery_slots' => 'ready_at',
                'recurring_schedule' => 'anchor_date', 'evaluate_sla' => 'started_at',
                'reverse_plan_delivery' => 'desired_completion_at',
            };
            $item[$field] = 'tomorrow';
        }
        $expected = $this->individualFailure($item);

        $collected = Workday::bulkCalculate([$item], ['on_error' => 'collect']);

        $this->assertSame(0, $collected->succeeded);
        $this->assertSame(1, $collected->failed);
        $this->assertFalse($collected->items[0]->success);
        $this->assertNull($collected->items[0]->result);
        $this->assertSame($expected::class, $collected->items[0]->error['type']);
        $this->assertSame($expected->getMessage(), $collected->items[0]->error['message']);
        $thrown = null;
        try {
            Workday::bulkCalculate([$item], ['on_error' => 'throw']);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }
        $this->assertNotNull($thrown);
        $this->assertStringContainsString($item['id'], $thrown->getMessage());
        $this->assertStringContainsString($operation, $thrown->getMessage());
        $this->assertSame($expected::class, $thrown->getPrevious()::class);
        $this->assertSame($expected->getMessage(), $thrown->getPrevious()->getMessage());
    }

    public static function advancedInvalidHorizonCases(): array
    {
        $cases = [];
        foreach (['plan_delivery', 'delivery_slots', 'recurring_schedule', 'evaluate_sla', 'reverse_plan_delivery'] as $operation) {
            foreach ([0, '5', null, false] as $index => $limit) {
                foreach (['valid domain', 'malformed options', 'malformed date'] as $domain) {
                    $cases[$operation.' limit'.$index.' '.$domain] = [$operation, $limit, $domain];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('combinedBasicConfigurationFailures')]
    public function test_basic_calculator_configuration_error_precedence_matches_individual_api(string $kind): void
    {
        config()->set('workdays.max_scan_days', 0);
        $profile = $kind === 'unknown profile' ? 'missing' : 'warehouse';
        if ($kind !== 'unknown profile') {
            config()->set('workdays.include_start_date', []);
        }
        if ($kind === 'invalid profile before include') {
            config()->set('workdays.profiles.warehouse.weekends', 'invalid');
        }
        $item = $this->dayItem('combined-errors', profile: $profile);
        $expected = $this->individualFailure($item);

        $actual = Workday::bulkCalculate([$item]);

        $this->assertFalse($actual->items[0]->success);
        $this->assertSame($expected::class, $actual->items[0]->error['type']);
        $this->assertSame($expected->getMessage(), $actual->items[0]->error['message']);
    }

    public static function combinedBasicConfigurationFailures(): array
    {
        return [['unknown profile'], ['invalid include before horizon'], ['invalid profile before include']];
    }

    #[DataProvider('allOperationNames')]
    public function test_explicit_null_profile_configuration_preserves_each_individual_error_and_throw_cause(string $operation, bool $malformedReverse = false): void
    {
        config()->set('workdays.profiles', null);
        $item = array_values(array_filter($this->allOperations(), static fn (array $candidate): bool => $candidate['operation'] === $operation))[0];
        $item['id'] = 'null-profiles-'.$operation;
        if ($malformedReverse) {
            $item['desired_completion_at'] = 'tomorrow';
            $item['plan'] = ['timezone' => 'Mars/Olympus'];
            $item['options'] = ['lookback_days' => '1'];
        }
        $expected = $this->individualFailure($item);

        $collected = Workday::bulkCalculate([$item]);

        $this->assertSame(0, $collected->succeeded);
        $this->assertSame(1, $collected->failed);
        $this->assertFalse($collected->items[0]->success);
        $this->assertNull($collected->items[0]->result);
        $this->assertSame($expected::class, $collected->items[0]->error['type']);
        $this->assertSame($expected->getMessage(), $collected->items[0]->error['message']);
        $thrown = null;
        try {
            Workday::bulkCalculate([$item], ['on_error' => 'throw']);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }
        $this->assertNotNull($thrown);
        $this->assertStringContainsString($item['id'], $thrown->getMessage());
        $this->assertStringContainsString($operation, $thrown->getMessage());
        $this->assertSame($expected::class, $thrown->getPrevious()::class);
        $this->assertSame($expected->getMessage(), $thrown->getPrevious()->getMessage());
    }

    public static function allOperationNames(): array
    {
        $cases = array_map(static fn (string $operation): array => [$operation], [
            'is_business_day', 'working_windows', 'add_business_days', 'add_business_minutes', 'resolve_cutoff',
            'plan_delivery', 'delivery_slots', 'recurring_schedule', 'evaluate_sla', 'reverse_plan_delivery',
        ]);
        $cases[] = ['reverse_plan_delivery', true];

        return $cases;
    }

    #[DataProvider('installedMalformedCarbonInputs')]
    public function test_installed_package_collects_real_carbon_input_errors_and_continues(string $operation, string $field): void
    {
        $item = ['id' => 'invalid-input', 'operation' => $operation, 'profile' => 'warehouse', $field => 'not-a-valid-datetime'];
        if ($operation === 'add_business_days') {
            $item['days'] = 1;
        }
        if ($operation === 'add_business_minutes') {
            $item['minutes'] = 1;
        }
        $expected = $this->individualFailure($item);
        $this->assertInstanceOf(InvalidArgumentException::class, $expected);

        $actual = $this->installedBulkCalculation([$item, $this->dayItem('valid-after-invalid')]);

        $this->assertNull($actual['exception'], json_encode($actual['exception']));
        $result = $actual['result'];
        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['succeeded']);
        $this->assertSame('invalid-input', $result['items'][0]['id']);
        $this->assertFalse($result['items'][0]['success']);
        $this->assertNull($result['items'][0]['result']);
        $this->assertSame(['type' => $expected::class, 'message' => $expected->getMessage()], $result['items'][0]['error']);
        $this->assertSame('valid-after-invalid', $result['items'][1]['id']);
        $this->assertTrue($result['items'][1]['success']);
        $this->assertTrue($result['items'][1]['result']);
        $this->assertNull($result['items'][1]['error']);
    }

    public static function installedMalformedCarbonInputs(): array
    {
        return [
            'business date' => ['is_business_day', 'date'],
            'working windows date' => ['working_windows', 'date'],
            'business-day arithmetic date' => ['add_business_days', 'date'],
            'business-minute arithmetic datetime' => ['add_business_minutes', 'datetime'],
        ];
    }

    #[DataProvider('installedMalformedCarbonInputs')]
    public function test_installed_package_throw_mode_retains_the_original_carbon_input_error(string $operation, string $field): void
    {
        $item = ['id' => 'invalid-input', 'operation' => $operation, 'profile' => 'warehouse', $field => 'not-a-valid-datetime'];
        if ($operation === 'add_business_days') {
            $item['days'] = 1;
        }
        if ($operation === 'add_business_minutes') {
            $item['minutes'] = 1;
        }
        $expected = $this->individualFailure($item);

        $actual = $this->installedBulkCalculation([$item, $this->dayItem('after-error')], ['on_error' => 'throw']);

        $this->assertNull($actual['result']);
        $this->assertSame(RuntimeException::class, $actual['exception'][0]['type']);
        $this->assertStringContainsString('invalid-input', $actual['exception'][0]['message']);
        $this->assertStringContainsString($operation, $actual['exception'][0]['message']);
        $this->assertSame(['type' => $expected::class, 'message' => $expected->getMessage()], $actual['exception'][1]);
    }

    public function test_installed_package_collects_a_real_hijri_range_failure_and_preserves_the_next_item(): void
    {
        config()->set('workdays.hijri.method', 'umm_al_qura');
        config()->set('workdays.profiles.warehouse.weekends', []);
        config()->set('workdays.profiles.warehouse.holidays.hijri', ['01-01' => 'Hijri holiday']);
        $item = $this->dayItem('out-of-calendar-range', '2200-01-01');
        $expected = $this->individualFailure($item);
        $this->assertInstanceOf(InvalidArgumentException::class, $expected);
        $this->assertInstanceOf(\Exception::class, $expected->getPrevious());

        $actual = $this->installedBulkCalculation([$item, $this->dayItem('inside-calendar-range')]);

        $this->assertNull($actual['exception'], json_encode($actual['exception']));
        $this->assertSame(1, $actual['result']['failed']);
        $this->assertSame(1, $actual['result']['succeeded']);
        $this->assertSame($expected::class, $actual['result']['items'][0]['error']['type']);
        $this->assertStringStartsWith('Unable to convert Gregorian date [2200-01-01] to Hijri using method [umm_al_qura]: Gregorian date must be before ', $actual['result']['items'][0]['error']['message']);
        $this->assertTrue($actual['result']['items'][1]['success']);
    }

    public function test_real_verta_transitive_jalali_domain_exception_remains_collectable(): void
    {
        $failure = null;
        try {
            Verta::createJalali(1404, 13, 1, 0, 0, 0, 'UTC');
        } catch (InvalidUnitException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(InvalidUnitException::class, $failure);
        $calculator = new BulkCalculator(static function () use ($failure): never {
            throw $failure;
        });

        $result = $calculator->calculate([$this->dayItem('invalid-jalali-unit')]);

        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame(['type' => $failure::class, 'message' => $failure->getMessage()], $result->items[0]->error);
    }

    #[DataProvider('applicationFailureModes')]
    public function test_real_application_query_listener_failures_escape_each_error_mode(string $class, string $mode): void
    {
        $this->storage('database');
        $failure = new $class('Application database query listener failed.');
        $armed = true;
        $this->app['db']->listen(static function (QueryExecuted $query) use ($failure, &$armed): void {
            if ($armed && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $armed = false;
                throw $failure;
            }
        });

        try {
            Workday::bulkCalculate([$this->dayItem('query-listener-error'), $this->dayItem('after-error')], ['on_error' => $mode]);
            $this->fail('The application query listener exception must remain visible.');
        } catch (Throwable $actual) {
            $this->assertSame($failure, $actual);
        }
    }

    #[DataProvider('applicationFailureModes')]
    public function test_application_wrappers_around_real_carbon_errors_are_not_collected(string $class, string $mode): void
    {
        $cause = $this->individualFailure($this->dayItem('invalid-date', 'not-a-valid-datetime'));
        $failure = new $class('Application callback failed after parsing.', previous: $cause);
        $calculator = new BulkCalculator(static function () use ($failure): never {
            throw $failure;
        });

        try {
            $calculator->calculate([$this->dayItem('application-wrapper')], ['on_error' => $mode]);
            $this->fail('An application wrapper around a dependency error must remain visible.');
        } catch (Throwable $actual) {
            $this->assertSame($failure, $actual);
            $this->assertSame($cause, $actual->getPrevious());
        }
    }

    public static function applicationFailureModes(): array
    {
        return [
            [InvalidArgumentException::class, 'collect'], [InvalidArgumentException::class, 'throw'],
            [RuntimeException::class, 'collect'], [RuntimeException::class, 'throw'],
        ];
    }

    #[DataProvider('wrappedInfrastructureFailures')]
    public function test_domain_wrappers_with_infrastructure_causes_escape_each_error_mode(string $class, string $mode): void
    {
        $actual = $this->installedBulkCalculation([$this->dayItem('wrapped-infrastructure')], ['on_error' => $mode], $class);

        $this->assertNull($actual['result']);
        $this->assertSame(['type' => WorkdayConfigurationException::class, 'message' => 'Domain-shaped wrapper.'], $actual['exception'][0]);
        $this->assertSame(['type' => $class, 'message' => 'Underlying infrastructure or programming failure.'], $actual['exception'][1]);
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Bulk'.DIRECTORY_SEPARATOR.'InfrastructureFixture.php', $actual['exception_files'][0]);
        $this->assertSame($actual['exception_files'][0], $actual['exception_files'][1]);
    }

    public static function wrappedInfrastructureFailures(): array
    {
        $cases = [];
        foreach ([\PDOException::class, \TypeError::class, Error::class] as $class) {
            foreach (['collect', 'throw'] as $mode) {
                $cases[] = [$class, $mode];
            }
        }

        return $cases;
    }

    /** Move the whole package, while loading its dependencies from their real Composer locations. */
    private function installedBulkCalculation(array $operations, array $options = [], ?string $infrastructureCause = null): array
    {
        $temporaryDirectory = realpath(sys_get_temp_dir());
        if ($temporaryDirectory === false) {
            throw new RuntimeException('The installed-layout fixture requires an existing temporary directory.');
        }
        $directory = $temporaryDirectory.DIRECTORY_SEPARATOR.'laravel-workdays-installed-bulk-'.bin2hex(random_bytes(8));
        $packageDirectory = $directory.DIRECTORY_SEPARATOR.'consumer'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'zarbinco'.DIRECTORY_SEPARATOR.'laravel-workdays';
        $sourceDirectory = $packageDirectory.DIRECTORY_SEPARATOR.'src';
        $filesystem = new Filesystem;
        $createdFiles = [];
        $createdDirectories = [];
        $write = static function (string $path, string $contents) use (&$createdFiles, &$createdDirectories, $directory, $filesystem): void {
            for ($parent = dirname($path); $parent !== dirname($directory); $parent = dirname($parent)) {
                $createdDirectories[$parent] = $parent;
            }
            $filesystem->ensureDirectoryExists(dirname($path));
            $filesystem->put($path, $contents);
            $createdFiles[] = $path;
        };

        try {
            foreach ($filesystem->allFiles(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'src') as $file) {
                $write($sourceDirectory.DIRECTORY_SEPARATOR.$file->getRelativePathname(), $file->getContents());
            }
            $inputPath = $directory.DIRECTORY_SEPARATOR.'input.json';
            $write($inputPath, json_encode(['configuration' => config('workdays'), 'operations' => $operations, 'options' => $options, 'infrastructure_cause' => $infrastructureCause,
                'runtime_timezone' => date_default_timezone_get(), 'application_timezone' => config('app.timezone')], JSON_THROW_ON_ERROR));
            if ($infrastructureCause !== null) {
                $write($sourceDirectory.DIRECTORY_SEPARATOR.'Bulk'.DIRECTORY_SEPARATOR.'InfrastructureFixture.php', <<<'PHP'
<?php
$class = $input['infrastructure_cause'];
return static function () use ($class): never {
    $cause = new $class('Underlying infrastructure or programming failure.');
    throw new Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException('Domain-shaped wrapper.', previous: $cause);
};
PHP);
            }
            $script = $directory.DIRECTORY_SEPARATOR.'consumer.php';
            $write($script, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$source = $argv[2];
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'Zarbinco\\LaravelWorkdays\\';
    if (str_starts_with($class, $prefix)) {
        $file = $source.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);
$input = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
date_default_timezone_set($input['runtime_timezone']);
$container = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($container);
$container->instance('config', new Illuminate\Config\Repository(['app' => ['timezone' => $input['application_timezone']], 'workdays' => $input['configuration']]));
$exception = null;
$exceptionFiles = [];
$result = null;
try {
    $observer = $input['infrastructure_cause'] === null ? null : require $source.DIRECTORY_SEPARATOR.'Bulk'.DIRECTORY_SEPARATOR.'InfrastructureFixture.php';
    $result = (new Zarbinco\LaravelWorkdays\Bulk\BulkCalculator($observer))->calculate($input['operations'], $input['options'])->toArray();
} catch (Throwable $error) {
    $chain = [];
    for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
        $chain[] = ['type' => $cause::class, 'message' => $cause->getMessage()];
        $exceptionFiles[] = $cause->getFile();
    }
    $exception = $chain;
}
echo json_encode(['result' => $result, 'exception' => $exception, 'exception_files' => $exceptionFiles,
    'bulk_file' => (new ReflectionClass(Zarbinco\LaravelWorkdays\Bulk\BulkCalculator::class))->getFileName(),
    'calculator_file' => (new ReflectionClass(Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator::class))->getFileName(),
    'carbon_file' => (new ReflectionClass(Carbon\CarbonImmutable::class))->getFileName(),
], JSON_THROW_ON_ERROR);
PHP);
            $process = new Process([PHP_BINARY, $script, dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php', $sourceDirectory, $inputPath]);
            $process->mustRun();
            $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($sourceDirectory.DIRECTORY_SEPARATOR.'Bulk'.DIRECTORY_SEPARATOR.'BulkCalculator.php', $actual['bulk_file']);
            $this->assertSame($sourceDirectory.DIRECTORY_SEPARATOR.'Calculator'.DIRECTORY_SEPARATOR.'BusinessDayCalculator.php', $actual['calculator_file']);
            $this->assertSame((new \ReflectionClass(CarbonImmutable::class))->getFileName(), $actual['carbon_file']);
            $this->assertStringNotContainsString($packageDirectory, $actual['carbon_file']);

            return $actual;
        } finally {
            foreach (array_reverse($createdFiles) as $createdFile) {
                if (is_file($createdFile)) {
                    unlink($createdFile);
                }
            }
            usort($createdDirectories, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
            foreach ($createdDirectories as $createdDirectory) {
                if (is_dir($createdDirectory)) {
                    rmdir($createdDirectory);
                }
            }
        }
    }

    private function profile(): array
    {
        return ['weekends' => ['Saturday', 'Sunday'], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [], 'working_hours' => $this->hours([['09:00', '12:00'], ['13:00', '17:00']]),
            'cutoff' => ['timezone' => 'UTC', 'time' => '14:00']];
    }

    private function hours(array $windows): array
    {
        return array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $windows);
    }

    private function workPlan(): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC'], 'stages' => [['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120]]];
    }

    private function allOperations(): array
    {
        $plan = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'], 'cutoff_profile' => 'warehouse', 'stages' => [
            ['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 60],
            ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']],
            ['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 1],
        ]];

        return [
            $this->dayItem('day'),
            ['id' => 'windows', 'operation' => 'working_windows', 'profile' => 'warehouse', 'date' => '2026-11-02'],
            ['id' => 'days', 'operation' => 'add_business_days', 'profile' => 'warehouse', 'date' => '2026-11-02', 'days' => 3],
            ['id' => 'minutes', 'operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => '2026-11-02T11:30:12.123456+00:00', 'minutes' => 120],
            ['id' => 'cutoff', 'operation' => 'resolve_cutoff', 'profile' => 'warehouse', 'received_at' => '2026-11-02T14:00:00Z'],
            ['id' => 'plan', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T13:30:00Z', 'plan' => $plan],
            ['id' => 'slots', 'operation' => 'delivery_slots', 'ready_at' => '2026-11-02T10:30:00Z', 'options' => [
                'profile' => 'warehouse', 'timezone' => 'UTC', 'days' => 2, 'duration_minutes' => 60, 'step_minutes' => 30, 'max_slots' => 10, 'min_notice_minutes' => 15]],
            ['id' => 'recurrence', 'operation' => 'recurring_schedule', 'anchor_date' => '2026-11-02', 'options' => [
                'profile' => 'child', 'timezone' => 'UTC', 'frequency' => 'weekly', 'interval' => 1, 'count' => 3, 'adjustment' => 'next']],
            ['id' => 'sla', 'operation' => 'evaluate_sla', 'started_at' => '2026-11-02T11:30:00Z', 'evaluated_at' => '2026-11-03T11:30:00Z',
                'options' => ['profile' => 'warehouse', 'timezone' => 'UTC', 'target_minutes' => 180, 'pauses' => [['started_at' => '2026-11-02T13:00:00Z', 'ended_at' => '2026-11-02T14:00:00Z']]]],
            ['id' => 'reverse', 'operation' => 'reverse_plan_delivery', 'desired_completion_at' => '2026-11-04T16:00:00Z', 'plan' => $this->workPlan(), 'options' => ['lookback_days' => 2]],
        ];
    }

    private function dayItem(string $id, string|\DateTimeInterface $date = '2026-11-02', string $profile = 'warehouse'): array
    {
        return ['id' => $id, 'operation' => 'is_business_day', 'profile' => $profile, 'date' => $date];
    }

    private function individual(array $item): mixed
    {
        return match ($item['operation']) {
            'is_business_day' => Workday::profile($item['profile'])->isBusinessDay($item['date']),
            'working_windows' => Workday::profile($item['profile'])->workingWindowsFor($item['date']),
            'add_business_days' => Workday::profile($item['profile'])->addBusinessDays($item['date'], $item['days']),
            'add_business_minutes' => Workday::profile($item['profile'])->addBusinessMinutes($item['datetime'], $item['minutes']),
            'resolve_cutoff' => Workday::profile($item['profile'])->resolveCutoff($item['received_at']),
            'plan_delivery' => Workday::planDelivery($item['received_at'], $item['plan']),
            'delivery_slots' => Workday::deliverySlots($item['ready_at'], $item['options']),
            'recurring_schedule' => Workday::recurringSchedule($item['anchor_date'], $item['options']),
            'evaluate_sla' => Workday::evaluateSla($item['started_at'], $item['evaluated_at'], $item['options']),
            'reverse_plan_delivery' => Workday::reversePlanDelivery($item['desired_completion_at'], $item['plan'], $item['options']),
        };
    }

    private function individualFailure(array $item): Throwable
    {
        try {
            $this->individual($item);
        } catch (Throwable $exception) {
            return $exception;
        }
        throw new RuntimeException('The standalone fixture unexpectedly succeeded.');
    }

    private function serialize(mixed $value): mixed
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_array($value)) {
            return array_map(fn (mixed $entry): mixed => $this->serialize($entry), $value);
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return $value;
    }

    private function assertParity(array $operations, array $options = []): void
    {
        $expected = array_map(fn (array $item): mixed => $this->serialize($this->individual($item)), $operations);
        $actual = Workday::bulkCalculate($operations, $options);
        $this->assertSame(0, $actual->failed);
        $this->assertSame(count($operations), $actual->succeeded);
        $this->assertSame($expected, array_map(fn (BulkCalculationItemResult $item): mixed => $this->serialize($item->result), $actual->items));
    }

    private function storage(string $driver): void
    {
        if ($driver !== 'config') {
            $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        }
        config()->set('workdays.storage.driver', $driver);
    }

    private function seedHoliday(string $profile, string $date, string $type, bool $active = true): WorkdaySpecialDate
    {
        return WorkdaySpecialDate::create(['profile' => $profile, 'date' => $date, 'type' => $type, 'title' => 'Fixture '.$type, 'is_active' => $active]);
    }

    private function holidayQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select')
            && (str_contains($query['query'], 'workday_holiday_rules') || str_contains($query['query'], 'workday_special_dates'))));
    }
}
