<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Support\WorkingTime;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class UnifiedWorkingTimeTest extends TestCase
{
    #[DataProvider('equivalentSchedules')]
    public function test_fractional_addition_navigation_and_total_difference_are_one_contract(string $source, array $variant): void
    {
        $calculator = $this->calculator($source, $variant);
        $input = Carbon::parse('2026-11-02T09:59:30.123456Z');
        $before = $input->format('c.u');
        $this->assertSame('2026-11-02T11:00:30.123456+00:00', $calculator->addBusinessMinutes($input, 1)->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame($before, $input->format('c.u'));
        $this->assertSame(1, $calculator->diffBusinessMinutes('2026-11-02T09:59:30Z', '2026-11-02T11:00:30Z'));
        $this->assertSame(-1, $calculator->diffBusinessMinutes('2026-11-02T11:00:30Z', '2026-11-02T09:59:30Z'));
        $this->assertSame('09:59:59.999999', $calculator->previousBusinessTime('2026-11-02T10:00:00Z')->format('H:i:s.u'));
        $this->assertSame('11:00:00.000000', $calculator->nextBusinessTime('2026-11-02T10:00:00Z')->format('H:i:s.u'));
        $this->assertTrue($calculator->isBusinessTime('2026-11-02T09:00:00Z'));
        $this->assertTrue($calculator->isBusinessTime('2026-11-02T09:59:59.999999Z'));
        $this->assertFalse($calculator->isBusinessTime('2026-11-02T10:00:00Z'));
        $this->assertSame('2026-11-02T10:30:00.123456+00:00', $calculator->addBusinessMinutes('2026-11-02T10:30:00.123456Z', 0)->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('10:00:00.000000', $calculator->addBusinessMinutes('2026-11-02T09:59:00Z', 1)->format('H:i:s.u'));
    }

    #[DataProvider('sources')]
    public function test_fractional_differences_truncate_total_once_toward_zero_including_pre_epoch(string $source): void
    {
        $calculator = $this->calculator($source, ['working_hours' => array_fill_keys(range(1, 7), [['09:00', '10:00'], ['11:00', '12:00']])]);
        foreach (['2026-11-02', '1969-11-03'] as $day) {
            $this->assertSame(1, $calculator->diffBusinessMinutes($day.'T09:59:20Z', $day.'T11:00:40Z'));
            $this->assertSame(-1, $calculator->diffBusinessMinutes($day.'T11:00:40Z', $day.'T09:59:20Z'));
            $this->assertSame(0, $calculator->diffBusinessMinutes($day.'T09:59:30Z', $day.'T11:00:29.999999Z'));
            $this->assertSame(1, $calculator->diffBusinessMinutes($day.'T09:59:30Z', $day.'T11:00:30Z'));
            $this->assertSame(0, $calculator->diffBusinessMinutes($day.'T11:00:29.999999Z', $day.'T09:59:30Z'));
            $this->assertSame(1 / 60, $calculator->diffBusinessHours($day.'T09:59:30Z', $day.'T11:00:30Z'));
        }
    }

    #[DataProvider('equivalentSchedules')]
    public function test_first_argument_timezone_is_fixed_before_ordering_and_second_representation_is_irrelevant(string $source, array $variant): void
    {
        $calculator = $this->calculator($source, $variant);
        $a = CarbonImmutable::parse('2026-11-02T09:59:30Z');
        $b = CarbonImmutable::parse('2026-11-02T11:00:30Z');
        foreach (['UTC', 'Asia/Tehran', 'Pacific/Kiritimati', 'America/New_York'] as $zone) {
            $this->assertSame(1, $calculator->diffBusinessMinutes($a, $b->setTimezone($zone)));
            $this->assertSame(-1, $calculator->diffBusinessMinutes($b, $a->setTimezone($zone)));
        }
        $this->assertSame(0, $calculator->diffBusinessMinutes($a->setTimezone('Asia/Tehran'), $b->setTimezone('Asia/Tehran')));
    }

    #[DataProvider('daytimeTransitions')]
    public function test_daytime_dst_and_skipped_midnight_use_local_wall_boundaries(string $source, array $variant, string $zone, string $start, string $end, string $completion, int $capacity): void
    {
        $calculator = $this->calculator($source, $variant + ['working_hours' => array_fill_keys(range(1, 7), $zone === 'America/New_York' ? [['00:00', '04:00']] : [['09:00', '17:00']])]);
        $a = CarbonImmutable::parse($start)->setTimezone($zone);
        $b = CarbonImmutable::parse($end)->setTimezone($zone);
        $this->assertSame($capacity, $calculator->diffBusinessMinutes($a, $b));
        $additionStart = $zone === 'America/New_York' && str_starts_with($start, '2026-03') ? CarbonImmutable::parse('2026-03-08T03:30:00-04:00')->setTimezone($zone) : $a;
        // The spring continuation uses an explicit Monday daytime schedule.
        if (str_starts_with($start, '2026-03')) {
            $calculator = $this->calculator($source, $variant + ['working_hours' => [7 => [['00:00', '04:00']], 1 => [['09:00', '17:00']]]]);
        } elseif (str_starts_with($start, '2026-11')) {
            $additionStart = CarbonImmutable::parse('2026-11-01T02:30:00-05:00')->setTimezone($zone);
        }
        $result = $calculator->addBusinessMinutes($additionStart, 60);
        $this->assertSame($completion, $result->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame(CarbonImmutable::parse($completion)->format('U.u'), $result->setTimezone('UTC')->format('U.u'));
    }

    #[DataProvider('sources')]
    public function test_overnight_daily_slices_union_overlap_preserve_touching_and_holiday_ownership(string $source): void
    {
        $profile = ['allow_overnight_working_hours' => true, 'working_hours' => [1 => [['22:00', '06:00']], 2 => [['04:00', '08:00'], ['08:00', '09:00']]], 'custom_holidays' => []];
        $calculator = $this->calculator($source, $profile);
        $this->assertSame('2026-11-03T02:00:00.000000+00:00', $calculator->addBusinessMinutes('2026-11-02T23:00:00Z', 180)->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame(240, $calculator->diffBusinessMinutes('2026-11-02T23:00:00Z', '2026-11-03T03:00:00Z'));
        $this->assertSame(480, $calculator->diffBusinessMinutes('2026-11-03T00:00:00Z', '2026-11-03T08:00:00Z'));
        $this->assertSame([['start' => '00:00', 'end' => '08:00'], ['start' => '08:00', 'end' => '09:00']], array_map(fn ($w) => $w->toArray(), $calculator->workingWindowsFor('2026-11-03T00:00:00Z')));
        $closedReceiving = $this->calculator($source, array_replace($profile, ['custom_holidays' => ['2026-11-03' => 'Receiving date closed'], 'special_working_hours' => ['2026-11-03' => []]]));
        $this->assertTrue($closedReceiving->isBusinessTime('2026-11-03T05:59:59.999999Z'));
        $closedOwner = $this->calculator($source, array_replace($profile, ['custom_holidays' => ['2026-11-02' => 'Origin closed'], 'working_hours' => [1 => [['22:00', '06:00']]]]));
        $this->assertFalse($closedOwner->isBusinessTime('2026-11-03T02:00:00Z'));
    }

    #[DataProvider('sources')]
    public function test_overnight_dst_counts_real_elapsed_time_and_derived_missing_midnight(string $source): void
    {
        $calculator = $this->calculator($source, ['allow_overnight_working_hours' => true, 'working_hours' => [6 => [['22:00', '06:00']]]]);
        foreach ([['2026-03-07T22:00:00-05:00', '2026-03-08T06:00:00-04:00', 420], ['2026-10-31T22:00:00-04:00', '2026-11-01T06:00:00-05:00', 540]] as [$start, $end, $minutes]) {
            $a = CarbonImmutable::parse($start)->setTimezone('America/New_York');
            $b = CarbonImmutable::parse($end)->setTimezone('America/New_York');
            $this->assertSame($minutes, $calculator->diffBusinessMinutes($a, $b));
            $this->assertSame($b->format('U.u'), $calculator->addBusinessMinutes($a, $minutes)->format('U.u'));
        }
        $a = CarbonImmutable::parse('2026-09-05T22:00:00-04:00')->setTimezone('America/Santiago');
        $b = CarbonImmutable::parse('2026-09-06T03:00:00-03:00')->setTimezone('America/Santiago');
        $this->assertSame(240, $calculator->diffBusinessMinutes($a, $b));
        $this->assertTrue($calculator->isBusinessTime(CarbonImmutable::parse('2026-09-06T01:00:00-03:00')->setTimezone('America/Santiago')));
    }

    #[DataProvider('sources')]
    public function test_one_addition_budget_is_anchored_before_any_normalization(string $source): void
    {
        $calculator = $this->calculator($source, ['working_hours' => array_fill_keys(range(1, 7), [['09:00', '09:01']])], 1);
        $this->assertSame('2026-11-03T09:00:30.123456+00:00', $calculator->addBusinessMinutes('2026-11-02T09:00:30.123456Z', 1)->format('Y-m-d\TH:i:s.uP'));
        $this->expectException(RuntimeException::class);
        $calculator->addBusinessMinutes('2026-11-02T09:00:30.123456Z', 2);
    }

    #[DataProvider('sources')]
    public function test_last_daily_slice_can_complete_at_exclusive_24_without_expanding_diff_range(string $source): void
    {
        $calculator = $this->calculator($source, ['allow_overnight_working_hours' => true, 'working_hours' => array_fill_keys(range(1, 7), [['22:00', '24:00']])], 1);
        $this->assertSame('2026-11-04T00:00:00.000000+00:00', $calculator->addBusinessMinutes('2026-11-02T23:00:00Z', 180)->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame(180, $calculator->diffBusinessMinutes('2026-11-02T23:00:00Z', '2026-11-04T00:00:00Z'));
        $this->expectException(RuntimeException::class);
        $calculator->diffBusinessMinutes('2026-11-02T23:00:00Z', '2026-11-04T00:00:00.000001Z');
    }

    #[DataProvider('sources')]
    public function test_skipped_civil_date_consumes_search_offset_without_phantom_time(string $source): void
    {
        $calculator = $this->calculator($source, ['working_hours' => array_fill_keys(range(1, 7), [['09:00', '17:00']])], 1);
        $a = CarbonImmutable::parse('2011-12-29T17:00:00-10:00')->setTimezone('Pacific/Apia');
        $this->expectException(RuntimeException::class);
        $calculator->nextBusinessTime($a);
    }

    #[DataProvider('sources')]
    public function test_day_only_calendars_remain_valid_but_time_apis_require_hours(string $source): void
    {
        $calculator = $this->calculator($source, ['working_hours' => null], dayOnly: true);
        $this->assertTrue($calculator->isBusinessDay('2026-11-02'));
        $this->expectException(WorkdayConfigurationException::class);
        $calculator->addBusinessMinutes('2026-11-02T10:00:00Z', 0);
    }

    #[DataProvider('unsafeHours')]
    public function test_unsafe_hour_inputs_are_rejected_explicitly(int|float $hours): void
    {
        $calculator = $this->calculator('config', []);
        $this->expectException(InvalidArgumentException::class);
        $calculator->addBusinessHours('2026-11-02T09:00:00Z', $hours);
    }

    public function test_enormous_minutes_fail_before_multiplication_or_date_scanning(): void
    {
        $calculator = $this->calculator('config', [], 1);
        $this->expectException(InvalidArgumentException::class);
        $calculator->addBusinessMinutes('2026-11-02T09:00:00Z', PHP_INT_MAX);
    }

    #[DataProvider('equivalentSchedules')]
    public function test_short_supported_operations_do_not_require_the_unused_search_margin_to_exist(string $source, array $variant): void
    {
        $calculator = $this->calculator($source, $variant + ['working_hours' => array_fill_keys(range(1, 7), [['09:00', '17:00']])]);
        foreach (['0001-01-01', '9999-12-31'] as $label) {
            $a = CarbonImmutable::parse($label.'T09:00:00Z');
            $this->assertSame($label.'T09:01:00.000000+00:00', $calculator->addBusinessMinutes($a, 1)->format('Y-m-d\TH:i:s.uP'));
            $this->assertSame($a->format('U.u'), $calculator->nextBusinessTime($a)->format('U.u'));
            $this->assertSame($a->format('U.u'), $calculator->previousBusinessTime($a)->format('U.u'));
            $this->assertSame(1, $calculator->diffBusinessMinutes($a, $a->addMinute()));
        }
    }

    public function test_exact_internal_overlap_is_additive_when_public_minute_presentation_is_not(): void
    {
        $calculator = $this->calculator('config', []);
        $engine = new WorkingTime('hours', 31, $calculator->workingIntervalsFor(...));
        $a = CarbonImmutable::parse('2026-11-02T09:59:20Z');
        $b = CarbonImmutable::parse('2026-11-02T10:30:00Z');
        $c = CarbonImmutable::parse('2026-11-02T11:00:40Z');
        $this->assertSame(80000000, $engine->measure($a, $c));
        $this->assertSame($engine->measure($a, $c), $engine->measure($a, $b) + $engine->measure($b, $c));
        $this->assertSame(0, $calculator->diffBusinessMinutes($a, $b) + $calculator->diffBusinessMinutes($b, $c));
        $this->assertSame(1, $calculator->diffBusinessMinutes($a, $c));
    }

    #[DataProvider('sources')]
    public function test_unused_overnight_permission_does_not_require_hours_on_an_unrelated_reopened_previous_date(string $source): void
    {
        $profile = ['extra_working_days' => ['2026-11-01' => 'Reopened Sunday'], 'working_hours' => [1 => [['09:00', '17:00']]]];
        $plain = $this->calculator($source, $profile);
        $enabled = $this->calculator($source, $profile + ['allow_overnight_working_hours' => true]);
        $this->assertTrue($plain->isBusinessTime('2026-11-02T10:00:00Z'));
        $this->assertTrue($enabled->isBusinessTime('2026-11-02T10:00:00Z'));
        $this->assertSame($plain->addBusinessMinutes('2026-11-02T10:00:00Z', 60)->format('U.u'), $enabled->addBusinessMinutes('2026-11-02T10:00:00Z', 60)->format('U.u'));
        // An overnight rule elsewhere is equally irrelevant to this receiving Monday.
        $elsewhere = $this->calculator($source, array_replace($profile, ['allow_overnight_working_hours' => true, 'working_hours' => $profile['working_hours'] + [5 => [['22:00', '06:00']]]]));
        $this->assertTrue($elsewhere->isBusinessTime('2026-11-02T10:00:00Z'));
    }

    #[DataProvider('sources')]
    public function test_seeded_exact_addition_inverse_and_timezone_cache_isolation(string $source): void
    {
        $calculator = $this->calculator($source, ['working_hours' => array_fill_keys(range(1, 7), [['09:00', '10:00'], ['11:00', '12:00']])]);
        $seed = 1701;
        for ($i = 0; $i < 24; $i++) {
            $seed = (int) (($seed * 48271) % 2147483647);
            $minutes = $seed % 180 + 1;
            $a = CarbonImmutable::parse('2026-11-02T09:00:00Z')->addSeconds($seed % 3500)->setMicrosecond($seed % 1000000);
            $b = $calculator->addBusinessMinutes($a, $minutes);
            $this->assertSame($minutes, $calculator->diffBusinessMinutes($a, $b));
            $this->assertSame('11:00:00', $calculator->nextBusinessTime(CarbonImmutable::parse('2026-11-02 10:00', 'Asia/Tehran'))->format('H:i:s'));
            $this->assertSame('11:00:00', $calculator->nextBusinessTime(CarbonImmutable::parse('2026-11-02 10:00', 'UTC'))->format('H:i:s'));
        }
    }

    public static function sources(): array
    {
        return [['config'], ['database']];
    }

    public static function equivalentSchedules(): iterable
    {
        foreach (['config', 'database'] as $source) {
            foreach ([
                'plain' => [], 'false' => ['allow_overnight_working_hours' => false],
                'true' => ['allow_overnight_working_hours' => true],
                'empty overlays' => ['special_working_hours' => [], 'seasonal_working_hours' => []],
            ] as $label => $variant) {
                yield $source.' '.$label => [$source, $variant];
            }
        }
    }

    public static function daytimeTransitions(): iterable
    {
        foreach (self::equivalentSchedules() as $key => [$source, $variant]) {
            yield $key.' spring' => [$source, $variant, 'America/New_York', '2026-03-08T00:00:00-05:00', '2026-03-08T04:00:00-04:00', '2026-03-09T09:30:00.000000-04:00', 180];
            yield $key.' fall' => [$source, $variant, 'America/New_York', '2026-11-01T00:00:00-04:00', '2026-11-01T04:00:00-05:00', '2026-11-01T03:30:00.000000-05:00', 300];
            yield $key.' midnight gap' => [$source, $variant, 'America/Santiago', '2026-09-06T09:00:00-03:00', '2026-09-06T17:00:00-03:00', '2026-09-06T10:00:00.000000-03:00', 480];
        }
    }

    public static function unsafeHours(): array
    {
        return [[NAN], [INF], [-INF], [PHP_INT_MAX], [0.00001]];
    }

    private function calculator(string $source, array $profile, int $limit = 31, bool $dayOnly = false): BusinessDayCalculator
    {
        $profile = array_replace(['weekends' => [], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => [], 'working_hours' => [1 => [['09:00', '10:00'], ['11:00', '12:00']]]], $profile);
        if ($dayOnly) {
            unset($profile['working_hours']);
        }
        $definitions = ['default_profile' => 'hours', 'include_start_date' => false, 'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null, 'profiles' => ['hours' => $profile]];
        config()->set('workdays.max_scan_days', $limit);
        config()->set('workdays.driver', $source);
        if ($source === 'database') {
            if (! $this->app['db']->connection()->getSchemaBuilder()->hasTable('workday_definition_state')) {
                $this->loadMigrationsFrom(dirname((new \ReflectionClass(TestCase::class))->getFileName()).'/../database/migrations');
            }
            $repository = $this->app->make(DatabaseDefinitionRepository::class);
            $repository->publish($definitions, expectedRevisionId: $repository->current()?->revisionId);
        } else {
            config()->set('workdays.definitions', $definitions);
        }

        return Workday::profile('hours');
    }
}
