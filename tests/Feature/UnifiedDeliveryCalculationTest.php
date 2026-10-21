<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Data\DeliverySlot;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class UnifiedDeliveryCalculationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        config()->set('app.timezone', 'Pacific/Honolulu');
        config()->set('workdays.max_scan_days', 4);
    }

    #[DataProvider('equivalentSchedules')]
    public function test_forward_work_preserves_fractional_capacity_across_a_break_for_equivalent_schedules(string $source, array $options): void
    {
        $this->configure($source, ['Monday' => [['09:00', '10:00'], ['11:00', '12:00']]], $options);

        $result = Workday::planDelivery('2026-11-02T09:59:30.123456Z', $this->plan(1));

        $this->assertSame('2026-11-02T09:59:30.123456+00:00', $this->format($result->stages[0]->startedAt));
        $this->assertSame('2026-11-02T11:00:30.123456+00:00', $this->format($result->estimatedCompletionAt));
    }

    #[DataProvider('equivalentSchedules')]
    public function test_fractional_capacity_on_the_final_plan_date_is_counted_before_integer_presentation(string $source, array $options): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->configure($source, ['Monday' => [['09:00', '10:00']], 'Tuesday' => [['14:59', '15:00']]], $options);
        $plan = $this->plan(1);
        $plan['timezone'] = 'Asia/Tokyo';

        $result = Workday::planDelivery('2026-11-02T09:59:30.123456Z', $plan);

        $this->assertSame('2026-11-03T14:59:30.123456+00:00', $this->format($result->estimatedCompletionAt));
        $this->assertSame('2026-11-03 23:59:30.123456', $result->estimatedCompletionAt->setTimezone('Asia/Tokyo')->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, config('workdays.max_scan_days'));
    }

    #[DataProvider('equivalentSchedules')]
    public function test_reverse_removes_only_the_incorrect_work_plateau_and_forward_proves_the_latest_microsecond(string $source, array $options): void
    {
        $this->configure($source, ['Monday' => [['09:00', '12:00'], ['13:00', '17:00']]], $options);
        $plan = $this->plan(120);

        $result = Workday::reversePlanDelivery('2026-11-02T14:00:00Z', $plan, ['lookback_days' => 1]);

        $this->assertTrue($result->feasible);
        $this->assertSame('2026-11-02T11:00:00.000000+00:00', $this->format($result->latestOrderAt));
        $this->assertSame('2026-11-02T14:00:00.000000+00:00', $this->format($result->estimatedCompletionAt));
        $this->assertSame($result->forwardResult->toArray(), Workday::planDelivery($result->latestOrderAt, $plan)->toArray());
        $this->assertSame('2026-11-02T14:00:00.000001+00:00', $this->format(Workday::planDelivery($result->latestOrderAt->addMicrosecond(), $plan)->estimatedCompletionAt));
        $this->assertSame('2026-11-02T14:00:00.999999+00:00', $this->format(Workday::planDelivery('2026-11-02T11:00:00.999999Z', $plan)->estimatedCompletionAt));
    }

    #[DataProvider('dstSchedules')]
    public function test_ordinary_daytime_dst_work_uses_real_elapsed_minutes(string $source, string $start, string $completion): void
    {
        $this->configure($source, ['Sunday' => [['00:00', '04:00']], 'Monday' => [['09:00', '17:00']]]);
        $plan = $this->plan(60);
        $plan['timezone'] = $plan['profile_timezones']['warehouse'] = 'America/New_York';

        $result = Workday::planDelivery($start, $plan);

        $this->assertSame($completion, $this->format($result->estimatedCompletionAt));
        $this->assertSame($completion, $this->format(Workday::profile('warehouse')->addBusinessMinutes(CarbonImmutable::parse($start)->setTimezone('America/New_York'), 60)->setTimezone('UTC')));
    }

    #[DataProvider('sources')]
    public function test_core_final_slice_may_end_at_midnight_while_the_plan_result_date_limit_stays_stricter(string $source): void
    {
        config()->set('workdays.max_scan_days', 1);
        $this->configure($source, ['Tuesday' => [['23:00', '24:00']]], ['allow_overnight_working_hours' => true]);
        $receipt = '2026-11-02T23:00:00Z';

        $this->assertSame('2026-11-04T00:00:00.000000+00:00', $this->format(Workday::profile('warehouse')->addBusinessMinutes($receipt, 60)));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('global horizon');

        Workday::planDelivery($receipt, $this->plan(60));
    }

    #[DataProvider('sources')]
    public function test_reverse_retains_the_legitimate_exclusive_handoff_microsecond(string $source): void
    {
        $this->configure($source, ['Monday' => [['09:00', '17:00']]], additionalProfiles: ['carrier' => ['weekends' => [], 'holidays' => [], 'working_hours' => ['Monday' => [['12:00', '18:00']]]]]);
        $plan = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC', 'carrier' => 'UTC'], 'stages' => [['id' => 'handoff', 'type' => 'handoff', 'profiles' => ['warehouse', 'carrier']]]];

        $result = Workday::reversePlanDelivery('2026-11-02T17:00:00Z', $plan, ['lookback_days' => 1]);

        $this->assertTrue($result->feasible);
        $this->assertSame('2026-11-02T16:59:59.999999+00:00', $this->format($result->latestOrderAt));
        $this->assertSame($result->forwardResult->toArray(), Workday::planDelivery($result->latestOrderAt, $plan)->toArray());
        $this->assertFalse(Workday::profile('warehouse')->isBusinessTime($result->latestOrderAt->addMicrosecond()));
    }

    #[DataProvider('sources')]
    public function test_reverse_business_day_solver_keeps_the_later_disconnected_dst_region(string $source): void
    {
        $this->configure($source, []);
        $plan = ['timezone' => 'America/New_York', 'profile_timezones' => ['warehouse' => 'America/New_York'], 'stages' => [['id' => 'transit', 'type' => 'business_days', 'profile' => 'warehouse', 'days' => 1]]];

        $result = Workday::reversePlanDelivery('2026-11-02T01:15:00-05:00', $plan, ['lookback_days' => 2]);

        $this->assertTrue($result->feasible);
        $this->assertSame('2026-11-01T06:15:00.000000+00:00', $this->format($result->latestOrderAt));
        $this->assertSame('2026-11-01T01:15:00.000000-05:00', $this->format($result->latestOrderAt->setTimezone('America/New_York')));
        $this->assertTrue(Workday::planDelivery('2026-11-01T01:45:00-04:00', $plan)->estimatedCompletionAt->greaterThan($result->targetCompletionAt));
        $this->assertTrue(Workday::planDelivery('2026-11-01T01:00:00-05:00', $plan)->estimatedCompletionAt->lessThan($result->targetCompletionAt));
        $this->assertTrue(Workday::planDelivery($result->latestOrderAt->addMicrosecond(), $plan)->estimatedCompletionAt->greaterThan($result->targetCompletionAt));
    }

    #[DataProvider('sources')]
    public function test_slots_keep_touching_windows_and_midnight_as_separate_policy_boundaries(string $source): void
    {
        $this->configure($source, ['Monday' => [['22:00', '23:00'], ['23:00', '02:00']]], ['allow_overnight_working_hours' => true, 'custom_holidays' => ['2026-11-03' => 'Receiving date closed']]);
        $options = ['profile' => 'warehouse', 'timezone' => 'UTC', 'days' => 2, 'duration_minutes' => 120, 'step_minutes' => 60];
        $serialize = fn (DeliverySlot $slot): array => [$this->format($slot->startsAt), $this->format($slot->endsAt)];

        $long = Workday::deliverySlots('2026-11-02T22:00:00Z', $options);
        $short = Workday::deliverySlots('2026-11-02T22:00:00Z', array_replace($options, ['duration_minutes' => 60]));

        $this->assertSame([['2026-11-03T00:00:00.000000+00:00', '2026-11-03T02:00:00.000000+00:00']], array_map($serialize, $long->slots));
        $this->assertSame([
            ['2026-11-02T22:00:00.000000+00:00', '2026-11-02T23:00:00.000000+00:00'],
            ['2026-11-02T23:00:00.000000+00:00', '2026-11-03T00:00:00.000000+00:00'],
            ['2026-11-03T00:00:00.000000+00:00', '2026-11-03T01:00:00.000000+00:00'],
            ['2026-11-03T01:00:00.000000+00:00', '2026-11-03T02:00:00.000000+00:00'],
        ], array_map($serialize, $short->slots));
        $this->assertFalse(Workday::profile('warehouse')->isBusinessDay('2026-11-03'));
    }

    #[DataProvider('hugeDurations')]
    public function test_unsupported_work_duration_is_rejected_before_forward_or_reverse_capacity_search(string $source, bool $reverse): void
    {
        $this->configure($source, ['Monday' => [['09:00', '17:00']]]);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('microsecond');

        if ($reverse) {
            Workday::reversePlanDelivery('2026-11-02T17:00:00Z', $this->plan(PHP_INT_MAX), ['lookback_days' => 1]);
        } else {
            Workday::planDelivery('2026-11-02T09:00:00Z', $this->plan(PHP_INT_MAX));
        }
    }

    #[DataProvider('sources')]
    public function test_unrepresentable_plan_date_horizon_is_rejected_before_carbon_date_arithmetic(string $source): void
    {
        $this->configure($source, ['Monday' => [['09:00', '17:00']]]);
        config()->set('workdays.max_scan_days', PHP_INT_MAX);
        $this->expectException(WorkdayConfigurationException::class);
        $this->expectExceptionMessage('Invalid delivery planning horizon');

        Workday::planDelivery('2026-11-02T09:00:00Z', $this->plan(1));
    }

    public static function equivalentSchedules(): array
    {
        $cases = [];
        foreach (['config', 'database'] as $source) {
            foreach (['plain' => [], 'false' => ['allow_overnight_working_hours' => false], 'true' => ['allow_overnight_working_hours' => true], 'unused empty overlays' => ['special_working_hours' => [], 'seasonal_working_hours' => []]] as $name => $options) {
                $cases[$source.' '.$name] = [$source, $options];
            }
        }

        return $cases;
    }

    public static function dstSchedules(): array
    {
        $cases = [];
        foreach (['config', 'database'] as $source) {
            $cases[$source.' spring'] = [$source, '2026-03-08T03:30:00-04:00', '2026-03-09T13:30:00.000000+00:00'];
            $cases[$source.' fall'] = [$source, '2026-11-01T02:30:00-05:00', '2026-11-01T08:30:00.000000+00:00'];
        }

        return $cases;
    }

    public static function sources(): array
    {
        return [['config'], ['database']];
    }

    public static function hugeDurations(): array
    {
        return [['config', false], ['config', true], ['database', false], ['database', true]];
    }

    private function configure(string $source, array $hours, array $options = [], array $additionalProfiles = []): void
    {
        $definitions = ['default_profile' => 'warehouse', 'include_start_date' => false, 'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null,
            'profiles' => ['warehouse' => array_replace(['weekends' => [], 'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []], 'custom_holidays' => [], 'extra_working_days' => [], 'working_hours' => $hours], $options)] + $additionalProfiles];
        config()->set('workdays.definitions', $definitions);
        config()->set('workdays.driver', $source);
        if ($source === 'database') {
            $this->app->make(DatabaseDefinitionRepository::class)->publish($definitions, expectedRevisionId: null);
        }
    }

    private function plan(int $minutes): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC'], 'stages' => [['id' => 'prepare', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => $minutes]]];
    }

    private function format(CarbonImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s.uP');
    }
}
