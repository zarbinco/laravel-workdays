<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTime;
use DateTimeZone;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\Holiday;
use Zarbinco\LaravelWorkdays\Data\ReverseDeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Delivery\DeliveryPlanner;
use Zarbinco\LaravelWorkdays\Delivery\ReverseDeliveryPlanner;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Holidays\LogicalHolidayProvider;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class ReverseDeliveryPlanningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.definitions.default_profile', 'warehouse');
        config()->set('workdays.driver', 'config');
        config()->set('workdays.definitions.include_start_date', false);
        config()->set('workdays.max_scan_days', 31);
        config()->set('workdays.definitions.hijri', ['method' => 'mathematical', 'adjustment' => 0]);
        config()->set('workdays.definitions.profiles', [
            'warehouse' => $this->profile($this->hours([['09:00', '17:00']])) + ['cutoff' => ['timezone' => 'UTC', 'time' => '14:00']],
            'carrier' => $this->profile($this->hours([['12:00', '18:00']])),
            'last_mile' => $this->profile($this->hours([['09:00', '17:00']])),
        ]);
    }

    public function test_a_simple_work_stage_returns_latest_exact_receipt_and_verified_forward_result(): void
    {
        $plan = $this->workPlan(120);

        $result = $this->reverse('2026-11-02T16:00:00Z', $plan);

        $this->assertSolution($result, '2026-11-02T14:00:00.000000+00:00', $plan);
        $this->assertSame('2026-11-02T16:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
        $this->assertSame(0, $result->slackMicroseconds);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_b_split_windows_reverse_without_counting_the_lunch_break(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00'], ['13:00', '17:00']]));
        $plan = $this->workPlan(120);

        $result = $this->reverse('2026-11-02T14:00:00Z', $plan);

        // Refactor 03 removes the old whole-second plateau, preserving every working microsecond.
        $this->assertSolution($result, '2026-11-02T11:00:00.000000+00:00', $plan);
        $this->assertSame('2026-11-02T14:00:00.999999+00:00', $this->format(Workday::planDelivery('2026-11-02T11:00:00.999999Z', $plan)->estimatedCompletionAt));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_overnight_permission_on_a_daytime_schedule_keeps_the_exact_eleven_oclock_latest_receipt(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.allow_overnight_working_hours', true);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00'], ['13:00', '17:00']]));
        $plan = $this->workPlan(120);

        $result = $this->reverse('2026-11-02T14:00:00Z', $plan, 2);

        $this->assertSolution($result, '2026-11-02T11:00:00.000000+00:00', $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    #[DataProvider('handoffAcceptanceCases')]
    public function test_c_d_handoff_includes_common_opening_and_excludes_common_closing(string $target, string $latest): void
    {
        $plan = $this->handoffPlan();

        $result = $this->reverse($target, $plan);

        $this->assertSolution($result, $latest, $plan);
        $this->assertSame($latest, $this->format($result->firstStageStartsAt));
        if ($result->latestOrderAt->lessThan($result->targetCompletionAt)) {
            $this->assertNextMicrosecondMisses($result, $plan);
        }
    }

    public static function handoffAcceptanceCases(): array
    {
        return [
            'C common opening' => ['2026-11-02T12:00:00Z', '2026-11-02T12:00:00.000000+00:00'],
            'D exclusive closing' => ['2026-11-02T17:00:00Z', '2026-11-02T16:59:59.999999+00:00'],
        ];
    }

    #[DataProvider('includeStartDateModes')]
    public function test_e_business_days_preserve_clock_and_count_strictly_future_dates_without_working_hours(bool $includeStart): void
    {
        config()->set('workdays.definitions.include_start_date', $includeStart);
        config()->set('workdays.definitions.profiles.carrier', ['weekends' => ['Saturday', 'Sunday'], 'holidays' => []]);
        $plan = $this->dayPlan(2);

        $result = $this->reverse('2026-11-04T15:30:12.123456Z', $plan);

        $this->assertSolution($result, '2026-11-02T15:30:12.123456+00:00', $plan);
        $this->assertSame('2026-11-02T15:30:12.123456+00:00', $this->format($result->firstStageStartsAt));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public static function includeStartDateModes(): array
    {
        return [[false], [true]];
    }

    public function test_f_receipt_after_yesterdays_cutoff_can_still_complete_todays_processing(): void
    {
        $plan = $this->workPlan(120);
        $plan['cutoff_profile'] = 'warehouse';

        $result = $this->reverse('2026-11-03T11:00:00Z', $plan);
        $afterPreviousCutoff = Workday::planDelivery('2026-11-02T14:00:00Z', $plan);

        $this->assertSolution($result, '2026-11-03T09:00:00.000000+00:00', $plan);
        $this->assertSame('2026-11-03T11:00:00.000000+00:00', $this->format($afterPreviousCutoff->estimatedCompletionAt));
        $this->assertFalse($afterPreviousCutoff->cutoffResult->sameDayEligible);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_g_latest_same_day_receipt_stays_one_microsecond_before_exclusive_cutoff(): void
    {
        $plan = $this->workPlan(120);
        $plan['cutoff_profile'] = 'warehouse';

        $result = $this->reverse('2026-11-02T17:00:00Z', $plan);

        $this->assertSolution($result, '2026-11-02T13:59:59.999999+00:00', $plan);
        $this->assertTrue($result->forwardResult->cutoffResult->sameDayEligible);
        $this->assertSame(3600000001, $result->slackMicroseconds);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_h_reference_multistage_plan_preserves_every_forward_stage_timestamp(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00'], ['13:00', '17:00']]));
        $plan = $this->referencePlan();

        $result = $this->reverse('2026-07-01T16:30:00Z', $plan);

        $this->assertSolution($result, '2026-06-29T13:30:00.000000+00:00', $plan);
        $this->assertSame([
            ['2026-06-29T13:30:00.000000+00:00', '2026-06-29T13:30:00.000000+00:00', '2026-06-29T15:30:00.000000+00:00'],
            ['2026-06-29T15:30:00.000000+00:00', '2026-06-29T15:30:00.000000+00:00', '2026-06-29T15:30:00.000000+00:00'],
            ['2026-06-29T15:30:00.000000+00:00', '2026-06-29T15:30:00.000000+00:00', '2026-07-01T15:30:00.000000+00:00'],
            ['2026-07-01T15:30:00.000000+00:00', '2026-07-01T15:30:00.000000+00:00', '2026-07-01T16:30:00.000000+00:00'],
        ], array_map(fn ($stage): array => [$this->format($stage->readyAt), $this->format($stage->startedAt), $this->format($stage->completedAt)], $result->forwardResult->stages));
        $this->assertSame(0, $result->slackMicroseconds);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_i_overnight_preparation_reverses_through_midnight_without_double_counting(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.allow_overnight_working_hours', true);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Monday' => [['22:00', '06:00']]]);
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-03' => 'Receiving closure']);
        $plan = $this->workPlan(180);

        $result = $this->reverse('2026-11-03T02:00:00Z', $plan);

        $this->assertSolution($result, '2026-11-02T23:00:00.000000+00:00', $plan);
        $this->assertFalse(Workday::profile('warehouse')->isBusinessDay('2026-11-03'));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_j_relevant_holiday_moves_latest_receipt_earlier_while_unrelated_holiday_does_not(): void
    {
        $plan = $this->workPlan(120);
        $before = $this->reverse('2026-11-02T16:00:00Z', $plan);
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-03' => 'After deadline']);
        $unrelated = $this->reverse('2026-11-02T16:00:00Z', $plan);
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-02' => 'Relevant closure']);

        $closed = $this->reverse('2026-11-02T16:00:00Z', $plan);

        $this->assertSame($this->format($before->latestOrderAt), $this->format($unrelated->latestOrderAt));
        $this->assertSolution($closed, '2026-10-30T15:00:00.000000+00:00', $plan);
        $this->assertNextMicrosecondMisses($closed, $plan);
    }

    public function test_k_valid_fully_closed_calendar_returns_explicit_nullable_infeasibility(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.special_working_hours', ['2026-11-01' => [], '2026-11-02' => []]);

        $result = $this->reverse('2026-11-02T16:00:00Z', $this->workPlan(120), 1);

        $this->assertFalse($result->feasible);
        foreach (['latestOrderAt', 'planningStartAt', 'firstStageStartsAt', 'estimatedCompletionAt', 'slackMicroseconds', 'forwardResult'] as $field) {
            $this->assertNull($result->{$field});
        }
        $this->assertSame('2026-11-01T00:00:00.000000+00:00', $this->format($result->searchStartAt));
        $this->assertSame([
            'timezone' => 'UTC', 'target_completion_at' => '2026-11-02T16:00:00.000000+00:00',
            'search_start_at' => '2026-11-01T00:00:00.000000+00:00', 'feasible' => false,
            'latest_order_at' => null, 'planning_start_at' => null, 'first_stage_starts_at' => null,
            'estimated_completion_at' => null, 'slack_microseconds' => null, 'forward_result' => null,
        ], $result->toArray());
    }

    public function test_mandatory_fall_back_regression_searches_later_disconnected_feasible_region(): void
    {
        config()->set('workdays.definitions.profiles.carrier', ['weekends' => [], 'holidays' => []]);
        $plan = $this->dayPlan(1, 'America/New_York');
        $target = '2026-11-02T01:15:00-05:00';

        $result = $this->reverse($target, $plan, 2);

        $this->assertSolution($result, '2026-11-01T06:15:00.000000+00:00', $plan);
        $this->assertSame('2026-11-01T01:15:00.000000-05:00', $result->latestOrderAt->setTimezone('America/New_York')->format('Y-m-d\TH:i:s.uP'));
        $earlierInfeasible = Workday::planDelivery('2026-11-01T01:45:00-04:00', $plan);
        $laterFeasible = Workday::planDelivery('2026-11-01T01:00:00-05:00', $plan);
        $this->assertTrue($earlierInfeasible->estimatedCompletionAt->greaterThan($result->targetCompletionAt));
        $this->assertTrue($laterFeasible->estimatedCompletionAt->lessThan($result->targetCompletionAt));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    #[DataProvider('workBoundaryCases')]
    public function test_work_reverse_respects_zero_minutes_closing_breaks_and_fractional_targets(int $minutes, string $target, string $latest, string $completion): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00'], ['13:00', '17:00']]));
        $plan = $this->workPlan($minutes);

        $result = $this->reverse($target, $plan, 2);

        $this->assertSolution($result, $latest, $plan);
        $this->assertSame($completion, $this->format($result->estimatedCompletionAt));
        if ($result->latestOrderAt->lessThan($result->targetCompletionAt)) {
            $this->assertNextMicrosecondMisses($result, $plan);
        }
    }

    public static function workBoundaryCases(): array
    {
        return [
            'zero at opening' => [0, '2026-11-02T09:00:00Z', '2026-11-02T09:00:00.000000+00:00', '2026-11-02T09:00:00.000000+00:00'],
            'zero at closing' => [0, '2026-11-02T17:00:00Z', '2026-11-02T16:59:59.999999+00:00', '2026-11-02T16:59:59.999999+00:00'],
            'work completes at closing' => [120, '2026-11-02T17:00:00Z', '2026-11-02T15:00:00.000000+00:00', '2026-11-02T17:00:00.000000+00:00'],
            'deadline during lunch' => [60, '2026-11-02T12:30:00Z', '2026-11-02T11:00:00.000000+00:00', '2026-11-02T12:00:00.000000+00:00'],
            'deadline after closing' => [120, '2026-11-02T18:00:00Z', '2026-11-02T15:00:00.000000+00:00', '2026-11-02T17:00:00.000000+00:00'],
            'fractional inside window' => [60, '2026-11-02T15:00:12.123456Z', '2026-11-02T14:00:12.123456+00:00', '2026-11-02T15:00:12.123456+00:00'],
        ];
    }

    #[DataProvider('precisionModes')]
    public function test_cross_break_latest_receipt_has_the_same_precision_for_absent_false_and_true_permission(?bool $enhanced, string $latest, string $completion): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00'], ['13:00', '17:00']]));
        if ($enhanced !== null) {
            config()->set('workdays.definitions.profiles.warehouse.allow_overnight_working_hours', $enhanced);
        }
        $plan = $this->workPlan(120);

        $result = $this->reverse('2026-11-02T14:00:30.123456Z', $plan, 2);

        $this->assertSolution($result, $latest, $plan);
        $this->assertSame($completion, $this->format($result->estimatedCompletionAt));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public static function precisionModes(): array
    {
        return [
            [null, '2026-11-02T11:00:30.123456+00:00', '2026-11-02T14:00:30.123456+00:00'],
            [false, '2026-11-02T11:00:30.123456+00:00', '2026-11-02T14:00:30.123456+00:00'],
            [true, '2026-11-02T11:00:30.123456+00:00', '2026-11-02T14:00:30.123456+00:00'],
        ];
    }

    #[DataProvider('scheduleOverrideCases')]
    public function test_half_days_extra_days_special_and_seasonal_schedules_change_latest_receipt(array $configuration, string $target, string $latest): void
    {
        foreach ($configuration as $key => $value) {
            config()->set('workdays.definitions.profiles.warehouse.'.$key, $value);
        }
        $plan = $this->workPlan(120);

        $result = $this->reverse($target, $plan, 7);

        $this->assertSolution($result, $latest, $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public static function scheduleOverrideCases(): array
    {
        return [
            'half day' => [['working_hours.Monday' => [['09:00', '12:00']]], '2026-11-02T16:00:00Z', '2026-11-02T10:00:00.000000+00:00'],
            'special shorter day' => [['special_working_hours' => ['2026-11-02' => [['10:00', '13:00']]]], '2026-11-02T16:00:00Z', '2026-11-02T11:00:00.000000+00:00'],
            'seasonal extension' => [['seasonal_working_hours' => [['from' => '2026-11-02', 'through' => '2026-11-02', 'weekdays' => ['Monday' => [['09:00', '20:00']]]]]], '2026-11-02T19:00:00Z', '2026-11-02T17:00:00.000000+00:00'],
            'extra Saturday' => [['extra_working_days' => ['2026-11-07' => 'Reopened'], 'extra_working_day_hours' => [['10:00', '14:00']]], '2026-11-07T13:00:00Z', '2026-11-07T11:00:00.000000+00:00'],
            'consecutive closures' => [['custom_holidays' => ['2026-11-02' => 'Closed', '2026-10-30' => 'Closed']], '2026-11-02T16:00:00Z', '2026-10-29T15:00:00.000000+00:00'],
        ];
    }

    public function test_latest_solution_can_equal_the_inclusive_earliest_search_instant(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.weekends', []);
        config()->set('workdays.definitions.profiles.warehouse.allow_overnight_working_hours', true);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', array_fill_keys(range(1, 7), [['00:00', '24:00']]));
        $plan = $this->workPlan(1440);

        $result = $this->reverse('2026-11-03T00:00:00Z', $plan, 1);

        $this->assertSolution($result, '2026-11-02T00:00:00.000000+00:00', $plan);
        $this->assertSame($this->format($result->searchStartAt), $this->format($result->latestOrderAt));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_solution_outside_lookback_is_not_returned_and_larger_explicit_lookback_can_find_it(): void
    {
        $plan = $this->workPlan(120);

        $short = $this->reverse('2026-11-02T09:30:00Z', $plan, 1);
        $longer = $this->reverse('2026-11-02T09:30:00Z', $plan, 3);

        $this->assertFalse($short->feasible);
        $this->assertNull($short->latestOrderAt);
        $this->assertSolution($longer, '2026-10-30T15:30:00.000000+00:00', $plan);
    }

    public function test_existing_global_forward_horizon_is_not_extended_for_reverse_candidates(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $plan = $this->dayPlan(2);

        $result = $this->reverse('2026-11-04T15:00:00Z', $plan, 1);

        $this->assertFalse($result->feasible);
        $this->assertNull($result->forwardResult);
        $this->assertSame(1, config('workdays.max_scan_days'));
    }

    public function test_cumulative_later_stage_horizon_failure_is_infeasibility_not_a_fabricated_deadline(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $plan = $this->dayPlan(1);
        $plan['stages'][] = ['id' => 'second_transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 1];

        $result = $this->reverse('2026-11-04T15:00:00Z', $plan, 1);

        $this->assertFalse($result->feasible);
    }

    public function test_zero_day_identity_can_return_the_exact_target_without_working_hours(): void
    {
        config()->set('workdays.definitions.profiles.carrier', ['weekends' => ['Saturday', 'Sunday']]);
        $plan = $this->dayPlan(0);

        $result = $this->reverse('2026-11-07T23:59:59.123456Z', $plan, 1);

        $this->assertSolution($result, '2026-11-07T23:59:59.123456+00:00', $plan);
        $this->assertSame(0, $result->slackMicroseconds);
    }

    #[DataProvider('invalidLookbackOptions')]
    public function test_reverse_options_require_a_bounded_strict_integer_and_reject_unknown_keys(array $options): void
    {
        $this->expectException(WorkdayConfigurationException::class);

        Workday::reversePlanDelivery('2026-11-02T16:00:00Z', $this->workPlan(120), $options);
    }

    public static function invalidLookbackOptions(): array
    {
        $cases = [[], ['lookback_days' => 14, 'unexpected' => true]];
        foreach ([0, -1, 32, PHP_INT_MAX, '14', 14.0, true, false, null, []] as $value) {
            $cases[] = ['lookback_days' => $value];
        }

        return array_map(static fn (array $options): array => [$options], $cases);
    }

    public function test_lookback_must_not_exceed_the_configured_forward_scan_limit(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->expectException(WorkdayConfigurationException::class);

        $this->reverse('2026-11-02T16:00:00Z', $this->workPlan(120), 2);
    }

    public function test_maximum_thirty_one_day_lookback_is_accepted_without_config_mutation(): void
    {
        $before = config('workdays');

        $result = $this->reverse('2026-11-02T16:00:00Z', $this->dayPlan(0), 31);

        $this->assertTrue($result->feasible);
        $this->assertSame('2026-10-02T00:00:00.000000+00:00', $this->format($result->searchStartAt));
        $this->assertSame($before, config('workdays'));
    }

    #[DataProvider('invalidTargetTimestamps')]
    public function test_target_requires_a_strict_absolute_supported_datetime(string $target, string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reverse($target, $this->workPlan(120, timezone: $timezone), 1);
    }

    public static function invalidTargetTimestamps(): array
    {
        $cases = array_map(static fn (string $value): array => [$value, 'UTC'], [
            '', 'tomorrow', '+1 day', '2026-11-02', '2026-02-30T16:00:00Z', '2026-11-02T24:00:00Z',
            '2026-11-02T16:00:00+24:00', '2026-11-02T16:00:00+03:60', '2026-11-02T16:00:00+0330',
            '2026-11-02T16:00:00', '2026-11-02 16:00:00Z', '2026-11-02T16:00:00.1234567Z',
        ]);
        $cases[] = ['2026-11-01 01:30', 'America/New_York'];
        $cases[] = ['2026-03-08 02:30', 'America/New_York'];

        return $cases;
    }

    #[DataProvider('invalidPlanMutations')]
    public function test_reverse_preserves_existing_plan_validation_and_does_not_disguise_errors_as_infeasibility(string $mutation): void
    {
        $plan = $this->workPlan(120);
        switch ($mutation) {
            case 'bad timezone': $plan['timezone'] = 'Mars/Olympus';
                break;
            case 'missing timezone': unset($plan['timezone']);
                break;
            case 'bad mapping': $plan['profile_timezones']['warehouse'] = '+00:00';
                break;
            case 'missing mapping': $plan['profile_timezones'] = [];
                break;
            case 'unknown profile': $plan['stages'][0]['profile'] = 'missing';
                $plan['profile_timezones']['missing'] = 'UTC';
                break;
            case 'empty stages': $plan['stages'] = [];
                break;
            case 'too many stages': $plan['stages'] = array_map(static fn (int $id): array => ['id' => 'stage'.$id, 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 0], range(1, 33));
                break;
            case 'unknown type': $plan['stages'][0]['type'] = 'teleport';
                break;
            case 'empty id': $plan['stages'][0]['id'] = '';
                break;
            case 'duplicate id': $plan['stages'][] = $plan['stages'][0];
                break;
            case 'negative minutes': $plan['stages'][0]['minutes'] = -1;
                break;
            case 'numeric string minutes': $plan['stages'][0]['minutes'] = '120';
                break;
            case 'float minutes': $plan['stages'][0]['minutes'] = 120.0;
                break;
            case 'missing minutes': unset($plan['stages'][0]['minutes']);
                break;
            case 'unknown stage field': $plan['stages'][0]['capacity'] = 1;
                break;
            case 'unknown plan field': $plan['delivery_slot'] = true;
                break;
        }
        $this->expectException(WorkdayConfigurationException::class);

        $this->reverse('2026-11-02T16:00:00Z', $plan, 1);
    }

    public static function invalidPlanMutations(): array
    {
        return array_map(static fn (string $name): array => [$name], [
            'bad timezone', 'missing timezone', 'bad mapping', 'missing mapping', 'unknown profile', 'empty stages', 'too many stages',
            'unknown type', 'empty id', 'duplicate id', 'negative minutes', 'numeric string minutes', 'float minutes', 'missing minutes', 'unknown stage field', 'unknown plan field',
        ]);
    }

    public function test_handoff_compares_independent_profile_timezones_at_the_common_closing_instant(): void
    {
        config()->set('workdays.definitions.profiles.carrier.working_hours', $this->hours([['10:00', '18:00']]));
        $plan = $this->handoffPlan();
        $plan['profile_timezones'] = ['warehouse' => 'Asia/Tehran', 'carrier' => 'Asia/Dubai'];

        $result = $this->reverse('2026-11-02T13:30:00Z', $plan, 1);

        $this->assertSolution($result, '2026-11-02T13:29:59.999999+00:00', $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_handoff_without_shared_working_time_returns_infeasible_instead_of_inventing_overlap(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00']]));
        config()->set('workdays.definitions.profiles.carrier.working_hours', $this->hours([['13:00', '17:00']]));

        $result = $this->reverse('2026-11-02T17:00:00Z', $this->handoffPlan(), 3);

        $this->assertFalse($result->feasible);
        $this->assertNull($result->latestOrderAt);
    }

    public function test_handoff_after_midnight_respects_overnight_carry_in_and_half_open_closing(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.allow_overnight_working_hours', true);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Monday' => [['22:00', '06:00']]]);
        config()->set('workdays.definitions.profiles.carrier.working_hours', ['Tuesday' => [['00:00', '04:00']]]);
        $plan = $this->handoffPlan();

        $result = $this->reverse('2026-11-03T04:00:00Z', $plan, 1);

        $this->assertSolution($result, '2026-11-03T03:59:59.999999+00:00', $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_holiday_in_one_handoff_profile_removes_the_current_days_overlap(): void
    {
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-02' => 'Carrier closure']);
        $plan = $this->handoffPlan();

        $result = $this->reverse('2026-11-02T17:00:00Z', $plan, 3);

        $this->assertSolution($result, '2026-10-30T16:59:59.999999+00:00', $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    #[DataProvider('cutoffOverrideCases')]
    public function test_cutoff_weekday_overrides_disabled_days_and_empty_future_eligibility_are_preserved(array $weekdays, string $target, string $latest): void
    {
        config()->set('workdays.definitions.profiles.warehouse.cutoff.weekdays', $weekdays);
        $plan = $this->workPlan(120);
        $plan['cutoff_profile'] = 'warehouse';

        $result = $this->reverse($target, $plan, 7);

        $this->assertSolution($result, $latest, $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public static function cutoffOverrideCases(): array
    {
        return [
            'earlier Monday cutoff' => [['Monday' => '12:00'], '2026-11-02T17:00:00Z', '2026-11-02T11:59:59.999999+00:00'],
            'disabled Monday' => [['Monday' => null], '2026-11-02T17:00:00Z', '2026-10-30T13:59:59.999999+00:00'],
            'future Tuesday has empty midnight eligibility' => [['Tuesday' => '00:00'], '2026-11-03T17:00:00Z', '2026-11-02T13:59:59.999999+00:00'],
        ];
    }

    public function test_extra_working_day_reopens_a_holiday_for_cutoff_and_working_time(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-02' => 'Closed']);
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days', ['2026-11-02' => 'Reopened']);
        $plan = $this->workPlan(120);
        $plan['cutoff_profile'] = 'warehouse';

        $result = $this->reverse('2026-11-02T16:00:00Z', $plan, 1);

        $this->assertSolution($result, '2026-11-02T13:59:59.999999+00:00', $plan);
        $this->assertTrue($result->forwardResult->cutoffResult->sameDayEligible);
    }

    public function test_receipt_planning_start_and_actual_first_stage_start_remain_distinct(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.cutoff.weekdays', ['Monday' => null]);
        $plan = $this->workPlan(0);
        $plan['cutoff_profile'] = 'warehouse';

        $forward = Workday::planDelivery('2026-11-02T20:00:00Z', $plan);
        $result = $this->reverse('2026-11-03T09:00:00Z', $plan, 1);

        $this->assertSame('2026-11-03T00:00:00.000000+00:00', $this->format($forward->planningStartAt));
        $this->assertSame('2026-11-03T09:00:00.000000+00:00', $this->format($forward->stages[0]->startedAt));
        $this->assertSolution($result, '2026-11-03T09:00:00.000000+00:00', $plan);
    }

    public function test_business_days_can_begin_on_weekend_and_skip_a_future_holiday(): void
    {
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-11-03' => 'Tuesday closure']);
        $plan = $this->dayPlan(2);

        $result = $this->reverse('2026-11-04T15:00:00Z', $plan, 7);

        $this->assertSolution($result, '2026-11-01T15:00:00.000000+00:00', $plan);
        $this->assertFalse(Workday::profile('carrier')->isBusinessDay($result->latestOrderAt));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_business_day_extra_saturday_changes_latest_receipt_without_requiring_saturday_hours(): void
    {
        $plan = $this->dayPlan(2);
        $ordinary = $this->reverse('2026-11-09T15:00:00Z', $plan, 7);
        config()->set('workdays.definitions.profiles.carrier.extra_working_days', ['2026-11-07' => 'Saturday transit']);

        $extra = $this->reverse('2026-11-09T15:00:00Z', $plan, 7);

        $this->assertSame('2026-11-05T15:00:00.000000+00:00', $this->format($ordinary->latestOrderAt));
        $this->assertSolution($extra, '2026-11-06T15:00:00.000000+00:00', $plan);
    }

    public function test_spring_gap_candidate_failure_does_not_hide_the_latest_earlier_valid_business_day_region(): void
    {
        config()->set('workdays.definitions.profiles.carrier', ['weekends' => [], 'holidays' => []]);
        $plan = $this->dayPlan(1, 'America/New_York');

        $result = $this->reverse('2026-03-08T01:59:00-05:00', $plan, 2);

        $this->assertSolution($result, '2026-03-07T06:59:00.000000+00:00', $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Workday::planDelivery('2026-03-07T02:30:00-05:00', $plan);
    }

    public function test_skipped_apia_date_neither_counts_as_a_business_day_nor_extends_lookback(): void
    {
        config()->set('workdays.definitions.profiles.carrier', ['weekends' => [], 'holidays' => []]);
        $plan = $this->dayPlan(1, 'Pacific/Apia');
        $target = '2011-12-31T10:00:00+14:00';

        $short = $this->reverse($target, $plan, 1);
        $longer = $this->reverse($target, $plan, 2);

        $this->assertFalse($short->feasible);
        $this->assertSame('2011-12-30T10:00:00.000000+00:00', $this->format($short->searchStartAt));
        $this->assertSolution($longer, '2011-12-29T20:00:00.000000+00:00', $plan);
        $this->assertNextMicrosecondMisses($longer, $plan);
    }

    public function test_earliest_search_date_with_skipped_midnight_starts_at_its_first_actual_instant(): void
    {
        $plan = $this->dayPlan(0, 'America/Santiago');

        $result = $this->reverse('2026-09-07T10:00:00-03:00', $plan, 1);

        $this->assertSame('2026-09-06T04:00:00.000000+00:00', $this->format($result->searchStartAt));
        $this->assertSolution($result, '2026-09-07T13:00:00.000000+00:00', $plan);
    }

    #[DataProvider('wallClockDstWorkCases')]
    public function test_reverse_daytime_dst_windows_use_identical_wall_clock_semantics_with_or_without_permission(bool $enhanced, string $target, string $latest): void
    {
        config()->set('workdays.definitions.profiles.warehouse.weekends', []);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Sunday' => [['00:00', '04:00']]]);
        if ($enhanced) {
            config()->set('workdays.definitions.profiles.warehouse.allow_overnight_working_hours', true);
        }
        $plan = $this->workPlan(60, timezone: 'America/New_York');

        $result = $this->reverse($target, $plan, 1);

        $this->assertSolution($result, $latest, $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public static function wallClockDstWorkCases(): array
    {
        return [
            'plain fall closes at04' => [false, '2026-11-01T03:30:00-05:00', '2026-11-01T07:30:00.000000+00:00'],
            'permitted fall closes at04' => [true, '2026-11-01T03:30:00-05:00', '2026-11-01T07:30:00.000000+00:00'],
            'plain spring closes at04' => [false, '2026-03-08T04:30:00-04:00', '2026-03-08T07:00:00.000000+00:00'],
            'permitted spring closes at04' => [true, '2026-03-08T04:30:00-04:00', '2026-03-08T07:00:00.000000+00:00'],
        ];
    }

    #[DataProvider('invalidSelectedConfigurations')]
    public function test_invalid_selected_profile_or_cutoff_configuration_throws_instead_of_reporting_infeasible(string $mutation): void
    {
        $plan = $this->workPlan(120);
        switch ($mutation) {
            case 'missing hours':
                $profile = config('workdays.definitions.profiles.warehouse');
                unset($profile['working_hours']);
                config()->set('workdays.definitions.profiles.warehouse', $profile);
                break;
            case 'malformed hours': config()->set('workdays.definitions.profiles.warehouse.working_hours', '09:00-17:00');
                break;
            case 'bad window': config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Monday' => [['17:00', '09:00']]]);
                break;
            case 'missing parent': config()->set('workdays.definitions.profiles.warehouse', ['extends' => 'missing']);
                break;
            case 'missing cutoff':
                $plan['cutoff_profile'] = 'warehouse';
                $profile = config('workdays.definitions.profiles.warehouse');
                unset($profile['cutoff']);
                config()->set('workdays.definitions.profiles.warehouse', $profile);
                break;
            case 'cutoff mismatch':
                $plan['cutoff_profile'] = 'warehouse';
                config()->set('workdays.definitions.profiles.warehouse.cutoff.timezone', 'Asia/Tehran');
                break;
            case 'invalid cutoff':
                $plan['cutoff_profile'] = 'warehouse';
                config()->set('workdays.definitions.profiles.warehouse.cutoff.time', '25:00');
                break;
        }
        $this->expectException(WorkdayConfigurationException::class);

        $this->reverse('2026-11-02T16:00:00Z', $plan, 1);
    }

    public static function invalidSelectedConfigurations(): array
    {
        return array_map(static fn (string $name): array => [$name], ['missing hours', 'malformed hours', 'bad window', 'missing parent', 'missing cutoff', 'cutoff mismatch', 'invalid cutoff']);
    }

    #[DataProvider('configuredBoundaryOptions')]
    public function test_invalid_configured_dst_boundary_is_a_configuration_error_for_every_schedule_variant(array $options): void
    {
        config()->set('workdays.definitions.profiles.warehouse.weekends', []);
        foreach ($options as $key => $value) {
            config()->set('workdays.definitions.profiles.warehouse.'.$key, $value);
        }
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Sunday' => [['02:30', '04:00']]]);
        $this->expectException(WorkdayConfigurationException::class);

        $this->reverse('2026-03-08T04:00:00-04:00', $this->workPlan(60, timezone: 'America/New_York'), 1);
    }

    public static function configuredBoundaryOptions(): array
    {
        return [
            'plain' => [[]],
            'false permission' => [['allow_overnight_working_hours' => false]],
            'true permission' => [['allow_overnight_working_hours' => true]],
            'empty overlays' => [['special_working_hours' => [], 'seasonal_working_hours' => []]],
        ];
    }

    public function test_unavailable_database_source_is_not_treated_as_reverse_infeasibility(): void
    {
        config()->set('workdays.driver', 'database');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('definitions migration');

        $this->reverse('2026-11-02T16:00:00Z', $this->workPlan(120), 1);
    }

    public function test_config_holiday_changes_latest_receipt_without_database_access_during_calculation(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-02' => 'Configured closure']);
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $plan = $this->workPlan(120);

        $result = $this->reverse('2026-11-02T16:00:00Z', $plan, 7);
        $queries = $connection->getQueryLog();

        $this->assertTrue($result->feasible);
        $this->assertSame('2026-10-30T15:00:00.000000+00:00', $this->format($result->latestOrderAt));
        $this->assertSame([], $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        $connection->disableQueryLog();
        $this->assertSolution($result, '2026-10-30T15:00:00.000000+00:00', $plan);
    }

    public function test_config_recurring_holiday_is_used_without_importing_database_definitions(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles.warehouse.holidays.gregorian', ['11-02' => 'Annual Monday closure']);
        $plan = $this->workPlan(120);

        $result = $this->reverse('2026-11-02T16:00:00Z', $plan, 7);

        $this->assertSolution($result, '2026-10-30T15:00:00.000000+00:00', $plan);
    }

    public function test_config_reopening_overrides_its_holiday_and_new_calls_observe_removal(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-11-02' => 'Config closure']);
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days', ['2026-11-02' => 'Configured reopening']);
        $plan = $this->workPlan(120);
        $open = $this->reverse('2026-11-02T16:00:00Z', $plan, 7);
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days', []);

        $closed = $this->reverse('2026-11-02T16:00:00Z', $plan, 7);

        $this->assertSame('2026-11-02T14:00:00.000000+00:00', $this->format($open->latestOrderAt));
        $this->assertSolution($closed, '2026-10-30T15:00:00.000000+00:00', $plan);
    }

    public function test_inherited_config_calendar_and_cutoff_use_child_identity_and_ignore_database_definitions_and_unused_routing(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        $parent = $this->profile(['Monday' => [['22:00', '06:00']]]) + [
            'allow_overnight_working_hours' => true,
            'special_working_hours' => ['2026-11-02' => [['21:00', '03:00']]],
            'seasonal_working_hours' => [['from' => '2026-11-02', 'through' => '2026-11-02', 'weekdays' => ['Monday' => [['20:00', '02:00']]]]],
            'cutoff' => ['timezone' => 'UTC', 'time' => '23:00'],
        ];
        $parent['custom_holidays'] = ['2026-11-03' => 'Receiving closure'];
        config()->set('workdays.definitions.profiles', ['parent' => $parent, 'priority' => ['extends' => 'parent']]);
        config()->set('workdays.definitions.profile_routing', ['invalid' => true]);
        $plan = $this->workPlan(180, 'priority');
        $plan['cutoff_profile'] = 'priority';

        $result = $this->reverse('2026-11-03T02:00:00Z', $plan, 1);

        $this->assertSolution($result, '2026-11-02T22:59:59.999999+00:00', $plan);
        $this->assertSame(['priority'], $result->forwardResult->stages[0]->profiles);
        $this->assertSame('priority', $result->forwardResult->cutoffResult->profile);
        $this->assertSame(1, $result->slackMicroseconds);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_config_reverse_probes_perform_no_database_access(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $result = $this->reverse('2026-11-02T16:00:00Z', $this->workPlan(120), 7);
        $queries = $connection->getQueryLog();
        $rules = array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'workday_holiday_rules')));
        $dates = array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'workday_special_dates')));
        $identities = array_map(static fn (array $query): string => json_encode($query['bindings'], JSON_THROW_ON_ERROR), $dates);

        $this->assertTrue($result->feasible);
        $this->assertCount(0, $rules);
        $this->assertSame([], $queries);
        $this->assertSame(count($identities), count(array_unique($identities)), 'Reverse probes reconstructed date-level providers.');
        $this->assertLessThanOrEqual(42, count($queries));
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
    }

    public function test_result_is_immutable_utc_serializable_and_detached_from_mutable_target(): void
    {
        $target = new DateTime('2026-11-02T19:30:12.123456+03:30');
        $before = $target->format('Y-m-d\TH:i:s.uP');
        $plan = $this->workPlan(120);
        $result = $this->reverse($target, $plan, 1);

        $this->assertSame($before, $target->format('Y-m-d\TH:i:s.uP'));
        $this->assertSolution($result, '2026-11-02T14:00:12.123456+00:00', $plan);
        $serialized = $result->toArray();
        $this->assertSame([
            'timezone', 'target_completion_at', 'search_start_at', 'feasible', 'latest_order_at',
            'planning_start_at', 'first_stage_starts_at', 'estimated_completion_at', 'slack_microseconds', 'forward_result',
        ], array_keys($serialized));
        $this->assertSame($result->forwardResult->toArray(), $serialized['forward_result']);
        $this->assertSame($serialized, json_decode(json_encode($serialized, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        foreach ([$result->targetCompletionAt, $result->searchStartAt, $result->latestOrderAt, $result->planningStartAt, $result->firstStageStartsAt, $result->estimatedCompletionAt] as $instant) {
            $this->assertInstanceOf(CarbonImmutable::class, $instant);
            $this->assertSame(0, $instant->getOffset());
        }
        $target->modify('+10 days')->setTimezone(new DateTimeZone('Pacific/Apia'));
        $this->assertSame($serialized, $result->toArray());
        $this->expectException(Error::class);

        $result->feasible = false;
    }

    public function test_naive_target_uses_plan_timezone_and_equivalent_offsets_are_identical(): void
    {
        $plan = $this->workPlan(120, timezone: 'Asia/Tehran');

        $naive = $this->reverse('2026-11-02 16:00:12.123456', $plan, 1);
        $absolute = $this->reverse('2026-11-02T12:30:12.123456Z', $plan, 1);

        $this->assertSame($naive->toArray(), $absolute->toArray());
        $this->assertSame('Asia/Tehran', $naive->timezone);
        $this->assertSame('2026-11-02T10:30:12.123456+00:00', $this->format($naive->latestOrderAt));
        $this->assertSame('2026-10-31T20:30:00.000000+00:00', $this->format($naive->searchStartAt));
    }

    public function test_manager_and_facade_are_deterministic_without_mutating_plan_or_configuration(): void
    {
        $plan = $this->workPlan(120);
        $beforePlan = $plan;
        $beforeConfig = config('workdays');

        $manager = app(WorkdayManager::class)->reversePlanDelivery('2026-11-02T16:00:00Z', $plan, ['lookback_days' => 1]);
        $facade = $this->reverse('2026-11-02T16:00:00Z', $plan, 1);

        $this->assertSame($manager->toArray(), $facade->toArray());
        $this->assertSame($beforePlan, $plan);
        $this->assertSame($beforeConfig, config('workdays'));
    }

    public function test_verified_latest_order_can_be_captured_by_the_unchanged_forward_promise_api(): void
    {
        $plan = $this->workPlan(120);
        $result = $this->reverse('2026-11-02T16:00:00Z', $plan, 1);

        $snapshot = Workday::captureDeliveryPromise($result->latestOrderAt, $plan, ['horizon_days' => 1]);

        $this->assertSame($result->forwardResult->toArray(), $snapshot->originalResult->toArray());
        $this->assertFalse(Workday::compareDeliveryPromise($snapshot)->estimateChanged);
        $this->assertArrayNotHasKey('latest_order_at', $snapshot->toArray());
    }

    public function test_all_thirty_two_existing_stages_are_accepted_and_keep_the_verified_timeline(): void
    {
        $plan = $this->dayPlan(0);
        $plan['stages'] = array_map(static fn (int $id): array => ['id' => 'transit'.$id, 'type' => 'business_days', 'profile' => 'carrier', 'days' => 0], range(1, 32));

        $result = $this->reverse('2026-11-02T16:00:00.123456Z', $plan, 1);

        $this->assertSolution($result, '2026-11-02T16:00:00.123456+00:00', $plan);
        $this->assertCount(32, $result->forwardResult->stages);
    }

    public function test_pre_epoch_microseconds_are_preserved_in_latest_and_target_instants(): void
    {
        $plan = $this->workPlan(120);

        $result = $this->reverse('1969-12-29T16:00:00.123456Z', $plan, 1);

        $this->assertSolution($result, '1969-12-29T14:00:00.123456+00:00', $plan);
        $this->assertSame('1969-12-29T16:00:00.123456+00:00', $this->format($result->targetCompletionAt));
        $this->assertSame(0, $result->slackMicroseconds);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_spring_daytime_schedule_no_longer_fabricates_a_spill_into_the_next_civil_date(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.weekends', []);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Sunday' => [['23:00', '23:59']], 'Monday' => [['00:00', '02:00']]]);
        $plan = $this->workPlan(1, timezone: 'America/New_York');

        $result = $this->reverse('2026-03-09T01:00:00-04:00', $plan, 2);

        $this->assertSolution($result, '2026-03-09T04:59:00.000000+00:00', $plan);
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    public function test_numeric_string_profile_name_zero_is_preserved_and_accepted(): void
    {
        config()->set('workdays.definitions.profiles', ['0' => $this->profile($this->hours([['09:00', '17:00']]))]);
        $plan = $this->workPlan(120, '0');

        $result = $this->reverse('2026-11-02T16:00:00Z', $plan, 1);

        $this->assertSolution($result, '2026-11-02T14:00:00.000000+00:00', $plan);
        $this->assertSame(['0'], $result->forwardResult->stages[0]->profiles);
    }

    public function test_unavailable_cutoff_profile_with_a_closing_bracket_in_its_name_is_ordinary_infeasibility(): void
    {
        $profile = $this->profile($this->hours([['09:00', '17:00']]));
        $profile['cutoff'] = ['timezone' => 'UTC', 'time' => '14:00', 'weekdays' => array_fill_keys(range(1, 7), null)];
        config()->set('workdays.definitions.profiles', ['a]b' => $profile]);
        $plan = $this->workPlan(0, 'a]b');
        $plan['cutoff_profile'] = 'a]b';

        $result = $this->reverse('2026-11-02T16:00:00Z', $plan, 1);

        $this->assertFalse($result->feasible);
        $this->assertNull($result->latestOrderAt);
        $this->assertNull($result->forwardResult);
    }

    public function test_external_references_in_handoff_plan_do_not_mutate_the_returned_forward_timeline(): void
    {
        $warehouse = 'warehouse';
        $carrier = 'carrier';
        $plan = $this->handoffPlan();
        $plan['stages'][0]['profiles'] = [&$warehouse, &$carrier];
        $result = $this->reverse('2026-11-02T16:00:00Z', $plan, 1);
        $this->assertSolution($result, '2026-11-02T16:00:00.000000+00:00', $plan);
        $before = $result->toArray();

        $warehouse = 'changed_warehouse';
        $carrier = 'changed_carrier';

        $this->assertSame(['changed_warehouse', 'changed_carrier'], $plan['stages'][0]['profiles']);
        $this->assertSame(['warehouse', 'carrier'], $result->forwardResult->stages[0]->profiles);
        $this->assertSame($before, $result->toArray());
    }

    public function test_direct_reverse_result_construction_normalizes_all_public_timestamp_fields_to_utc(): void
    {
        $forward = Workday::planDelivery('2026-11-02T14:00:00.123456Z', $this->workPlan(120));
        $target = CarbonImmutable::parse('2026-11-02T19:30:00.123456+03:30');
        $start = CarbonImmutable::parse('2026-11-02T17:30:00.123456+03:30');
        $search = CarbonImmutable::parse('2026-11-01T03:30:00.123456+03:30');

        $result = new ReverseDeliveryPlanResult('UTC', $target, $search, true, $start, $start, $start, $target, 0, $forward);

        foreach (['targetCompletionAt', 'searchStartAt', 'latestOrderAt', 'planningStartAt', 'firstStageStartsAt', 'estimatedCompletionAt'] as $field) {
            $this->assertSame('UTC', $result->{$field}->timezoneName);
            $this->assertSame(0, $result->{$field}->getOffset());
        }
        $this->assertSame('2026-11-02T16:00:00.123456+00:00', $result->toArray()['target_completion_at']);
        $this->assertSame('2026-11-02T14:00:00.123456+00:00', $result->toArray()['latest_order_at']);
        $this->assertSame('2026-11-01T00:00:00.123456+00:00', $result->toArray()['search_start_at']);
    }

    public function test_unknown_holiday_provider_failure_propagates_instead_of_becoming_infeasibility(): void
    {
        $provider = $this->createStub(HolidayProviderInterface::class);
        $provider->method('recurringHolidays')->willThrowException(new RuntimeException('Calendar provider backend unavailable.'));
        $configuration = config('workdays.definitions.profiles.warehouse');
        $resolveConfig = static fn (string $profile): array => $configuration;
        $resolveCalculator = static fn (string $profile, int $limit): BusinessDayCalculator => new BusinessDayCalculator($profile, $configuration, maxScanDays: $limit, holidayProvider: $provider, includeStartDate: false, jalaliCalendar: new JalaliCalendarAdapter, hijriCalendar: new HijriCalendarAdapter('umm_al_qura', 0));
        $reverse = new ReverseDeliveryPlanner(new DeliveryPlanner($resolveCalculator, $resolveConfig, 3660, static fn (string $name): bool => $name === 'warehouse'), $resolveCalculator, $resolveConfig, 3660);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Calendar provider backend unavailable.');

        $reverse->plan('2026-11-02T16:00:00Z', $this->workPlan(120), ['lookback_days' => 1]);
    }

    #[DataProvider('boundedSingleOpeningCalendars')]
    public function test_failed_later_receipts_do_not_scan_thousands_of_dates_beyond_the_completion_target(bool $enhanced, string $latest): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.max_scan_days', 3660);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', []);
        if ($enhanced) {
            config()->set('workdays.definitions.profiles.warehouse.special_working_hours', ['2026-11-02' => [['09:00', '17:00']]]);
        } else {
            config()->set('workdays.definitions.profiles.warehouse.weekends', range(1, 7));
            config()->set('workdays.definitions.profiles.warehouse.extra_working_day_hours', [['09:00', '17:00']]);
            config()->set('workdays.definitions.profiles.warehouse.extra_working_days', ['2026-11-02' => 'Only available day']);
        }
        $connection = $this->app['db']->connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $plan = $this->workPlan(120);

        $configuration = config('workdays.definitions.profiles.warehouse');
        $provider = new class(new LogicalHolidayProvider(static fn (string $name): array => $configuration)) implements HolidayProviderInterface
        {
            public array $dates = [];

            public function __construct(private HolidayProviderInterface $inner) {}

            public function recurringHolidays(string $profile, string $calendar): array
            {
                return $this->inner->recurringHolidays($profile, $calendar);
            }

            public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday
            {
                $this->dates[] = $date->toDateString();

                return $this->inner->customHoliday($profile, $date);
            }

            public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday
            {
                $this->dates[] = $date->toDateString();

                return $this->inner->extraWorkingDay($profile, $date);
            }

            public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
            {
                return $this->customHoliday($profile, $date) !== null;
            }

            public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
            {
                return $this->extraWorkingDay($profile, $date) !== null;
            }
        };
        $calculators = [];
        $resolve = static function (string $profile, int $limit) use ($configuration, $provider, &$calculators): BusinessDayCalculator {
            return $calculators[$profile][$limit] ??= new BusinessDayCalculator($profile, $configuration, false, $limit,
                new JalaliCalendarAdapter, new HijriCalendarAdapter('umm_al_qura', 0), $provider);
        };
        $resolveConfiguration = static fn (string $name): array => $configuration;
        $planner = new ReverseDeliveryPlanner(new DeliveryPlanner($resolve, $resolveConfiguration, 3660, static fn (string $name): bool => $name === 'warehouse'), $resolve, $resolveConfiguration, 3660);

        $result = $planner->plan('2026-11-03T16:00:00Z', $plan, ['lookback_days' => 2]);
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertSame([], $queries);
        $dates = array_values(array_unique($provider->dates));
        $this->assertNotEmpty($dates);
        $this->assertLessThan(15, count($dates));
        foreach ($dates as $date) {
            $this->assertLessThanOrEqual('2026-11-03', $date, 'A reverse probe exhausted availability after its target.');
        }
        $this->assertSolution($result, $latest, $plan);
        $this->assertSame(3660, config('workdays.max_scan_days'));
    }

    public static function boundedSingleOpeningCalendars(): array
    {
        return [
            'enhanced special-only opening' => [true, '2026-11-02T15:00:00.000000+00:00'],
            'weekly-empty extra-day fallback' => [false, '2026-11-02T15:00:00.000000+00:00'],
        ];
    }

    public function test_pre_epoch_lunch_latest_receipt_has_exact_microsecond_capacity_bounds(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00'], ['13:00', '17:00']]));
        $plan = $this->workPlan(120);

        $result = $this->reverse('1969-12-29T14:00:00Z', $plan, 1);

        $this->assertSolution($result, '1969-12-29T11:00:00.000000+00:00', $plan);
        $this->assertSame('1969-12-29T14:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
        $this->assertNextMicrosecondMisses($result, $plan);
    }

    private function profile(array $hours): array
    {
        return ['weekends' => ['Saturday', 'Sunday'], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [], 'working_hours' => $hours];
    }

    private function hours(array $windows): array
    {
        return array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $windows);
    }

    private function workPlan(int $minutes, string $profile = 'warehouse', string $timezone = 'UTC'): array
    {
        return ['timezone' => $timezone, 'profile_timezones' => [$profile => $timezone],
            'stages' => [['id' => 'prepare', 'type' => 'work', 'profile' => $profile, 'minutes' => $minutes]]];
    }

    private function dayPlan(int $days, string $timezone = 'UTC'): array
    {
        return ['timezone' => $timezone, 'profile_timezones' => ['carrier' => $timezone],
            'stages' => [['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => $days]]];
    }

    private function handoffPlan(): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'],
            'stages' => [['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']]]];
    }

    private function referencePlan(): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC', 'last_mile' => 'UTC'], 'cutoff_profile' => 'warehouse', 'stages' => [
            ['id' => 'preparation', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120],
            ['id' => 'handoff', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']],
            ['id' => 'transport', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 2],
            ['id' => 'final_processing', 'type' => 'work', 'profile' => 'last_mile', 'minutes' => 60],
        ]];
    }

    private function reverse(string|\DateTimeInterface $target, array $plan, int $lookback = 14): ReverseDeliveryPlanResult
    {
        return Workday::reversePlanDelivery($target, $plan, ['lookback_days' => $lookback]);
    }

    private function assertSolution(ReverseDeliveryPlanResult $result, string $latest, array $plan): void
    {
        $this->assertInstanceOf(ReverseDeliveryPlanResult::class, $result);
        $this->assertTrue($result->feasible);
        $this->assertSame($latest, $this->format($result->latestOrderAt));
        $this->assertInstanceOf(DeliveryPlanResult::class, $result->forwardResult);
        $this->assertSame(Workday::planDelivery($result->latestOrderAt, $plan)->toArray(), $result->forwardResult->toArray());
        $this->assertSame($this->format($result->forwardResult->planningStartAt), $this->format($result->planningStartAt));
        $this->assertSame($this->format($result->forwardResult->stages[0]->startedAt), $this->format($result->firstStageStartsAt));
        $this->assertSame($this->format($result->forwardResult->estimatedCompletionAt), $this->format($result->estimatedCompletionAt));
        $this->assertTrue($result->estimatedCompletionAt->lessThanOrEqualTo($result->targetCompletionAt));
        $this->assertTrue($result->latestOrderAt->greaterThanOrEqualTo($result->searchStartAt));
        $this->assertTrue($result->latestOrderAt->lessThanOrEqualTo($result->targetCompletionAt));
    }

    private function assertNextMicrosecondMisses(ReverseDeliveryPlanResult $result, array $plan): void
    {
        try {
            $next = Workday::planDelivery($result->latestOrderAt->addMicrosecond(), $plan);
        } catch (RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());

            return;
        }

        $this->assertTrue($next->estimatedCompletionAt->greaterThan($result->targetCompletionAt), 'A later receipt still meets the target.');
    }

    private function format(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.uP');
    }
}
