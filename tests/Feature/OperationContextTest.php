<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calendar\CalendarFingerprint;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\WorkdayDefinitions;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class OperationContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('workdays.driver', 'config');
        config()->set('workdays.max_scan_days', 62);
        config()->set('workdays.definitions', [
            'default_profile' => 'file_only',
            'include_start_date' => true,
            'hijri' => ['method' => 'umm_al_qura', 'adjustment' => 0],
            'profile_routing' => null,
            'profiles' => ['file_only' => ['weekends' => range(1, 7)]],
        ]);
    }

    public function test_bound_source_controls_every_public_engine_and_the_whole_bulk_batch(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $plan = $this->plan();
        $slots = ['profile' => 'child', 'timezone' => 'UTC', 'days' => 1, 'duration_minutes' => 60, 'step_minutes' => 60, 'max_slots' => 2];
        $recurrence = ['profile' => 'child', 'timezone' => 'UTC', 'frequency' => 'weekly', 'interval' => 1, 'count' => 2, 'adjustment' => 'next'];
        $sla = ['profile' => 'child', 'timezone' => 'UTC', 'target_minutes' => 120, 'pauses' => [['started_at' => '2026-11-02T09:30:00Z', 'ended_at' => '2026-11-02T10:00:00Z']]];
        $standalone = [
            $manager->isBusinessDay('2026-11-03'),
            $manager->workingWindowsFor('2026-11-02'),
            $manager->addBusinessDays('2026-11-02', 1),
            $manager->addBusinessMinutes('2026-11-02T09:00:00Z', 120),
            $manager->resolveCutoff('2026-11-02T10:00:00Z'),
            $manager->planDelivery('2026-11-02T09:00:00Z', $plan),
            $manager->deliverySlots('2026-11-02T08:00:00Z', $slots),
            $manager->recurringSchedule('2026-11-03', $recurrence),
            $manager->evaluateSla('2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z', $sla),
            $manager->reversePlanDelivery('2026-11-02T12:00:00Z', $plan, ['lookback_days' => 1]),
        ];

        $this->assertSame(10, $source->opens);
        $this->assertFalse($standalone[0]);
        $this->assertSame([['start' => '09:00', 'end' => '17:00']], $this->serialized($standalone[1]));
        $this->assertSame('2026-11-04', $standalone[2]->toDateString());
        $this->assertSame('11:00:00', $standalone[3]->format('H:i:s'));
        $this->assertTrue($standalone[4]->sameDayEligible);
        $this->assertSame('11:00:00', $standalone[4]->cutoffAt->format('H:i:s'));
        $this->assertSame('11:00:00', $standalone[5]->estimatedCompletionAt->format('H:i:s'));
        $this->assertSame('09:00:00', $standalone[6]->slots[0]->startsAt->format('H:i:s'));
        $this->assertSame(['2026-11-04', '2026-11-10'], array_column($standalone[7]->toArray()['occurrences'], 'scheduled_date'));
        $this->assertSame(30, $standalone[8]->consumedWorkingMinutes);
        $this->assertSame('11:30:00', $standalone[8]->dueAt->format('H:i:s'));
        $this->assertTrue($standalone[9]->feasible);
        $this->assertSame('12:00:00', $standalone[9]->estimatedCompletionAt->format('H:i:s'));

        $operations = [
            ['id' => 'day', 'operation' => 'is_business_day', 'profile' => 'child', 'date' => '2026-11-03'],
            ['id' => 'windows', 'operation' => 'working_windows', 'profile' => 'child', 'date' => '2026-11-02'],
            ['id' => 'days', 'operation' => 'add_business_days', 'profile' => 'child', 'date' => '2026-11-02', 'days' => 1],
            ['id' => 'minutes', 'operation' => 'add_business_minutes', 'profile' => 'child', 'datetime' => '2026-11-02T09:00:00Z', 'minutes' => 120],
            ['id' => 'cutoff', 'operation' => 'resolve_cutoff', 'profile' => 'child', 'received_at' => '2026-11-02T10:00:00Z'],
            ['id' => 'plan', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T09:00:00Z', 'plan' => $plan],
            ['id' => 'slots', 'operation' => 'delivery_slots', 'ready_at' => '2026-11-02T08:00:00Z', 'options' => $slots],
            ['id' => 'recurrence', 'operation' => 'recurring_schedule', 'anchor_date' => '2026-11-03', 'options' => $recurrence],
            ['id' => 'sla', 'operation' => 'evaluate_sla', 'started_at' => '2026-11-02T09:00:00Z', 'evaluated_at' => '2026-11-02T10:00:00Z', 'options' => $sla],
            ['id' => 'reverse', 'operation' => 'reverse_plan_delivery', 'desired_completion_at' => '2026-11-02T12:00:00Z', 'plan' => $plan, 'options' => ['lookback_days' => 1]],
        ];
        $bulk = $manager->bulkCalculate($operations);

        $this->assertSame(11, $source->opens);
        $this->assertSame(10, $bulk->succeeded);
        foreach ($bulk->items as $index => $item) {
            $this->assertSame($this->serialized($standalone[$index]), $item->toArray()['result'], $item->operation);
            if (is_object($standalone[$index])) {
                $this->assertSame($standalone[$index]::class, $item->result::class);
            }
        }
    }

    public function test_profile_for_routes_and_constructs_from_one_view_and_next_operation_is_fresh(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $source->afterOpen = function () use ($source): void {
            $source->profiles['base']['working_hours']['Monday'] = [['13:00', '17:00']];
            $source->settings['profile_routing']['fallback_profile'] = 'base';
            $source->afterOpen = null;
        };

        $old = $manager->profileFor(['channel' => 'priority']);
        $this->assertSame(1, $source->opens);
        $this->assertTrue($old->isBusinessTime('2026-11-02T09:30:00Z'));
        $this->assertFalse($old->isBusinessDay('2026-11-03'));
        $this->assertSame('child', $manager->resolveProfile(['channel' => 'priority'])->profile);
        $this->assertFalse($manager->profileFor(['channel' => 'priority'])->isBusinessTime('2026-11-02T09:30:00Z'));
        $this->assertTrue($old->isBusinessTime('2026-11-02T09:30:00Z'));
        $this->assertSame(3, $source->opens);
    }

    public function test_config_calculator_retains_its_view_while_same_manager_sees_new_config(): void
    {
        config()->set('workdays.definitions', $this->fileDefinitions());
        $manager = $this->app->make(WorkdayManager::class);
        $old = $manager->profile('child');
        config()->set('workdays.definitions.profiles.base.custom_holidays', ['2026-11-02' => 'Changed parent']);
        config()->set('workdays.definitions.profiles.base.working_hours.Monday', [['13:00', '17:00']]);

        $this->assertTrue($old->isBusinessDay('2026-11-02'));
        $this->assertTrue($old->isBusinessTime('2026-11-02T09:30:00Z'));
        $this->assertFalse($manager->profile('child')->isBusinessDay('2026-11-02'));
        $this->assertFalse($manager->isBusinessDay('2026-11-02'));
        $this->assertFalse($old->isBusinessDay('2026-11-03'));
    }

    public function test_hijri_settings_are_from_the_source_and_retained_calculators_keep_their_adapter(): void
    {
        $source = $this->memorySource();
        $source->settings['hijri'] = ['method' => 'mathematical', 'adjustment' => 1];
        $day = CarbonImmutable::parse('2025-02-14', 'UTC');
        $adjusted = (new HijriCalendarAdapter('mathematical', 1))->monthDayFromGregorian($day);
        $unadjusted = (new HijriCalendarAdapter('mathematical', 0))->monthDayFromGregorian($day);
        $source->profiles['base']['holidays'] = ['hijri' => [$adjusted => 'Source Hijri holiday']];
        $manager = $this->bindSource($source);
        $old = $manager->profile('child');

        $this->assertNotSame($adjusted, $unadjusted);
        $this->assertTrue($old->isHijriHoliday($day));
        $source->settings['hijri']['adjustment'] = 0;
        $this->assertFalse($manager->isHijriHoliday($day));
        $this->assertTrue($old->isHijriHoliday($day));
    }

    public function test_batch_keeps_source_and_bootstrap_snapshot_after_mid_operation_mutation(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $source->onRead = function () use ($source): void {
            $source->profiles['base']['custom_holidays'] = ['2026-11-02' => 'Next view closure'];
            $source->profiles['base']['working_hours']['Monday'] = [['13:00', '17:00']];
            config()->set('workdays.max_scan_days', 1);
            config()->set('workdays.definitions.profiles', []);
            $source->onRead = null;
        };
        $operations = [
            ['id' => 'before', 'operation' => 'is_business_day', 'profile' => 'child', 'date' => '2026-11-02'],
            ['id' => 'still-old', 'operation' => 'add_business_minutes', 'profile' => 'child', 'datetime' => '2026-11-02T09:00:00Z', 'minutes' => 120],
            ['id' => 'horizon', 'operation' => 'recurring_schedule', 'anchor_date' => '2026-11-02', 'options' => ['profile' => 'child', 'timezone' => 'UTC', 'frequency' => 'weekly', 'interval' => 1, 'count' => 2, 'adjustment' => 'next']],
        ];
        $first = $manager->bulkCalculate($operations);

        $this->assertSame(1, $source->opens);
        $this->assertSame(3, $first->succeeded);
        $this->assertTrue($first->items[0]->result);
        $this->assertSame('11:00:00', $first->items[1]->result->format('H:i:s'));
        $this->assertSame('2026-11-09', $first->items[2]->result->occurrences[1]->scheduledDate);
        $next = $manager->bulkCalculate([$operations[0], $operations[2]]);
        $this->assertSame(2, $source->opens);
        $this->assertFalse($next->items[0]->result);
        $this->assertFalse($next->items[1]->success);
    }

    public function test_context_reuses_effective_profiles_provider_and_actual_limit_calculators(): void
    {
        $source = $this->memorySource();
        $this->bindSource($source);
        $events = [];
        $context = $this->app->make(CalculationContextFactory::class)->open(function (...$event) use (&$events): void {
            $events[] = $event;
        });

        $one = $context->calculator('child', 1);
        $this->assertSame($one, $context->calculator('child', 1));
        $this->assertNotSame($one, $context->calculator('child', 2));
        $this->assertSame(1, count(array_filter($events, static fn (array $event): bool => $event[0] === 'provider')));
        $this->assertSame(2, count(array_filter($events, static fn (array $event): bool => $event[0] === 'calculator')));
        $this->assertSame(1, array_count_values($source->reads)['base']);
        $this->assertSame(1, array_count_values($source->reads)['child']);
        $this->assertArrayNotHasKey('special_working_hours', $context->configuration('child'));
        $this->assertArrayNotHasKey('seasonal_working_hours', $context->configuration('child'));
    }

    public function test_source_and_config_arrays_do_not_keep_external_mutable_references(): void
    {
        $holiday = '2026-11-03';
        $hours = [['09:00', '17:00']];
        $definitions = $this->fileDefinitions();
        $definitions['profiles']['base']['custom_holidays'] = [$holiday => 'Original'];
        $definitions['profiles']['base']['working_hours']['Monday'] = &$hours;
        config()->set('workdays.definitions', $definitions);
        $manager = $this->app->make(WorkdayManager::class);
        $old = $manager->profile('child');
        $hours = [['13:00', '17:00']];

        $this->assertTrue($old->isBusinessTime('2026-11-02T09:30:00Z'));
        $this->assertFalse($manager->profile('child')->isBusinessTime('2026-11-02T09:30:00Z'));
    }

    public function test_snapshot_capture_restore_and_fresh_comparison_use_explicit_source_views(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $source->afterOpen = function () use ($source): void {
            $source->profiles['base']['working_hours']['Monday'] = [['10:00', '17:00']];
            $source->afterOpen = null;
        };
        $snapshot = $manager->captureDeliveryPromise('2026-11-02T08:00:00Z', $this->plan(), ['horizon_days' => 7]);
        $original = $snapshot->toArray();
        $restored = DeliveryPromiseSnapshot::fromArray(json_decode(json_encode($original, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertSame(1, $source->opens);
        $this->assertSame('11:00:00', $restored->originalResult->estimatedCompletionAt->format('H:i:s'));
        $comparison = $manager->compareDeliveryPromise($restored);
        $this->assertSame(2, $source->opens);
        $this->assertSame('later', $comparison->direction);
        $this->assertSame('12:00:00', $comparison->currentEstimatedCompletionAt->format('H:i:s'));
        $this->assertSame($original, $snapshot->toArray());
        $this->assertSame($original, $restored->toArray());
    }

    public function test_snapshot_restore_uses_captured_replay_bound_even_when_live_source_is_unavailable(): void
    {
        $manager = $this->bindSource($this->memorySource());
        $snapshot = $manager->captureDeliveryPromise('2026-11-02T09:00:00Z', $this->plan(), ['horizon_days' => 7]);
        $payload = $snapshot->toArray();
        config()->set('workdays.driver', 'database');
        config()->set('workdays.max_scan_days', null);
        config()->set('workdays.definitions', null);

        $this->assertSame($payload, DeliveryPromiseSnapshot::fromArray($payload)->toArray());
        $payload['plan']['stages'][0]['minutes'] = 121;
        unset($payload['integrity_hash']);
        $payload['integrity_hash'] = CalendarFingerprint::integrity($payload);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('original result is inconsistent');
        DeliveryPromiseSnapshot::fromArray($payload);
    }

    public function test_selected_source_failure_is_not_collected_as_a_batch_domain_error(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $source->onRead = static function (): void {
            throw new DefinitionSourceException('Test source read failed.');
        };

        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('Test source read failed');
        $manager->bulkCalculate([['id' => 'read', 'operation' => 'is_business_day', 'profile' => 'child', 'date' => '2026-11-02']]);
    }

    public function test_lazy_source_failure_during_snapshot_comparison_remains_visible(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $snapshot = $manager->captureDeliveryPromise('2026-11-02T09:00:00Z', $this->plan(), ['horizon_days' => 7]);
        $source->onRead = static function (): void {
            throw new DefinitionSourceException('Historical source connection failed.');
        };

        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('Historical source connection failed');
        $manager->compareDeliveryPromise($snapshot);
    }

    public function test_lazy_source_failure_during_delivery_keeps_the_source_exception_type(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $source->onRead = static function (): void {
            throw new DefinitionSourceException('Delivery source read failed.');
        };

        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('Delivery source read failed');
        $manager->planDelivery('2026-11-02T09:00:00Z', $this->plan());
    }

    public function test_bulk_delivery_does_not_collect_or_wrap_a_lazy_source_failure(): void
    {
        $source = $this->memorySource();
        $manager = $this->bindSource($source);
        $failure = new DefinitionSourceException('Bulk delivery source read failed.');
        $source->onRead = static function () use ($failure): void {
            throw $failure;
        };

        try {
            $manager->bulkCalculate([['id' => 'delivery', 'operation' => 'plan_delivery', 'received_at' => '2026-11-02T09:00:00Z', 'plan' => $this->plan()]]);
            $this->fail('Source errors must escape collect mode.');
        } catch (DefinitionSourceException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_rules_macros_and_facade_follow_the_bound_source(): void
    {
        $source = $this->memorySource();
        $this->bindSource($source);
        $this->assertFalse(Workday::isBusinessDay('2026-11-03'));
        $this->assertFalse(CarbonImmutable::parse('2026-11-03', 'UTC')->workdayIsBusinessDay());
        $failed = [];
        WorkdayRule::businessDay()->validate('day', '2026-11-03', function (string $message) use (&$failed): void {
            $failed[] = $message;
        });
        $this->assertCount(1, $failed);
        $this->assertSame(3, $source->opens);
    }

    private function bindSource(OperationMemorySource $source): WorkdayManager
    {
        $this->app->instance(DefinitionSource::class, $source);
        $this->app->forgetInstance(CalculationContextFactory::class);
        $this->app->forgetInstance(WorkdayManager::class);
        Workday::clearResolvedInstance('workdays');

        return $this->app->make(WorkdayManager::class);
    }

    private function memorySource(): OperationMemorySource
    {
        $definitions = $this->fileDefinitions();
        $profiles = $definitions['profiles'];
        unset($definitions['profiles']);

        return new OperationMemorySource($definitions, $profiles);
    }

    private function fileDefinitions(): array
    {
        return [
            'default_profile' => 'child',
            'include_start_date' => false,
            'hijri' => ['method' => 'mathematical', 'adjustment' => 0],
            'profile_routing' => ['fallback_profile' => 'child', 'rules' => [['id' => 'priority', 'match' => ['channel' => 'priority'], 'profile' => 'child']]],
            'profiles' => [
                'base' => [
                    'weekends' => ['Saturday', 'Sunday'],
                    'holidays' => [],
                    'custom_holidays' => ['2026-11-03' => 'Inherited closure'],
                    'working_hours' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '17:00']]),
                    'cutoff' => ['timezone' => 'UTC', 'time' => '10:00'],
                ],
                'child' => ['extends' => 'base', 'working_hours' => ['Thursday' => [['09:00', '12:00']]], 'cutoff' => ['time' => '11:00']],
                'unrelated_invalid' => ['extends' => 'missing'],
            ],
        ];
    }

    private function plan(): array
    {
        return ['timezone' => 'UTC', 'profile_timezones' => ['child' => 'UTC'], 'stages' => [['id' => 'work', 'type' => 'work', 'profile' => 'child', 'minutes' => 120]]];
    }

    private function serialized(mixed $value): mixed
    {
        if ($value instanceof CarbonImmutable) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (is_array($value)) {
            return array_map($this->serialized(...), $value);
        }

        return is_object($value) ? $value->toArray() : $value;
    }
}

/** A detached in-memory source exercises the seam; it is not a Database implementation. */
final class OperationMemorySource implements DefinitionSource
{
    public int $opens = 0;

    public array $reads = [];

    public ?Closure $afterOpen = null;

    public ?Closure $onRead = null;

    public function __construct(public array $settings, public array $profiles) {}

    public function open(): WorkdayDefinitions
    {
        $this->opens++;
        $settings = WorkdayDefinitions::detach($this->settings, 'Test source settings');
        $profiles = WorkdayDefinitions::detach($this->profiles, 'Test source profiles');
        ($this->afterOpen)?->__invoke();

        return new WorkdayDefinitions($settings, function (string $name) use ($profiles): mixed {
            $this->reads[] = $name;
            ($this->onRead)?->__invoke($name);

            return $profiles[$name];
        }, static fn (string $name): bool => array_key_exists($name, $profiles));
    }
}
