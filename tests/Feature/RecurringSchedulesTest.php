<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeZone;
use Error;
use Hekmatinasser\Verta\Verta;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\RecurringOccurrence;
use Zarbinco\LaravelWorkdays\Data\RecurringScheduleResult;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\Recurring\RecurringScheduleGenerator;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class RecurringSchedulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.default_profile', 'warehouse');
        config()->set('workdays.storage.driver', 'config');
        config()->set('workdays.max_scan_days', 3660);
        config()->set('workdays.hijri', ['method' => 'mathematical', 'adjustment' => 0]);
        config()->set('workdays.profiles', ['warehouse' => $this->profile()]);
    }

    public function test_a_monthly_holiday_adjustments_never_change_the_original_anchor_day(): void
    {
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-05' => 'Fixture closure']);

        $result = $this->schedule('2026-10-05');

        $this->assertInstanceOf(RecurringScheduleResult::class, $result);
        $this->assertSame([
            ['2026-10-05', '2026-10-05'], ['2026-11-05', '2026-11-06'],
            ['2026-12-05', '2026-12-07'], ['2027-01-05', '2027-01-05'],
        ], $this->dates($result));
        $this->assertSame(['2026-10', '2026-11', '2026-12', '2027-01'], array_column($result->toArray()['occurrences'], 'nominal_period'));
        $this->assertSame([0, 1, 2, 3], array_column($result->toArray()['occurrences'], 'cycle_index'));
        $this->assertSame(['scheduled', 'shifted', 'shifted', 'scheduled'], array_column($result->toArray()['occurrences'], 'status'));
        $this->assertSame(['business_day', 'shifted_next', 'shifted_next', 'business_day'], array_column($result->toArray()['occurrences'], 'reason'));
        $this->assertSame([0, 1, 2, 0], array_column($result->toArray()['occurrences'], 'shift_days'));
    }

    public function test_b_weekly_cycles_remain_anchored_after_a_friday_holiday_moves_to_monday(): void
    {
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-13' => 'Friday closure']);

        $result = $this->schedule('2026-11-06', ['frequency' => 'weekly']);

        $this->assertSame([
            ['2026-11-06', '2026-11-06'], ['2026-11-13', '2026-11-16'],
            ['2026-11-20', '2026-11-20'], ['2026-11-27', '2026-11-27'],
        ], $this->dates($result));
        $this->assertSame(['2026-11-06', '2026-11-13', '2026-11-20', '2026-11-27'], array_column($result->toArray()['occurrences'], 'nominal_period'));
        $this->assertSame(3, $result->occurrences[1]->shiftDays);
    }

    #[DataProvider('gregorianClampCases')]
    public function test_c_d_gregorian_clamp_and_leap_years_restore_original_day_after_short_months(string $anchor, array $expected): void
    {
        $this->everyDay();

        $result = $this->schedule($anchor);

        $this->assertSame($expected, array_column($result->toArray()['occurrences'], 'nominal_date'));
        $this->assertSame($expected, array_column($result->toArray()['occurrences'], 'scheduled_date'));
    }

    public static function gregorianClampCases(): array
    {
        return [
            'C common year' => ['2026-01-31', ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']],
            'D leap year' => ['2024-01-31', ['2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30']],
        ];
    }

    public function test_e_skipped_short_months_preserve_cycle_identity_and_do_not_generate_replacements(): void
    {
        $this->everyDay();

        $result = $this->schedule('2026-01-31', ['short_month' => 'skip']);

        $this->assertCount(4, $result->occurrences);
        $this->assertSame(4, $result->requestedCount);
        $this->assertSame([['2026-01-31', '2026-01-31'], [null, null], ['2026-03-31', '2026-03-31'], [null, null]], $this->dates($result));
        $this->assertSame([
            'cycle_index' => 1, 'nominal_period' => '2026-02', 'nominal_date' => null, 'scheduled_date' => null,
            'status' => 'skipped', 'reason' => 'invalid_month_day', 'shift_days' => null, 'collides' => false,
        ], $result->occurrences[1]->toArray());
        $this->assertSame(2, $result->occurrences[2]->cycleIndex);
    }

    #[DataProvider('shortMonthModes')]
    public function test_f_end_of_month_uses_each_target_month_end_regardless_of_short_month_policy(string $shortMonth): void
    {
        $this->everyDay();

        $result = $this->schedule('2024-01-31', ['month_anchor' => 'end_of_month', 'short_month' => $shortMonth]);

        $this->assertSame(['2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30'], array_column($result->toArray()['occurrences'], 'nominal_date'));
    }

    public static function shortMonthModes(): array
    {
        return [['clamp'], ['skip']];
    }

    public function test_end_of_month_rejects_an_anchor_that_is_not_selected_calendar_month_end(): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        $this->schedule('2026-01-30', ['month_anchor' => 'end_of_month']);
    }

    public function test_g_jalali_clamp_crosses_thirty_day_months_esfand_and_year_rollover_without_drift(): void
    {
        $this->everyDay();
        $anchor = (new JalaliCalendarAdapter)->jalaliDateToGregorian(1404, 6, 31)->toDateString();

        $result = $this->schedule($anchor, ['calendar' => 'jalali', 'count' => 8]);

        $this->assertSame('2025-09-22', $anchor);
        $this->assertSame(['1404-06', '1404-07', '1404-08', '1404-09', '1404-10', '1404-11', '1404-12', '1405-01'], array_column($result->toArray()['occurrences'], 'nominal_period'));
        $this->assertSame(['2025-09-22', '2025-10-22', '2025-11-21', '2025-12-21', '2026-01-20', '2026-02-19', '2026-03-20', '2026-04-20'], array_column($result->toArray()['occurrences'], 'nominal_date'));
        $this->assertSame(['1404-06-31', '1404-07-30', '1404-08-30', '1404-09-30', '1404-10-30', '1404-11-30', '1404-12-29', '1405-01-31'], array_map(static fn (RecurringOccurrence $occurrence): string => Verta::instance(CarbonImmutable::parse($occurrence->nominalDate, 'UTC'))->format('Y-m-d'), $result->occurrences));
    }

    public function test_g_jalali_skip_keeps_invalid_cycles_and_restores_original_day_in_farvardin(): void
    {
        $this->everyDay();

        $result = $this->schedule('2025-09-22', ['calendar' => 'jalali', 'short_month' => 'skip', 'count' => 8]);

        $this->assertCount(8, $result->occurrences);
        $this->assertSame(['2025-09-22', null, null, null, null, null, null, '2026-04-20'], array_column($result->toArray()['occurrences'], 'nominal_date'));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], array_column($result->toArray()['occurrences'], 'cycle_index'));
        $this->assertSame('invalid_month_day', $result->occurrences[6]->reason);
        $this->assertSame('1405-01', $result->occurrences[7]->nominalPeriod);
    }

    #[DataProvider('jalaliLeapCases')]
    public function test_g_jalali_esfand_month_length_uses_jalali_leap_rules_and_round_trips(string $anchor, array $expectedPeriods, array $expectedDates, array $expectedJalali): void
    {
        $this->everyDay();

        $result = $this->schedule($anchor, ['calendar' => 'jalali', 'month_anchor' => 'end_of_month', 'count' => count($expectedDates)]);

        $this->assertSame($expectedPeriods, array_column($result->toArray()['occurrences'], 'nominal_period'));
        $this->assertSame($expectedDates, array_column($result->toArray()['occurrences'], 'nominal_date'));
        foreach ($result->occurrences as $index => $occurrence) {
            $this->assertSame($expectedJalali[$index], Verta::instance(CarbonImmutable::parse($occurrence->nominalDate, 'UTC'))->format('Y-m-d'));
        }
    }

    public static function jalaliLeapCases(): array
    {
        return [
            'Gregorian2024 leap does not make Jalali1402 leap' => ['2024-02-19', ['1402-11', '1402-12', '1403-01'], ['2024-02-19', '2024-03-19', '2024-04-19'], ['1402-11-30', '1402-12-29', '1403-01-31']],
            'Jalali1403 leap despite Gregorian2025 common year' => ['2025-02-18', ['1403-11', '1403-12', '1404-01'], ['2025-02-18', '2025-03-20', '2025-04-20'], ['1403-11-30', '1403-12-30', '1404-01-31']],
            'Jalali1404 common Esfand into1405' => ['2026-02-19', ['1404-11', '1404-12', '1405-01'], ['2026-02-19', '2026-03-20', '2026-04-20'], ['1404-11-30', '1404-12-29', '1405-01-31']],
        ];
    }

    public function test_jalali_interval_twelve_preserves_original_day_thirty_across_leap_year_change(): void
    {
        $this->everyDay();

        $clamped = $this->schedule('2025-03-20', ['calendar' => 'jalali', 'interval' => 12, 'count' => 2]);
        $skipped = $this->schedule('2025-03-20', ['calendar' => 'jalali', 'interval' => 12, 'count' => 2, 'short_month' => 'skip']);

        $this->assertSame(['1403-12', '1404-12'], array_column($clamped->toArray()['occurrences'], 'nominal_period'));
        $this->assertSame(['2025-03-20', '2026-03-20'], array_column($clamped->toArray()['occurrences'], 'nominal_date'));
        $this->assertNull($skipped->occurrences[1]->nominalDate);
        $this->assertSame('invalid_month_day', $skipped->occurrences[1]->reason);
    }

    public function test_jalali_month_end_validation_uses_selected_calendar_rather_than_gregorian_month_end(): void
    {
        $this->everyDay();
        $accepted = $this->schedule('2026-03-20', ['calendar' => 'jalali', 'month_anchor' => 'end_of_month', 'count' => 1]);
        $this->assertSame('1404-12', $accepted->occurrences[0]->nominalPeriod);
        $this->expectException(WorkdayConfigurationException::class);

        $this->schedule('2026-01-31', ['calendar' => 'jalali', 'month_anchor' => 'end_of_month', 'count' => 1]);
    }

    public function test_h_previous_adjustment_crosses_year_boundary_before_anchor_with_negative_date_distance(): void
    {
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2027-01-01' => 'New year closure']);

        $result = $this->schedule('2027-01-01', ['frequency' => 'weekly', 'adjustment' => 'previous', 'count' => 1, 'max_shift_days' => 1]);

        $this->assertSame([['2027-01-01', '2026-12-31']], $this->dates($result));
        $this->assertSame(-1, $result->occurrences[0]->shiftDays);
        $this->assertSame('shifted', $result->occurrences[0]->status);
        $this->assertSame('shifted_previous', $result->occurrences[0]->reason);
    }

    public function test_i_skip_policy_keeps_closed_nominal_date_and_cycle_without_replacement(): void
    {
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-13' => 'Closed']);

        $result = $this->schedule('2026-11-06', ['frequency' => 'weekly', 'adjustment' => 'skip']);

        $this->assertCount(4, $result->occurrences);
        $this->assertSame([['2026-11-06', '2026-11-06'], ['2026-11-13', null], ['2026-11-20', '2026-11-20'], ['2026-11-27', '2026-11-27']], $this->dates($result));
        $this->assertSame('skipped', $result->occurrences[1]->status);
        $this->assertSame('closed_day', $result->occurrences[1]->reason);
        $this->assertNull($result->occurrences[1]->shiftDays);
        $this->assertFalse($result->occurrences[1]->collides);
    }

    public function test_j_extra_working_day_reopens_a_closed_saturday_without_working_hours_or_cutoff(): void
    {
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-07' => 'Closed']);
        config()->set('workdays.profiles.warehouse.extra_working_days', ['2026-11-07' => 'Reopened']);
        config()->set('workdays.profiles.warehouse.cutoff', ['time' => 'invalid but unused']);

        $result = $this->schedule('2026-11-07', ['frequency' => 'weekly', 'count' => 1, 'max_shift_days' => 0]);

        $this->assertSame([['2026-11-07', '2026-11-07']], $this->dates($result));
        $this->assertSame('business_day', $result->occurrences[0]->reason);
        $this->assertSame(0, $result->occurrences[0]->shiftDays);
    }

    public function test_collision_rejection_identifies_both_cycles_and_the_conflicting_scheduled_date(): void
    {
        $this->collisionFixture();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(?=.*0)(?=.*1)(?=.*2026-11-09)/s');

        $this->schedule('2026-11-02', ['frequency' => 'weekly', 'count' => 3]);
    }

    public function test_allowed_collisions_mark_both_cycles_without_merging_or_reordering_them(): void
    {
        $this->collisionFixture();

        $result = $this->schedule('2026-11-02', ['frequency' => 'weekly', 'count' => 3, 'collision_policy' => 'allow']);

        $this->assertSame([['2026-11-02', '2026-11-09'], ['2026-11-09', '2026-11-09'], ['2026-11-16', '2026-11-16']], $this->dates($result));
        $this->assertSame([0, 1, 2], array_column($result->toArray()['occurrences'], 'cycle_index'));
        $this->assertSame([true, true, false], array_column($result->toArray()['occurrences'], 'collides'));
        $this->assertSame([7, 0, 0], array_column($result->toArray()['occurrences'], 'shift_days'));
    }

    public function test_three_colliding_cycles_all_keep_their_identity_and_collision_flag(): void
    {
        $this->everyDay();
        $this->closeDates('2026-11-02', 14);

        $result = $this->schedule('2026-11-02', ['frequency' => 'weekly', 'count' => 3, 'max_shift_days' => 14, 'collision_policy' => 'allow']);

        $this->assertSame(['2026-11-16', '2026-11-16', '2026-11-16'], array_column($result->toArray()['occurrences'], 'scheduled_date'));
        $this->assertSame([true, true, true], array_column($result->toArray()['occurrences'], 'collides'));
        $this->assertSame([14, 7, 0], array_column($result->toArray()['occurrences'], 'shift_days'));
    }

    public function test_zero_shift_limit_accepts_open_nominal_date_and_rejects_a_closed_one(): void
    {
        $open = $this->schedule('2026-11-02', ['count' => 1, 'max_shift_days' => 0]);
        $this->assertSame('2026-11-02', $open->occurrences[0]->scheduledDate);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(?=.*warehouse)(?=.*0)(?=.*2026-11-07)(?=.*next)/s');

        $this->schedule('2026-11-07', ['count' => 1, 'max_shift_days' => 0]);
    }

    #[DataProvider('shiftDirections')]
    public function test_movement_exactly_at_shift_limit_is_allowed_and_one_day_beyond_is_not(string $direction, int $sign): void
    {
        $this->everyDay();
        $anchor = CarbonImmutable::parse('2026-11-02', 'UTC');
        $closed = [];
        foreach (range(0, 6) as $distance) {
            $closed[$anchor->addDays($distance * $sign)->toDateString()] = 'Closed';
        }
        config()->set('workdays.profiles.warehouse.custom_holidays', $closed);
        $result = $this->schedule('2026-11-02', ['count' => 1, 'adjustment' => $direction, 'max_shift_days' => 7]);
        $this->assertSame($anchor->addDays(7 * $sign)->toDateString(), $result->occurrences[0]->scheduledDate);
        $this->assertSame(7 * $sign, $result->occurrences[0]->shiftDays);
        $closed[$anchor->addDays(7 * $sign)->toDateString()] = 'Also closed';
        config()->set('workdays.profiles.warehouse.custom_holidays', $closed);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(?=.*warehouse)(?=.*0)(?=.*2026-11-02)(?=.*'.$direction.')(?=.*7)/s');

        $this->schedule('2026-11-02', ['count' => 1, 'adjustment' => $direction, 'max_shift_days' => 7]);
    }

    public static function shiftDirections(): array
    {
        return [['next', 1], ['previous', -1]];
    }

    public function test_maximum_thirty_one_label_shift_is_supported_without_unbounded_search(): void
    {
        config()->set('workdays.profiles.warehouse.weekends', range(1, 7));
        config()->set('workdays.profiles.warehouse.extra_working_days', ['2026-12-02' => 'Only eligible date']);

        $result = $this->schedule('2026-11-01', ['count' => 1, 'max_shift_days' => 31]);

        $this->assertSame('2026-12-02', $result->occurrences[0]->scheduledDate);
        $this->assertSame(31, $result->occurrences[0]->shiftDays);
    }

    public function test_weekly_cycle_exactly_on_global_horizon_is_included_and_next_cycle_is_rejected(): void
    {
        config()->set('workdays.max_scan_days', 7);
        $result = $this->schedule('2026-11-02', ['frequency' => 'weekly', 'count' => 2]);
        $this->assertSame('2026-11-09', $result->occurrences[1]->nominalDate);
        $this->assertSame(7, config('workdays.max_scan_days'));
        $this->expectException(RuntimeException::class);

        $this->schedule('2026-11-02', ['frequency' => 'weekly', 'count' => 3]);
    }

    public function test_future_adjustment_cannot_cross_the_global_horizon_even_with_a_larger_shift_limit(): void
    {
        config()->set('workdays.max_scan_days', 7);
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-09' => 'Final-date closure']);
        $this->expectException(RuntimeException::class);

        $this->schedule('2026-11-02', ['frequency' => 'weekly', 'count' => 2, 'max_shift_days' => 31]);
    }

    public function test_previous_adjustment_may_precede_anchor_within_its_shift_allowance(): void
    {
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2027-01-01' => 'Closed']);

        $result = $this->schedule('2027-01-01', ['count' => 1, 'adjustment' => 'previous', 'max_shift_days' => 1]);

        $this->assertSame('2026-12-31', $result->occurrences[0]->scheduledDate);
        $this->assertSame(1, config('workdays.max_scan_days'));
    }

    public function test_skipped_short_month_requires_its_entire_valid_target_period_inside_the_horizon(): void
    {
        $this->everyDay();
        config()->set('workdays.max_scan_days', 28);
        $allowed = $this->schedule('2026-01-31', ['count' => 2, 'short_month' => 'skip']);
        $this->assertNull($allowed->occurrences[1]->nominalDate);
        config()->set('workdays.max_scan_days', 1);
        $this->expectException(RuntimeException::class);

        $this->schedule('2026-01-31', ['count' => 2, 'short_month' => 'skip']);
    }

    public function test_valid_monthly_nominal_day_does_not_require_the_entire_month_inside_horizon(): void
    {
        $this->everyDay();
        config()->set('workdays.max_scan_days', 31);

        $result = $this->schedule('2026-01-05', ['count' => 2]);

        $this->assertSame('2026-02-05', $result->occurrences[1]->nominalDate);
    }

    public function test_interval_and_count_upper_bounds_generate_directly_from_original_anchor(): void
    {
        $this->everyDay();
        $annual = $this->schedule('2024-02-29', ['interval' => 12, 'count' => 3]);
        $sixty = $this->schedule('2026-01-31', ['count' => 60]);
        $weekly = $this->schedule('2026-11-02', ['frequency' => 'weekly', 'interval' => 12, 'count' => 2]);

        $this->assertSame(['2024-02-29', '2025-02-28', '2026-02-28'], array_column($annual->toArray()['occurrences'], 'nominal_date'));
        $this->assertCount(60, $sixty->occurrences);
        $this->assertSame(59, $sixty->occurrences[59]->cycleIndex);
        $this->assertSame('2030-12-31', $sixty->occurrences[59]->nominalDate);
        $this->assertSame('2027-01-25', $weekly->occurrences[1]->nominalDate);
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_schedule_options_use_configuration_exceptions(array $options): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        Workday::recurringSchedule('2026-11-02', $options);
    }

    public static function invalidOptions(): array
    {
        $valid = ['profile' => 'warehouse', 'timezone' => 'UTC', 'frequency' => 'monthly', 'interval' => 1, 'count' => 4, 'adjustment' => 'next'];
        $cases = [[]];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            $cases[] = $missing;
        }
        foreach (['profile' => ['', 'missing', null, true, []], 'timezone' => ['', 'Mars/Olympus', '+03:30', null, true],
            'frequency' => ['daily', 'Monthly', null, []], 'calendar' => ['hijri', 'Gregorian', null, false],
            'interval' => [0, -1, 13, '1', 1.0, true, null], 'count' => [0, -1, 61, '4', 4.0, false, null],
            'month_anchor' => ['first_day', '', null, []], 'short_month' => ['rollover', null, true],
            'adjustment' => ['nearest', 'Next', null, false], 'max_shift_days' => [-1, 32, '7', 7.0, true, null],
            'collision_policy' => ['merge', null, false],
        ] as $key => $values) {
            foreach ($values as $value) {
                $cases[] = array_replace($valid, [$key => $value]);
            }
        }
        $cases[] = $valid + ['unknown' => true];
        $weekly = array_replace($valid, ['frequency' => 'weekly']);
        foreach ([['calendar' => 'jalali'], ['month_anchor' => 'day_of_month'], ['month_anchor' => 'end_of_month'], ['short_month' => 'clamp'], ['short_month' => 'skip']] as $extra) {
            $cases[] = array_replace($weekly, $extra);
        }

        return array_map(static fn (array $options): array => [$options], $cases);
    }

    #[DataProvider('invalidAnchors')]
    public function test_string_anchor_must_be_a_real_strict_date_in_the_selected_timezone(string $anchor, string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->schedule($anchor, ['timezone' => $timezone, 'count' => 1]);
    }

    public static function invalidAnchors(): array
    {
        $cases = array_map(static fn (string $anchor): array => [$anchor, 'UTC'], [
            '', 'today', 'next Monday', '2026-02-30', '2026-11-02T00:00:00Z', '2026-11-02 00:00', '2026/11/02',
            '2026-1-02', '26-11-02', '2026-11', '0000-01-01', '2026-13-01', "2026-11-02\n", ' 2026-11-02',
        ]);
        $cases[] = ['2011-12-30', 'Pacific/Apia'];

        return $cases;
    }

    #[DataProvider('weeklyTimezoneCases')]
    public function test_weekly_cycles_step_by_local_date_labels_through_dst_changes(string $anchor, string $timezone, array $expected): void
    {
        $this->everyDay();

        $result = $this->schedule($anchor, ['frequency' => 'weekly', 'timezone' => $timezone, 'count' => count($expected)]);

        $this->assertSame($anchor, $result->anchorDate);
        $this->assertSame($expected, array_column($result->toArray()['occurrences'], 'nominal_date'));
        $this->assertSame($expected, array_column($result->toArray()['occurrences'], 'scheduled_date'));
        $this->assertSame(array_fill(0, count($expected), 0), array_column($result->toArray()['occurrences'], 'shift_days'));
    }

    public static function weeklyTimezoneCases(): array
    {
        return [
            ['2026-03-01', 'America/New_York', ['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22']],
            ['2026-10-25', 'America/New_York', ['2026-10-25', '2026-11-01', '2026-11-08', '2026-11-15']],
            ['2026-09-06', 'America/Santiago', ['2026-09-06', '2026-09-13', '2026-09-20']],
            ['2026-11-02', 'Asia/Tehran', ['2026-11-02', '2026-11-09', '2026-11-16']],
        ];
    }

    #[DataProvider('apiaAdjustmentCases')]
    public function test_generated_skipped_civil_date_uses_explicit_adjustment_without_changing_later_cycles(string $adjustment, ?string $scheduled, ?int $shift, string $reason): void
    {
        $this->everyDay();

        $result = $this->schedule('2011-12-23', ['frequency' => 'weekly', 'timezone' => 'Pacific/Apia', 'adjustment' => $adjustment, 'count' => 3, 'max_shift_days' => 1]);

        $this->assertSame([['2011-12-23', '2011-12-23'], ['2011-12-30', $scheduled], ['2012-01-06', '2012-01-06']], $this->dates($result));
        $this->assertSame($shift, $result->occurrences[1]->shiftDays);
        $this->assertSame($reason, $result->occurrences[1]->reason);
    }

    public static function apiaAdjustmentCases(): array
    {
        return [['next', '2011-12-31', 1, 'shifted_next'], ['previous', '2011-12-29', -1, 'shifted_previous'], ['skip', null, null, 'closed_day']];
    }

    public function test_skipped_civil_date_consumes_shift_labels_and_does_not_expand_global_horizon(): void
    {
        $this->everyDay();
        config()->set('workdays.max_scan_days', 7);
        $skipped = $this->schedule('2011-12-23', ['frequency' => 'weekly', 'timezone' => 'Pacific/Apia', 'adjustment' => 'skip', 'count' => 2]);
        $this->assertSame('2011-12-30', $skipped->occurrences[1]->nominalDate);
        $this->assertNull($skipped->occurrences[1]->scheduledDate);
        $this->expectException(RuntimeException::class);

        $this->schedule('2011-12-23', ['frequency' => 'weekly', 'timezone' => 'Pacific/Apia', 'count' => 2, 'max_shift_days' => 1]);
    }

    public function test_shift_days_count_calendar_labels_across_dst_instead_of_elapsed_twenty_four_hour_periods(): void
    {
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-03-06' => 'Friday closure']);

        $result = $this->schedule('2026-03-06', ['timezone' => 'America/New_York', 'frequency' => 'weekly', 'count' => 1]);

        $this->assertSame('2026-03-09', $result->occurrences[0]->scheduledDate);
        $this->assertSame(3, $result->occurrences[0]->shiftDays);
    }

    public function test_datetime_anchor_uses_selected_local_date_and_does_not_retain_time_or_mutate_input(): void
    {
        $anchor = new DateTime('2026-11-01T22:00:12.123456+00:00');
        $before = $anchor->format('Y-m-d\TH:i:s.uP');

        $result = $this->schedule($anchor, ['timezone' => 'Asia/Tehran', 'frequency' => 'weekly', 'count' => 2]);

        $this->assertSame($before, $anchor->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-11-02', $result->anchorDate);
        $this->assertSame([['2026-11-02', '2026-11-02'], ['2026-11-09', '2026-11-09']], $this->dates($result));
        $serialized = $result->toArray();
        $anchor->modify('+2 months')->setTimezone(new DateTimeZone('Pacific/Apia'));
        $this->assertSame($serialized, $result->toArray());
    }

    public function test_business_date_with_explicitly_empty_working_hours_remains_eligible(): void
    {
        config()->set('workdays.profiles.warehouse.working_hours', []);
        config()->set('workdays.profiles.warehouse.special_working_hours', ['2026-11-02' => []]);

        $result = $this->schedule('2026-11-02', ['count' => 1]);

        $this->assertTrue(Workday::profile('warehouse')->isBusinessDay('2026-11-02'));
        $this->assertSame('2026-11-02', $result->occurrences[0]->scheduledDate);
        $this->assertSame('business_day', $result->occurrences[0]->reason);
    }

    #[DataProvider('recurringHolidayCases')]
    public function test_existing_gregorian_and_jalali_recurring_holidays_adjust_business_dates(string $calendar, string $key, string $anchor, string $expected): void
    {
        config()->set('workdays.profiles.warehouse.holidays.'.$calendar, [$key => 'Recurring closure']);

        $result = $this->schedule($anchor, ['count' => 1]);

        $this->assertSame($expected, $result->occurrences[0]->scheduledDate);
    }

    public static function recurringHolidayCases(): array
    {
        return [['gregorian', '11-02', '2026-11-02', '2026-11-03'], ['jalali', '04-01', '2026-06-22', '2026-06-23']];
    }

    public function test_hijri_holidays_still_adjust_gregorian_recurrence_without_adding_hijri_recurrence(): void
    {
        $this->everyDay();
        $holiday = (new HijriCalendarAdapter('mathematical', 0))->hijriDateToGregorian(1446, 8, 15);
        config()->set('workdays.profiles.warehouse.holidays.hijri', ['08-15' => 'Hijri closure']);

        $result = $this->schedule($holiday->toDateString(), ['count' => 1]);

        $this->assertSame($holiday->addDay()->toDateString(), $result->occurrences[0]->scheduledDate);
        $this->assertSame('gregorian', $result->calendar);
    }

    public function test_database_holiday_adjustments_reuse_one_profile_snapshot_without_writes(): void
    {
        $this->databaseStorage();
        WorkdaySpecialDate::create(['profile' => 'warehouse', 'date' => '2026-11-13', 'type' => 'holiday', 'title' => 'Stored Friday closure', 'is_active' => true]);
        WorkdaySpecialDate::create(['profile' => 'unrelated', 'date' => '2026-11-06', 'type' => 'holiday', 'title' => 'Other profile', 'is_active' => true]);
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $result = $this->schedule('2026-11-06', ['frequency' => 'weekly']);
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertSame('2026-11-16', $result->occurrences[1]->scheduledDate);
        $this->assertCount(8, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
            $this->assertContains('warehouse', $query['bindings']);
            $this->assertNotContains('unrelated', $query['bindings']);
        }
        $this->assertSame(2, WorkdaySpecialDate::count());
    }

    public function test_database_recurring_rules_are_used_and_config_holiday_maps_are_ignored_by_database_driver(): void
    {
        $this->databaseStorage();
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-16' => 'Config ignored']);
        WorkdayHolidayRule::create(['profile' => 'warehouse', 'calendar_type' => 'gregorian', 'month' => 11, 'day' => 13, 'title' => 'Annual closure', 'is_active' => true]);

        $result = $this->schedule('2026-11-06', ['frequency' => 'weekly', 'count' => 2]);

        $this->assertSame('2026-11-16', $result->occurrences[1]->scheduledDate);
    }

    public function test_chain_database_reopening_matches_ordinary_calendar_and_new_call_observes_deactivation(): void
    {
        $this->databaseStorage('chain');
        config()->set('workdays.profiles.warehouse.custom_holidays', ['2026-11-13' => 'Config closure']);
        $reopening = WorkdaySpecialDate::create(['profile' => 'warehouse', 'date' => '2026-11-13', 'type' => 'working_day', 'title' => 'Reopened', 'is_active' => true]);

        $open = $this->schedule('2026-11-13', ['count' => 1]);
        $this->assertTrue(Workday::profile('warehouse')->isBusinessDay('2026-11-13'));
        $reopening->update(['is_active' => false]);
        $closed = $this->schedule('2026-11-13', ['count' => 1]);

        $this->assertSame('2026-11-13', $open->occurrences[0]->scheduledDate);
        $this->assertSame('2026-11-16', $closed->occurrences[0]->scheduledDate);
    }

    public function test_inherited_weekends_holidays_and_extra_days_keep_child_database_identity_without_routing(): void
    {
        $this->databaseStorage('chain');
        $parent = $this->profile();
        $parent['holidays']['gregorian'] = ['11-20' => 'Recurring closure'];
        $parent['custom_holidays'] = ['2026-11-13' => 'Custom closure'];
        $parent['extra_working_days'] = ['2026-11-14' => 'Extra Saturday'];
        config()->set('workdays.profiles', ['parent' => $parent, 'child' => ['extends' => 'parent']]);
        config()->set('workdays.profile_routing', ['invalid' => true]);
        WorkdaySpecialDate::create(['profile' => 'parent', 'date' => '2026-11-06', 'type' => 'holiday', 'title' => 'Parent only', 'is_active' => true]);

        $result = $this->schedule('2026-11-06', ['profile' => 'child', 'frequency' => 'weekly']);

        $this->assertSame('child', $result->profile);
        $this->assertSame([['2026-11-06', '2026-11-06'], ['2026-11-13', '2026-11-14'], ['2026-11-20', '2026-11-23'], ['2026-11-27', '2026-11-27']], $this->dates($result));
        WorkdaySpecialDate::create(['profile' => 'child', 'date' => '2026-11-27', 'type' => 'holiday', 'title' => 'Child closure', 'is_active' => true]);
        $changed = $this->schedule('2026-11-06', ['profile' => 'child', 'frequency' => 'weekly']);
        $this->assertSame('2026-11-30', $changed->occurrences[3]->scheduledDate);
    }

    public function test_missing_database_tables_are_not_disguised_as_skipped_or_closed_occurrences(): void
    {
        config()->set('workdays.storage.driver', 'database');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migrations');

        $this->schedule('2026-11-02', ['count' => 1]);
    }

    public function test_result_and_occurrences_are_readonly_and_have_stable_date_only_serialization(): void
    {
        $result = $this->schedule('2026-11-02', ['count' => 1]);

        $this->assertSame([
            'profile' => 'warehouse', 'timezone' => 'UTC', 'calendar' => 'gregorian', 'frequency' => 'monthly',
            'interval' => 1, 'anchor_date' => '2026-11-02', 'requested_count' => 1,
            'occurrences' => [[
                'cycle_index' => 0, 'nominal_period' => '2026-11', 'nominal_date' => '2026-11-02',
                'scheduled_date' => '2026-11-02', 'status' => 'scheduled', 'reason' => 'business_day', 'shift_days' => 0, 'collides' => false,
            ]],
        ], $result->toArray());
        $this->assertInstanceOf(RecurringOccurrence::class, $result->occurrences[0]);
        $this->assertSame($result->toArray(), json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->expectException(Error::class);

        $result->occurrences[0]->scheduledDate = '2026-11-03';
    }

    public function test_result_occurrence_array_is_readonly_and_detached_from_external_references(): void
    {
        $entry = new RecurringOccurrence(0, '2026-11', '2026-11-02', '2026-11-02', 'scheduled', 'business_day', 0);
        $list = [&$entry];
        $result = new RecurringScheduleResult('warehouse', 'UTC', 'gregorian', 'monthly', 1, '2026-11-02', 1, $list);
        $entry = new RecurringOccurrence(0, '2026-11', '2026-11-03', '2026-11-03', 'scheduled', 'business_day', 0);

        $this->assertSame('2026-11-02', $result->occurrences[0]->scheduledDate);
        $this->expectException(Error::class);

        $result->occurrences[] = $entry;
    }

    public function test_same_options_are_deterministic_detached_and_shared_by_manager_and_facade(): void
    {
        $timezone = 'UTC';
        $options = $this->scheduleOptions(['count' => 1]);
        $options['timezone'] = &$timezone;
        $before = config('workdays');
        $manager = app(WorkdayManager::class)->recurringSchedule('2026-11-02', $options);
        $facade = Workday::recurringSchedule('2026-11-02', $options);
        $serialized = $manager->toArray();

        $this->assertSame($serialized, $facade->toArray());
        $this->assertSame('UTC', $options['timezone']);
        $this->assertSame($before, config('workdays'));
        $timezone = 'Asia/Tehran';
        $this->assertSame('UTC', $manager->timezone);
        $this->assertSame($serialized, $manager->toArray());
    }

    public function test_only_one_selected_calculator_is_resolved_for_sixty_cycles(): void
    {
        $configuration = $this->profile();
        $configuration['weekends'] = [];
        $resolved = [];
        $generator = new RecurringScheduleGenerator(function (string $profile) use (&$resolved, $configuration): BusinessDayCalculator {
            $resolved[] = $profile;

            return new BusinessDayCalculator($profile, $configuration);
        });

        $result = $generator->generate('2026-01-31', $this->scheduleOptions(['count' => 60]));

        $this->assertCount(60, $result->occurrences);
        $this->assertSame(['warehouse'], $resolved);
    }

    public function test_invalid_future_nominal_period_is_rejected_before_resolving_any_calculator(): void
    {
        config()->set('workdays.max_scan_days', 7);
        $resolved = 0;
        $generator = new RecurringScheduleGenerator(function () use (&$resolved): never {
            $resolved++;
            throw new RuntimeException('Calculator resolution should not be reached.');
        });
        try {
            $generator->generate('2026-11-02', $this->scheduleOptions(['frequency' => 'weekly', 'count' => 3]));
            $this->fail('Out-of-horizon recurrence was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('horizon', $exception->getMessage());
        }
        $this->assertSame(0, $resolved);
    }

    public function test_internal_chronology_guard_rejects_reversed_scheduled_cycles_instead_of_reordering_them(): void
    {
        // Fixed next/previous projections are nondecreasing for stable calendars; exercise
        // the defensive invariant directly rather than inventing an inconsistent holiday fixture.
        $generator = new RecurringScheduleGenerator(static fn (): never => throw new RuntimeException('Unused resolver.'));
        $guard = new ReflectionMethod(RecurringScheduleGenerator::class, 'assertChronological');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/(?=.*0)(?=.*1)(?=.*chronologically)/s');

        $guard->invoke($generator, [
            new RecurringOccurrence(0, '2026-11-02', '2026-11-02', '2026-11-10', 'shifted', 'shifted_next', 8),
            new RecurringOccurrence(1, '2026-11-09', '2026-11-09', '2026-11-09', 'scheduled', 'business_day', 0),
        ]);
    }

    private function profile(): array
    {
        return ['weekends' => ['Saturday', 'Sunday'], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => []];
    }

    private function everyDay(): void
    {
        config()->set('workdays.profiles.warehouse.weekends', []);
    }

    private function scheduleOptions(array $overrides = []): array
    {
        return array_replace(['profile' => 'warehouse', 'timezone' => 'UTC', 'frequency' => 'monthly', 'interval' => 1, 'count' => 4, 'adjustment' => 'next'], $overrides);
    }

    private function schedule(string|\DateTimeInterface $anchor, array $overrides = []): RecurringScheduleResult
    {
        return Workday::recurringSchedule($anchor, $this->scheduleOptions($overrides));
    }

    private function dates(RecurringScheduleResult $result): array
    {
        return array_map(static fn (RecurringOccurrence $occurrence): array => [$occurrence->nominalDate, $occurrence->scheduledDate], $result->occurrences);
    }

    private function closeDates(string $first, int $count): void
    {
        $closed = [];
        $anchor = CarbonImmutable::parse($first, 'UTC');
        foreach (range(0, $count - 1) as $index) {
            $closed[$anchor->addDays($index)->toDateString()] = 'Closed';
        }
        config()->set('workdays.profiles.warehouse.custom_holidays', $closed);
    }

    private function collisionFixture(): void
    {
        $this->everyDay();
        $this->closeDates('2026-11-02', 7);
    }

    private function databaseStorage(string $driver = 'database'): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
        config()->set('workdays.storage.driver', $driver);
    }
}
