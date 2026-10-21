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
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Data\CutoffResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Data\DeliveryStageResult;
use Zarbinco\LaravelWorkdays\Delivery\DeliveryPlanner;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class DeliveryPlanningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.definitions.default_profile', 'warehouse');
        config()->set('workdays.driver', 'config');
        config()->set('workdays.definitions.include_start_date', false);
        config()->set('workdays.max_scan_days', 14);
        config()->set('workdays.definitions.profiles', [
            'warehouse' => $this->profile($this->hours([['09:00', '12:00'], ['13:00', '17:00']])) + [
                'cutoff' => ['timezone' => 'UTC', 'time' => '14:00'],
            ],
            'carrier' => $this->profile($this->hours([['12:00', '18:00']])),
            'last_mile' => $this->profile($this->hours([['09:00', '17:00']])),
        ]);
    }

    #[DataProvider('acceptanceScenarios')]
    public function test_reference_scenarios_have_exact_stage_timestamps(string $receivedAt, bool $holiday, string $planningStart, array $expectedStages): void
    {
        if ($holiday) {
            foreach (['warehouse', 'carrier', 'last_mile'] as $profile) {
                config()->set('workdays.definitions.profiles.'.$profile.'.custom_holidays', ['2026-07-03' => 'Fixture closure']);
            }
        }

        $result = Workday::planDelivery($receivedAt, self::referencePlan());

        $this->assertInstanceOf(DeliveryPlanResult::class, $result);
        $this->assertInstanceOf(CutoffResult::class, $result->cutoffResult);
        $this->assertSame($this->utc($receivedAt), $this->format($result->receivedAt));
        $this->assertSame($this->utc($planningStart), $this->format($result->planningStartAt));
        $this->assertCount(4, $result->stages);

        foreach ($expectedStages as $index => [$ready, $start, $end]) {
            $definition = self::referencePlan()['stages'][$index];
            $this->assertStage($result->stages[$index], $definition['id'], $definition['type'], $definition['profiles'] ?? [$definition['profile']], $ready, $start, $end);
        }

        $this->assertSame($this->format($result->stages[3]->completedAt), $this->format($result->estimatedCompletionAt));
        $this->assertSame(! str_contains($receivedAt, '14:00'), $result->cutoffResult->sameDayEligible);
    }

    public static function acceptanceScenarios(): array
    {
        return [
            'A before exclusive cutoff' => ['2026-06-29 13:30', false, '2026-06-29 13:30', [
                ['2026-06-29 13:30', '2026-06-29 13:30', '2026-06-29 15:30'],
                ['2026-06-29 15:30', '2026-06-29 15:30', '2026-06-29 15:30'],
                ['2026-06-29 15:30', '2026-06-29 15:30', '2026-07-01 15:30'],
                ['2026-07-01 15:30', '2026-07-01 15:30', '2026-07-01 16:30'],
            ]],
            'B exact exclusive cutoff' => ['2026-06-29 14:00', false, '2026-06-30 00:00', [
                ['2026-06-30 00:00', '2026-06-30 09:00', '2026-06-30 11:00'],
                // The user retained half-open hours: the brief's noon pickup becomes 13:00.
                ['2026-06-30 11:00', '2026-06-30 13:00', '2026-06-30 13:00'],
                ['2026-06-30 13:00', '2026-06-30 13:00', '2026-07-02 13:00'],
                ['2026-07-02 13:00', '2026-07-02 13:00', '2026-07-02 14:00'],
            ]],
            'C Friday holiday and weekend' => ['2026-07-02 14:00', true, '2026-07-06 00:00', [
                ['2026-07-06 00:00', '2026-07-06 09:00', '2026-07-06 11:00'],
                ['2026-07-06 11:00', '2026-07-06 13:00', '2026-07-06 13:00'],
                ['2026-07-06 13:00', '2026-07-06 13:00', '2026-07-08 13:00'],
                ['2026-07-08 13:00', '2026-07-08 13:00', '2026-07-08 14:00'],
            ]],
        ];
    }

    public function test_d_handoff_compares_independent_profile_timezones_as_instants(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '17:00']]));
        config()->set('workdays.definitions.profiles.carrier.working_hours', $this->hours([['10:00', '18:00']]));
        $plan = $this->singleStage(['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']]);
        $plan['profile_timezones'] = ['warehouse' => 'Asia/Tehran', 'carrier' => 'Asia/Dubai'];

        $result = Workday::planDelivery('2026-06-29T05:45:00Z', $plan);

        $this->assertStage($result->stages[0], 'pickup', 'handoff', ['warehouse', 'carrier'], '2026-06-29 05:45', '2026-06-29 06:00', '2026-06-29 06:00');
        $this->assertNull($result->cutoffResult);
    }

    public function test_e_disjoint_working_hours_fail_with_stage_profiles_and_global_limit(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', $this->hours([['09:00', '12:00']]));
        config()->set('workdays.definitions.profiles.carrier.working_hours', $this->hours([['13:00', '17:00']]));
        config()->set('workdays.max_scan_days', 3);

        $this->assertPlanningFailure('2026-06-29 09:00', $this->handoffPlan(), 'pickup', ['warehouse', 'carrier'], 3);
    }

    #[DataProvider('handoffBoundaries')]
    public function test_f_handoff_respects_common_openings_closings_and_split_shifts(string $receivedAt, string $expected): void
    {
        $result = Workday::planDelivery($receivedAt, $this->handoffPlan());

        $this->assertStage($result->stages[0], 'pickup', 'handoff', ['warehouse', 'carrier'], $receivedAt, $expected, $expected);
    }

    public static function handoffBoundaries(): array
    {
        return [
            'before second opening and during warehouse break' => ['2026-06-29 11:30', '2026-06-29 13:00'],
            'touching windows are not overlap' => ['2026-06-29 12:00', '2026-06-29 13:00'],
            'inside lunch break' => ['2026-06-29 12:30', '2026-06-29 13:00'],
            'exact common opening' => ['2026-06-29 13:00', '2026-06-29 13:00'],
            'already both open' => ['2026-06-29 15:30:12.123456', '2026-06-29 15:30:12.123456'],
            'last microsecond before close' => ['2026-06-29 16:59:59.999999', '2026-06-29 16:59:59.999999'],
            'exact warehouse closing' => ['2026-06-29 17:00', '2026-06-30 13:00'],
            'missed Friday closing crosses weekend' => ['2026-07-03 17:00', '2026-07-06 13:00'],
        ];
    }

    public function test_f_preparation_at_closing_is_valid_but_next_handoff_waits(): void
    {
        $plan = $this->handoffPlan();
        array_unshift($plan['stages'], ['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 60]);

        $result = Workday::planDelivery('2026-06-29 16:00', $plan);

        $this->assertStage($result->stages[0], 'prepare', 'work', ['warehouse'], '2026-06-29 16:00', '2026-06-29 16:00', '2026-06-29 17:00');
        $this->assertStage($result->stages[1], 'pickup', 'handoff', ['warehouse', 'carrier'], '2026-06-29 17:00', '2026-06-30 13:00', '2026-06-30 13:00');
    }

    public function test_g_extra_working_day_fallback_hours_reopen_holiday_for_work_and_handoff(): void
    {
        foreach (['warehouse', 'carrier'] as $profile) {
            config()->set('workdays.definitions.profiles.'.$profile.'.custom_holidays', ['2026-06-27' => 'Normally closed']);
            config()->set('workdays.definitions.profiles.'.$profile.'.extra_working_days', ['2026-06-27' => 'Extra Saturday']);
        }
        config()->set('workdays.definitions.profiles.warehouse.extra_working_day_hours', [['10:00', '14:00']]);
        config()->set('workdays.definitions.profiles.carrier.extra_working_day_hours', [['11:00', '15:00']]);
        $plan = $this->handoffPlan();
        array_unshift($plan['stages'], ['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 60]);

        $result = Workday::planDelivery('2026-06-27 09:00', $plan);

        $this->assertStage($result->stages[0], 'prepare', 'work', ['warehouse'], '2026-06-27 09:00', '2026-06-27 10:00', '2026-06-27 11:00');
        $this->assertStage($result->stages[1], 'pickup', 'handoff', ['warehouse', 'carrier'], '2026-06-27 11:00', '2026-06-27 11:00', '2026-06-27 11:00');
    }

    public function test_manager_and_facade_route_the_identical_plan(): void
    {
        $plan = self::referencePlan();

        $this->assertSame(
            Workday::planDelivery('2026-06-29 13:30', $plan)->toArray(),
            $this->app->make(WorkdayManager::class)->planDelivery('2026-06-29 13:30', $plan)->toArray(),
        );
    }

    public function test_calculators_are_resolved_once_per_profile_and_reused_across_stages(): void
    {
        $resolved = [];
        $context = $this->app->make(CalculationContextFactory::class)->open();
        $planner = new DeliveryPlanner(function (string $profile) use (&$resolved, $context) {
            $resolved[$profile] = ($resolved[$profile] ?? 0) + 1;

            return $context->calculator($profile);
        }, $context->configuration(...), $context->maxScanDays, $context->hasProfile(...));
        $plan = self::referencePlan();
        $plan['stages'][] = ['id' => 'warehouse_again', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 0];

        $planner->plan('2026-06-29 13:30', $plan);

        $this->assertSame(['warehouse' => 1, 'carrier' => 1, 'last_mile' => 1], $resolved);
    }

    public function test_results_are_immutable_utc_values_with_stable_serialization(): void
    {
        $plan = $this->singleStage(['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 1]);
        $plan['timezone'] = 'Asia/Tehran';

        $result = Workday::planDelivery('2026-06-29T12:30:12.123456+03:30', $plan);

        $expectedStage = [
            'id' => 'prepare', 'type' => 'work', 'profiles' => ['warehouse'],
            'ready_at' => '2026-06-29T09:00:12.123456+00:00',
            'started_at' => '2026-06-29T09:00:12.123456+00:00',
            'completed_at' => '2026-06-29T09:01:12.123456+00:00',
        ];
        $this->assertSame([
            'timezone' => 'Asia/Tehran',
            'received_at' => '2026-06-29T09:00:12.123456+00:00',
            'planning_start_at' => '2026-06-29T09:00:12.123456+00:00',
            'cutoff_result' => null,
            'stages' => [$expectedStage],
            'estimated_completion_at' => '2026-06-29T09:01:12.123456+00:00',
        ], $result->toArray());
        $this->assertSame($expectedStage, $result->stages[0]->toArray());
        $result->receivedAt->addDay();
        $this->assertSame('2026-06-29T09:00:12.123456+00:00', $this->format($result->receivedAt));
        $this->assertInstanceOf(CarbonImmutable::class, $result->estimatedCompletionAt);
        $this->expectException(Error::class);

        $result->timezone = 'Europe/London';
    }

    public function test_stage_properties_are_readonly(): void
    {
        $result = Workday::planDelivery('2026-06-29 09:00', $this->workPlan(0));
        $this->expectException(Error::class);

        $result->stages[0]->id = 'changed';
    }

    public function test_mutable_input_keeps_its_original_fields_and_timezone(): void
    {
        $input = new DateTime('2026-06-29 12:30:12.123456', new DateTimeZone('Asia/Tehran'));
        $before = $input->format('Y-m-d H:i:s.u e');

        $result = Workday::planDelivery($input, $this->workPlan(1));

        $this->assertSame($before, $input->format('Y-m-d H:i:s.u e'));
        $this->assertSame('2026-06-29T09:00:12.123456+00:00', $this->format($result->receivedAt));
        $this->assertSame('2026-06-29T09:01:12.123456+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_naive_input_uses_plan_timezone_and_work_uses_profile_timezone(): void
    {
        $plan = $this->workPlan(30);
        $plan['timezone'] = 'Asia/Dubai';
        $plan['profile_timezones']['warehouse'] = 'Asia/Tehran';

        $result = Workday::planDelivery('2026-06-29 10:00:12.123456', $plan);

        $this->assertSame('2026-06-29T06:00:12.123456+00:00', $this->format($result->receivedAt));
        $this->assertStage($result->stages[0], 'prepare', 'work', ['warehouse'], '2026-06-29 06:00:12.123456', '2026-06-29 06:00:12.123456', '2026-06-29 06:30:12.123456');
    }

    #[DataProvider('invalidTimestamps')]
    public function test_invalid_or_ambiguous_timestamp_inputs_are_rejected(string $receivedAt): void
    {
        $this->expectException(InvalidArgumentException::class);

        Workday::planDelivery($receivedAt, $this->workPlan(0));
    }

    public static function invalidTimestamps(): array
    {
        return array_map(static fn (string $value): array => [$value], [
            '', 'now', 'tomorrow', 'next Monday', '2026-06-29', '2026/06/29 09:00',
            '2026-06-29 9:00', '2026-06-29T09:00', '2026-06-29 09:00Z', '2026-06-29 09:00 UTC',
            '2026-02-29 09:00', '2026-06-29 24:00', '2026-06-29 09:60', '2026-06-29 09:00:60',
            '2026-06-29 09:00:00.1234567', '2026-06-29T09:00:00+24:00', '2026-06-29T09:00:00+03:60',
            ' 2026-06-29 09:00', "2026-06-29 09:00\n",
        ]);
    }

    #[DataProvider('transitionInputs')]
    public function test_nonexistent_and_ambiguous_naive_wall_clock_inputs_are_rejected(string $receivedAt): void
    {
        $plan = $this->workPlan(0);
        $plan['timezone'] = 'America/New_York';
        $this->expectException(InvalidArgumentException::class);

        Workday::planDelivery($receivedAt, $plan);
    }

    public static function transitionInputs(): array
    {
        return [['2026-03-08 02:30'], ['2026-11-01 01:30']];
    }

    #[DataProvider('explicitFoldOffsets')]
    public function test_explicit_offsets_disambiguate_repeated_wall_clock_times(string $receivedAt, string $expectedUtc): void
    {
        $plan = $this->daysPlan(0);
        $plan['timezone'] = 'America/New_York';

        $result = Workday::planDelivery($receivedAt, $plan);

        $this->assertSame($expectedUtc, $this->format($result->estimatedCompletionAt));
    }

    public static function explicitFoldOffsets(): array
    {
        return [
            ['2026-11-01T01:30:00-04:00', '2026-11-01T05:30:00.000000+00:00'],
            ['2026-11-01T01:30:00-05:00', '2026-11-01T06:30:00.000000+00:00'],
        ];
    }

    public function test_fixed_offset_tzdb_identifier_normalizes_without_transition_metadata(): void
    {
        $plan = $this->daysPlan(0);
        $plan['timezone'] = 'EST';

        $result = Workday::planDelivery('2026-06-29 10:00', $plan);

        $this->assertSame('2026-06-29T15:00:00.000000+00:00', $this->format($result->receivedAt));
    }

    #[DataProvider('invalidPlans')]
    public function test_invalid_plan_shapes_and_unsupported_settings_are_rejected(array $plan, string $context): void
    {
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage($context);

        Workday::planDelivery('2026-06-29 10:00', $plan);
    }

    public static function invalidPlans(): array
    {
        $base = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'], 'stages' => [
            ['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 1],
        ]];
        $cases = [];
        foreach (['timezone', 'profile_timezones', 'stages'] as $key) {
            $plan = $base;
            unset($plan[$key]);
            $cases['missing '.$key] = [$plan, $key];
        }
        foreach ([null, false, 1, '', '+03:30', 'Mars/Olympus'] as $value) {
            $cases['invalid timezone '.var_export($value, true)] = [array_replace($base, ['timezone' => $value]), 'timezone'];
        }
        foreach ([null, 'UTC', []] as $value) {
            $cases['invalid profile timezone map '.var_export($value, true)] = [array_replace($base, ['profile_timezones' => $value]), 'profile_timezones'];
        }
        foreach ([null, '', 3, '+03:30', 'Mars/Olympus'] as $value) {
            $plan = $base;
            $plan['profile_timezones']['warehouse'] = $value;
            $cases['invalid profile timezone '.var_export($value, true)] = [$plan, 'warehouse'];
        }
        foreach ([null, 'work', [], ['first' => $base['stages'][0]], [1 => $base['stages'][0]]] as $value) {
            $cases['invalid stages '.var_export($value, true)] = [array_replace($base, ['stages' => $value]), 'stages'];
        }
        $many = [];
        for ($index = 0; $index < 33; $index++) {
            $many[] = array_replace($base['stages'][0], ['id' => 'stage_'.$index]);
        }
        $cases['too many stages'] = [array_replace($base, ['stages' => $many]), '32'];
        $cases['unsupported plan key'] = [$base + ['capacity' => 1], 'capacity'];
        foreach ([null, false, 3, ''] as $value) {
            $cases['invalid cutoff profile '.var_export($value, true)] = [$base + ['cutoff_profile' => $value], 'cutoff_profile'];
        }
        $cases['cutoff unknown profile'] = [$base + ['cutoff_profile' => 'ghost'], 'ghost'];
        $cases['cutoff wrong first profile'] = [$base + ['cutoff_profile' => 'carrier'], 'prepare'];
        $handoff = ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']];
        $cases['cutoff handoff first'] = [array_replace($base, ['cutoff_profile' => 'warehouse', 'stages' => [$handoff]]), 'pickup'];
        foreach ([null, false, 'work'] as $value) {
            $cases['non-array stage '.var_export($value, true)] = [array_replace($base, ['stages' => [$value]]), '0'];
        }
        foreach (['id', 'type', 'profile', 'minutes'] as $key) {
            $stage = $base['stages'][0];
            unset($stage[$key]);
            $cases['missing stage '.$key] = [array_replace($base, ['stages' => [$stage]]), $key === 'id' ? '0' : 'prepare'];
        }
        foreach ([null, 1, '', '   '] as $value) {
            $cases['invalid stage id '.var_export($value, true)] = [array_replace($base, ['stages' => [array_replace($base['stages'][0], ['id' => $value])]]), '0'];
        }
        $cases['duplicate ids'] = [array_replace($base, ['stages' => [$base['stages'][0], $base['stages'][0]]]), 'prepare'];
        foreach ([null, 1, '', 'shipping'] as $value) {
            $cases['invalid stage type '.var_export($value, true)] = [array_replace($base, ['stages' => [array_replace($base['stages'][0], ['type' => $value])]]), 'prepare'];
        }
        foreach ([null, 1, '', 'ghost'] as $value) {
            $cases['invalid stage profile '.var_export($value, true)] = [array_replace($base, ['stages' => [array_replace($base['stages'][0], ['profile' => $value])]]), 'prepare'];
        }
        foreach (['work' => 'minutes', 'business_days' => 'days'] as $type => $durationKey) {
            $stage = ['id' => 'duration', 'type' => $type, 'profile' => 'warehouse', $durationKey => 1];
            foreach ([null, -1, 1.0, 0.5, '1', '0', false, true, []] as $value) {
                $cases['invalid '.$type.' duration '.var_export($value, true)] = [array_replace($base, ['stages' => [array_replace($stage, [$durationKey => $value])]]), 'duration'];
            }
            unset($stage[$durationKey]);
            $cases['missing '.$durationKey] = [array_replace($base, ['stages' => [$stage]]), 'duration'];
        }
        foreach ([null, 'warehouse', [], ['warehouse'], ['warehouse', 'warehouse'], ['warehouse', 'carrier', 'last_mile'], ['a' => 'warehouse', 'b' => 'carrier'], ['warehouse', 1], ['warehouse', 'ghost']] as $value) {
            $cases['invalid handoff profiles '.var_export($value, true)] = [array_replace($base, ['stages' => [array_replace($handoff, ['profiles' => $value])]]), 'pickup'];
        }
        $cases['missing handoff profiles'] = [array_replace($base, ['stages' => [['id' => 'pickup', 'type' => 'handoff']]]), 'pickup'];
        foreach ([
            $base['stages'][0] + ['days' => 1],
            $handoff + ['minutes' => 1],
            ['id' => 'transit', 'type' => 'business_days', 'profile' => 'warehouse', 'days' => 1, 'include_start_date' => true],
        ] as $stage) {
            $cases['unsupported '.$stage['type'].' key'] = [array_replace($base, ['stages' => [$stage]]), $stage['id']];
        }

        return $cases;
    }

    public function test_exactly_thirty_two_stages_are_allowed_and_remain_ordered(): void
    {
        $plan = $this->daysPlan(0);
        $plan['stages'] = [];
        for ($index = 0; $index < 32; $index++) {
            $plan['stages'][] = ['id' => 'stage_'.$index, 'type' => 'business_days', 'profile' => 'carrier', 'days' => 0];
        }

        $result = Workday::planDelivery('2026-06-29 10:00', $plan);

        $this->assertSame(array_column($plan['stages'], 'id'), array_map(static fn (DeliveryStageResult $stage): string => $stage->id, $result->stages));
        $this->assertSame('2026-06-29T10:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_invalid_profile_configuration_has_stage_context(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.weekends', ['Funday']);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('prepare');

        Workday::planDelivery('2026-06-29 10:00', $this->workPlan(0));
    }

    public function test_missing_working_hours_has_stage_and_profile_context(): void
    {
        $profile = config('workdays.definitions.profiles.warehouse');
        unset($profile['working_hours']);
        config()->set('workdays.definitions.profiles.warehouse', $profile);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('prepare');

        Workday::planDelivery('2026-06-29 10:00', $this->workPlan(0));
    }

    public function test_all_closed_working_hours_fail_with_stage_and_horizon_context(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Monday' => []]);
        config()->set('workdays.max_scan_days', 2);

        $this->assertPlanningFailure('2026-06-29 10:00', $this->workPlan(0), 'prepare', ['warehouse'], 2);
    }

    public function test_cutoff_omitted_never_validates_even_malformed_cutoff_configuration(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.cutoff', ['invalid' => 'ignored']);

        $result = Workday::planDelivery('2026-06-29 14:00', $this->workPlan(0));

        $this->assertNull($result->cutoffResult);
        $this->assertSame('2026-06-29T14:00:00.000000+00:00', $this->format($result->planningStartAt));
    }

    public function test_requested_missing_cutoff_policy_uses_existing_meaningful_error(): void
    {
        $profile = config('workdays.definitions.profiles.warehouse');
        unset($profile['cutoff']);
        config()->set('workdays.definitions.profiles.warehouse', $profile);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('warehouse');

        Workday::planDelivery('2026-06-29 10:00', self::referencePlan());
    }

    public function test_cutoff_profile_timezone_must_match_the_stage_profile_timezone(): void
    {
        $plan = self::referencePlan();
        $plan['profile_timezones']['warehouse'] = 'Asia/Tehran';
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('warehouse');

        Workday::planDelivery('2026-06-29 10:00', $plan);
    }

    public function test_cutoff_timezone_mismatch_is_rejected_before_unavailable_cutoff_search(): void
    {
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.definitions.profiles.warehouse.cutoff.weekdays', array_fill_keys(range(1, 7), null));
        $plan = self::referencePlan();
        $plan['profile_timezones']['warehouse'] = 'Asia/Tehran';
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('timezone');

        Workday::planDelivery('2026-06-29 10:00', $plan);
    }

    public function test_unknown_profile_in_unused_timezone_mapping_is_rejected(): void
    {
        $plan = $this->workPlan(0);
        $plan['profile_timezones']['ghost'] = 'UTC';
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('ghost');

        Workday::planDelivery('2026-06-29 10:00', $plan);
    }

    public function test_cutoff_result_retains_its_original_timezone_and_serialization(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.cutoff', ['timezone' => 'Asia/Tehran', 'time' => '14:00']);
        $plan = $this->workPlan(0);
        $plan['profile_timezones']['warehouse'] = 'Asia/Tehran';
        $plan['cutoff_profile'] = 'warehouse';
        $input = '2026-06-29T10:30:00.123456Z';
        $existing = Workday::profile('warehouse')->resolveCutoff($input);

        $result = Workday::planDelivery($input, $plan);

        $this->assertSame($existing->toArray(), $result->cutoffResult->toArray());
        $this->assertSame($existing->toArray(), $result->toArray()['cutoff_result']);
        $this->assertSame('Asia/Tehran', $result->cutoffResult->receivedAt->timezoneName);
        $this->assertSame('2026-06-29T20:30:00.000000+00:00', $this->format($result->planningStartAt));
        $this->assertSame('2026-06-30T05:30:00.000000+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_cutoff_weekday_override_is_reused_and_only_routes_the_first_stage(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.cutoff.weekdays', ['Monday' => '11:00', 'Wednesday' => null]);
        $plan = $this->workPlan(0);
        $plan['cutoff_profile'] = 'warehouse';
        $plan['stages'][] = ['id' => 'advance', 'type' => 'business_days', 'profile' => 'warehouse', 'days' => 1];
        $plan['stages'][] = ['id' => 'later_work', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 0];

        $result = Workday::planDelivery('2026-06-29 11:00', $plan);

        $this->assertSame('2026-06-30', $result->cutoffResult->effectiveBusinessDate->toDateString());
        $this->assertSame('2026-07-01T09:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_same_day_cutoff_keeps_original_cursor_before_working_hours_open(): void
    {
        $plan = $this->workPlan(0);
        $plan['cutoff_profile'] = 'warehouse';

        $result = Workday::planDelivery('2026-06-29 07:00', $plan);

        $this->assertTrue($result->cutoffResult->sameDayEligible);
        $this->assertSame('2026-06-29T07:00:00.000000+00:00', $this->format($result->planningStartAt));
        $this->assertStage($result->stages[0], 'prepare', 'work', ['warehouse'], '2026-06-29 07:00', '2026-06-29 09:00', '2026-06-29 09:00');
    }

    public function test_cutoff_microfix_skips_a_future_date_with_no_actual_eligibility_window(): void
    {
        config()->set('workdays.definitions.profiles.warehouse.weekends', []);
        config()->set('workdays.definitions.profiles.warehouse.cutoff', ['timezone' => 'America/Santiago', 'time' => '01:00']);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], [['09:00', '17:00']]));
        config()->set('workdays.max_scan_days', 2);
        $plan = $this->workPlan(0);
        $plan['timezone'] = 'America/Santiago';
        $plan['profile_timezones']['warehouse'] = 'America/Santiago';
        $plan['cutoff_profile'] = 'warehouse';

        $result = Workday::planDelivery('2026-09-05 02:00', $plan);

        $this->assertSame('2026-09-07', $result->cutoffResult->effectiveBusinessDate->toDateString());
        $this->assertSame('2026-09-07T12:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_zero_minute_work_normalizes_and_zero_business_days_preserve_closed_cursor(): void
    {
        $work = Workday::planDelivery('2026-06-28 08:00', $this->workPlan(0));
        $days = Workday::planDelivery('2026-06-28 08:00', $this->daysPlan(0));

        $this->assertStage($work->stages[0], 'prepare', 'work', ['warehouse'], '2026-06-28 08:00', '2026-06-29 09:00', '2026-06-29 09:00');
        $this->assertStage($days->stages[0], 'transit', 'business_days', ['carrier'], '2026-06-28 08:00', '2026-06-28 08:00', '2026-06-28 08:00');
    }

    public function test_work_consumes_business_minutes_across_lunch_and_half_day_windows(): void
    {
        $lunch = Workday::planDelivery('2026-06-29 11:30', $this->workPlan(120));
        config()->set('workdays.definitions.profiles.warehouse.working_hours.Thursday', [['09:00', '13:00']]);
        $halfDay = Workday::planDelivery('2026-07-02 12:00', $this->workPlan(120));

        $this->assertSame('2026-06-29T14:30:00.000000+00:00', $this->format($lunch->estimatedCompletionAt));
        $this->assertSame('2026-07-03T10:00:00.000000+00:00', $this->format($halfDay->estimatedCompletionAt));
    }

    public function test_handoff_respects_the_second_profiles_own_holiday(): void
    {
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-06-29' => 'Carrier closed']);

        $result = Workday::planDelivery('2026-06-29 13:00', $this->handoffPlan());

        $this->assertSame('2026-06-30T13:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_business_days_are_strictly_future_independent_of_include_start_and_working_hours(): void
    {
        $profile = config('workdays.definitions.profiles.carrier');
        unset($profile['working_hours']);
        config()->set('workdays.definitions.profiles.carrier', $profile);
        config()->set('workdays.definitions.profiles.carrier.custom_holidays', ['2026-07-03' => 'Fixture holiday']);
        $plan = $this->daysPlan(2);

        config()->set('workdays.definitions.include_start_date', false);
        $excluding = Workday::planDelivery('2026-07-02 20:12:34.123456', $plan);
        config()->set('workdays.definitions.include_start_date', true);
        $including = Workday::planDelivery('2026-07-02 20:12:34.123456', $plan);

        $this->assertSame($excluding->toArray(), $including->toArray());
        $this->assertStage($excluding->stages[0], 'transit', 'business_days', ['carrier'], '2026-07-02 20:12:34.123456', '2026-07-02 20:12:34.123456', '2026-07-07 20:12:34.123456');
    }

    public function test_business_days_preserve_stage_local_clock_across_dst_offset_change(): void
    {
        $plan = $this->daysPlan(1);
        $plan['profile_timezones']['carrier'] = 'America/New_York';

        $result = Workday::planDelivery('2026-03-06T15:12:34.123456Z', $plan);

        $this->assertSame('2026-03-09T14:12:34.123456+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_work_after_business_date_advancement_normalizes_an_outside_hours_result(): void
    {
        $plan = $this->daysPlan(1);
        $plan['stages'][] = ['id' => 'final_delivery', 'type' => 'work', 'profile' => 'last_mile', 'minutes' => 0];

        $result = Workday::planDelivery('2026-06-29 20:00', $plan);

        $this->assertStage($result->stages[0], 'transit', 'business_days', ['carrier'], '2026-06-29 20:00', '2026-06-29 20:00', '2026-06-30 20:00');
        $this->assertStage($result->stages[1], 'final_delivery', 'work', ['last_mile'], '2026-06-30 20:00', '2026-07-01 09:00', '2026-07-01 09:00');
    }

    public function test_business_days_fail_when_the_target_local_wall_clock_does_not_exist(): void
    {
        config()->set('workdays.definitions.profiles.carrier.weekends', []);
        $plan = $this->daysPlan(1);
        $plan['profile_timezones']['carrier'] = 'America/New_York';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transit');

        Workday::planDelivery('2026-03-07T07:30:00Z', $plan);
    }

    public function test_business_days_need_a_real_wall_clock_only_on_the_final_counted_date(): void
    {
        config()->set('workdays.definitions.profiles.carrier.weekends', []);
        $plan = $this->daysPlan(2);
        $plan['profile_timezones']['carrier'] = 'America/New_York';

        $result = Workday::planDelivery('2026-03-07T07:30:00Z', $plan);

        $this->assertSame('2026-03-09T06:30:00.000000+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_business_days_skip_a_nonexistent_civil_date_without_counting_it(): void
    {
        config()->set('workdays.definitions.profiles.carrier.weekends', []);
        config()->set('workdays.max_scan_days', 2);
        $plan = $this->daysPlan(1);
        $plan['timezone'] = 'Pacific/Apia';
        $plan['profile_timezones']['carrier'] = 'Pacific/Apia';

        $result = Workday::planDelivery('2011-12-29 10:00', $plan);

        $this->assertSame('2011-12-30T20:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
        $this->assertSame('2011-12-31 10:00', $result->estimatedCompletionAt->setTimezone('Pacific/Apia')->format('Y-m-d H:i'));
    }

    public function test_skipped_civil_date_does_not_extend_the_global_date_label_boundary(): void
    {
        config()->set('workdays.definitions.profiles.carrier.weekends', []);
        config()->set('workdays.max_scan_days', 1);
        $plan = $this->daysPlan(1);
        $plan['timezone'] = 'Pacific/Apia';
        $plan['profile_timezones']['carrier'] = 'Pacific/Apia';

        $this->assertPlanningFailure('2011-12-29 10:00', $plan, 'transit', ['carrier'], 1);
    }

    public function test_global_horizon_includes_the_last_date_and_the_input_is_day_zero(): void
    {
        config()->set('workdays.max_scan_days', 1);

        $result = Workday::planDelivery('2026-06-29 17:00', $this->workPlan(0));

        $this->assertSame('2026-06-30T09:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
    }

    public function test_business_day_count_beyond_global_horizon_fails_without_partial_success(): void
    {
        config()->set('workdays.max_scan_days', 1);

        $this->assertPlanningFailure('2026-06-29 10:00', $this->daysPlan(2), 'transit', ['carrier'], 1);
    }

    public function test_work_duration_beyond_global_horizon_fails(): void
    {
        config()->set('workdays.max_scan_days', 1);

        $this->assertPlanningFailure('2026-06-29 16:00', $this->workPlan(481), 'prepare', ['warehouse'], 1);
    }

    public function test_later_stage_cannot_restart_the_global_horizon(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $plan = $this->daysPlan(1);
        $plan['stages'][] = ['id' => 'second_transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 1];

        $this->assertPlanningFailure('2026-06-29 10:00', $plan, 'second_transit', ['carrier'], 1);
    }

    public function test_horizon_is_checked_in_plan_timezone_instead_of_stage_timezone(): void
    {
        config()->set('workdays.max_scan_days', 1);
        $plan = $this->daysPlan(1);
        $plan['timezone'] = 'Asia/Tokyo';
        $plan['profile_timezones']['carrier'] = 'America/Los_Angeles';
        $plan['profile_timezones']['warehouse'] = 'America/Los_Angeles';
        $plan['stages'][] = ['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 0];

        $this->assertPlanningFailure('2026-06-29T14:30:00Z', $plan, 'prepare', ['warehouse'], 1);
    }

    public function test_work_search_reaches_every_profile_local_date_inside_the_global_plan_horizon(): void
    {
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Wednesday' => [['01:00', '02:00']]]);
        $plan = $this->workPlan(0);
        $plan['timezone'] = 'America/New_York';

        $result = Workday::planDelivery('2026-06-29T23:00:00Z', $plan);

        $this->assertStage($result->stages[0], 'prepare', 'work', ['warehouse'], '2026-06-29 23:00', '2026-07-01 01:00', '2026-07-01 01:00');
        $this->assertSame('2026-06-30 21:00', $result->estimatedCompletionAt->setTimezone('America/New_York')->format('Y-m-d H:i'));
    }

    public function test_handoff_search_reaches_profile_dates_two_labels_after_the_utc_received_date(): void
    {
        config()->set('workdays.max_scan_days', 1);
        foreach (['warehouse', 'carrier'] as $profile) {
            config()->set('workdays.definitions.profiles.'.$profile.'.working_hours', ['Wednesday' => [['01:00', '02:00']]]);
        }
        $plan = $this->handoffPlan();
        $plan['timezone'] = 'America/New_York';

        $result = Workday::planDelivery('2026-06-29T23:00:00Z', $plan);

        $this->assertStage($result->stages[0], 'pickup', 'handoff', ['warehouse', 'carrier'], '2026-06-29 23:00', '2026-07-01 01:00', '2026-07-01 01:00');
    }

    #[DataProvider('midnightRollbackDurations')]
    public function test_plan_horizon_includes_the_repeated_last_date_after_a_midnight_rollback(int $minutes, string $completion): void
    {
        config()->set('workdays.max_scan_days', 1);
        config()->set('workdays.definitions.profiles.warehouse.working_hours', ['Saturday' => [['23:00', '23:30']]]);
        config()->set('workdays.definitions.profiles.warehouse.extra_working_days', ['1988-10-29' => 'Explicit historical fixture']);
        $plan = $this->workPlan($minutes);
        $plan['timezone'] = 'America/St_Johns';
        $plan['profile_timezones']['warehouse'] = 'America/New_York';

        $result = Workday::planDelivery('1988-10-28T12:00:00Z', $plan);

        $this->assertStage($result->stages[0], 'prepare', 'work', ['warehouse'], '1988-10-28 12:00', '1988-10-30 03:00', $completion);
        $this->assertSame('1988-10-29', $result->estimatedCompletionAt->setTimezone('America/St_Johns')->toDateString());
    }

    public static function midnightRollbackDurations(): array
    {
        return [
            'zero minutes normalize during repeated final date' => [0, '1988-10-30 03:00'],
            'positive duration consumes minutes during repeated final date' => [5, '1988-10-30 03:05'],
        ];
    }

    public function test_config_holiday_delays_work_without_reading_conflicting_database_definitions(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        config()->set('workdays.definitions.profiles.warehouse.custom_holidays', ['2026-06-29' => 'Configured closure']);

        $result = Workday::planDelivery('2026-06-29 10:00', $this->workPlan(60));

        $this->assertStage($result->stages[0], 'prepare', 'work', ['warehouse'], '2026-06-29 10:00', '2026-06-30 09:00', '2026-06-30 10:00');
    }

    public function test_config_reopening_overrides_its_holiday_for_work_and_handoff(): void
    {
        $this->publishConflictingDatabaseDefinitions();
        foreach (['warehouse', 'carrier'] as $profile) {
            config()->set('workdays.definitions.profiles.'.$profile.'.custom_holidays', ['2026-06-29' => 'Configured closure']);
            config()->set('workdays.definitions.profiles.'.$profile.'.extra_working_days', ['2026-06-29' => 'Configured reopening']);
        }
        $plan = $this->handoffPlan();
        array_unshift($plan['stages'], ['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 60]);

        $result = Workday::planDelivery('2026-06-29 13:00', $plan);

        $this->assertSame('2026-06-29T14:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
        $this->assertTrue(Workday::profile('warehouse')->isBusinessDay('2026-06-29'));
    }

    public function test_legacy_day_minute_and_cutoff_calls_keep_behavior_before_and_after_a_plan(): void
    {
        $calculator = Workday::profile('warehouse');
        $before = [
            $calculator->addBusinessDays('2026-06-29', 1)->toDateString(),
            $calculator->addBusinessMinutes('2026-06-29 11:30', 120)->format('Y-m-d H:i'),
            $calculator->resolveCutoff('2026-06-29 14:00')->toArray(),
            $calculator->workingWindowsFor('2026-06-29')[0]->toArray(),
        ];

        Workday::planDelivery('2026-06-29 13:30', self::referencePlan());

        $this->assertSame($before, [
            $calculator->addBusinessDays('2026-06-29', 1)->toDateString(),
            $calculator->addBusinessMinutes('2026-06-29 11:30', 120)->format('Y-m-d H:i'),
            $calculator->resolveCutoff('2026-06-29 14:00')->toArray(),
            $calculator->workingWindowsFor('2026-06-29')[0]->toArray(),
        ]);
        $this->assertSame('2026-06-30', $before[0]);
        $this->assertSame('2026-06-29 14:30', $before[1]);
    }

    private static function referencePlan(): array
    {
        return [
            'timezone' => 'UTC',
            'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC', 'last_mile' => 'UTC'],
            'cutoff_profile' => 'warehouse',
            'stages' => [
                ['id' => 'preparation', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120],
                ['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']],
                ['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => 2],
                ['id' => 'final_delivery', 'type' => 'work', 'profile' => 'last_mile', 'minutes' => 60],
            ],
        ];
    }

    private function profile(array $hours): array
    {
        return [
            'weekends' => ['Saturday', 'Sunday'],
            'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [], 'working_hours' => $hours,
        ];
    }

    private function hours(array $windows): array
    {
        return array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], $windows);
    }

    private function singleStage(array $stage): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC', 'last_mile' => 'UTC'], 'stages' => [$stage]];
    }

    private function workPlan(int $minutes): array
    {
        return $this->singleStage(['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => $minutes]);
    }

    private function daysPlan(int $days): array
    {
        return $this->singleStage(['id' => 'transit', 'type' => 'business_days', 'profile' => 'carrier', 'days' => $days]);
    }

    private function handoffPlan(): array
    {
        return $this->singleStage(['id' => 'pickup', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']]);
    }

    private function assertStage(DeliveryStageResult $stage, string $id, string $type, array $profiles, string $ready, string $start, string $end): void
    {
        $this->assertSame([
            'id' => $id, 'type' => $type, 'profiles' => $profiles,
            'ready_at' => $this->utc($ready), 'started_at' => $this->utc($start), 'completed_at' => $this->utc($end),
        ], $stage->toArray());
        foreach ([$stage->readyAt, $stage->startedAt, $stage->completedAt] as $instant) {
            $this->assertInstanceOf(CarbonImmutable::class, $instant);
            $this->assertSame(0, $instant->getOffset());
        }
        $this->assertTrue($stage->readyAt->lessThanOrEqualTo($stage->startedAt));
        $this->assertTrue($stage->startedAt->lessThanOrEqualTo($stage->completedAt));
    }

    private function assertPlanningFailure(string $input, array $plan, string $stage, array $profiles, int $limit): void
    {
        try {
            Workday::planDelivery($input, $plan);
            $this->fail('The required stage must fail rather than return a partial delivery estimate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($stage, $exception->getMessage());
            foreach ($profiles as $profile) {
                $this->assertStringContainsString($profile, $exception->getMessage());
            }
            $this->assertStringContainsString((string) $limit, $exception->getMessage());
        }
    }

    private function utc(string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone('UTC')->format('Y-m-d\TH:i:s.uP');
    }

    private function format(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.uP');
    }
}
