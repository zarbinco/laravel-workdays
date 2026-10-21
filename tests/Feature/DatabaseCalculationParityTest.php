<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Rules\WorkdayRule;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class DatabaseCalculationParityTest extends TestCase
{
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        $this->fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/refactor01-definitions-and-operations.json'), true, 512, JSON_THROW_ON_ERROR);
        config()->set('workdays.max_scan_days', 62);
        config()->set('workdays.definitions', $this->fixture['definitions']);
        $this->app->make(DatabaseDefinitionRepository::class)->publish($this->fixture['definitions'], expectedRevisionId: null);
    }

    public function test_all_ten_operations_types_microseconds_and_full_bulk_outputs_match_both_sources(): void
    {
        $outputs = [];
        foreach (['config', 'database'] as $source) {
            config()->set('workdays.driver', $source);
            $manager = $this->app->make(WorkdayManager::class);
            foreach ($this->fixture['operations'] as $operation) {
                $outputs[$source][$operation['id']] = $this->typed($this->standalone($manager, $operation));
            }
            $batch = $manager->bulkCalculate($this->fixture['operations']);
            $this->assertSame(10, $batch->succeeded);
            foreach ($batch->items as $index => $item) {
                $this->assertSame($outputs[$source][$item->id]['value'], $item->toArray()['result']);
                $this->assertSame($outputs[$source][$item->id]['type'], get_debug_type($item->result));
            }
        }
        $this->assertSame($outputs['config'], $outputs['database']);
        // Exact elapsed work preserves the starting seconds through lunch (formerly 14:31).
        $this->assertSame('2026-11-02T14:30:12.123456+00:00', $outputs['database']['minutes']['value']);
    }

    #[DataProvider('sources')]
    public function test_calendars_reopening_disabled_cutoff_special_seasonal_closed_and_overnight_windows(string $source): void
    {
        $definitions = $this->fixture['definitions'];
        $monday = CarbonImmutable::parse('2026-11-02', 'UTC');
        $definitions['profiles']['parent']['holidays'] = [
            'gregorian' => ['11-02' => 'Gregorian'],
            'jalali' => [(new JalaliCalendarAdapter)->monthDayFromGregorian($monday) => 'Jalali'],
            'hijri' => [(new HijriCalendarAdapter('mathematical', 0))->monthDayFromGregorian($monday) => 'Hijri'],
        ];
        $definitions['profiles']['child']['extra_working_days'] = ['2026-11-02' => 'Inherited holiday reopened'];
        $definitions['profiles']['child']['cutoff']['weekdays'] = ['Monday' => null];
        $definitions['profiles']['child']['special_working_hours'] = ['2026-11-02' => [['10:00', '11:00']], '2026-11-04' => []];
        $definitions['profiles']['child']['seasonal_working_hours'] = [['from' => '2026-11-05', 'through' => '2026-11-06', 'weekdays' => ['Thursday' => [['15:00', '16:00']]]]];
        $this->replace($definitions);
        config()->set('workdays.driver', $source);
        $calculator = Workday::profile('child');

        $this->assertTrue($calculator->isGregorianHoliday($monday));
        $this->assertTrue($calculator->isJalaliHoliday($monday));
        $this->assertTrue($calculator->isHijriHoliday($monday));
        $this->assertTrue($calculator->isBusinessDay($monday));
        $day = $calculator->explain($monday)->toArray();
        $this->assertSame(['gregorian_holiday', 'jalali_holiday', 'hijri_holiday', 'extra_working_day'], array_column($day['reasons'], 'type'));
        $this->assertSame([true, true, true, false], array_column($day['reasons'], 'overridden'));
        $this->assertSame($source, $day['reasons'][3]['source']);
        $this->assertSame('cutoff_disabled', $calculator->resolveCutoff('2026-11-02T10:00:00Z')->reason);
        $this->assertSame([['start' => '10:00', 'end' => '11:00']], $this->plain($calculator->workingWindowsFor($monday)));
        $this->assertSame([], $calculator->workingWindowsFor('2026-11-04'));
        $this->assertSame([['start' => '15:00', 'end' => '16:00']], $this->plain($calculator->workingWindowsFor('2026-11-05')));
        $this->assertSame('child', Workday::resolveProfile(['kind' => 'support'])->profile);
        $this->assertSame($day, Workday::profileFor(['kind' => 'support'])->explain($monday)->toArray());
        $this->assertSame('2026-11-03T01:45:12.123456+00:00', Workday::profile('night')->addBusinessMinutes('2026-11-02T23:45:12.123456Z', 120)->format('Y-m-d\TH:i:s.uP'));
        $definitions['profiles']['parent']['holidays'] = [];
        $this->replace($definitions);
        $this->assertTrue($calculator->isGregorianHoliday($monday));
        $this->assertTrue($calculator->isJalaliHoliday($monday));
        $this->assertTrue($calculator->isHijriHoliday($monday));
        $this->assertFalse(Workday::profile('child')->isCalendarHoliday($monday));
    }

    public function test_all_core_arithmetic_explanations_dst_sla_recurrence_rules_and_macros_match_exactly(): void
    {
        $results = [];
        foreach (['config', 'database'] as $source) {
            config()->set('workdays.driver', $source);
            $manager = $this->app->make(WorkdayManager::class);
            $calculator = $manager->profile('warehouse');
            foreach ([
                'isHoliday' => ['2026-11-03'], 'isNonWorkingDay' => ['2026-11-07'], 'isWeekend' => ['2026-11-07'],
                'isCalendarHoliday' => ['2026-11-03'], 'isCustomHoliday' => ['2026-11-03'], 'isExtraWorkingDay' => ['2026-11-07'],
                'isBusinessTime' => ['2026-11-02T12:00:00Z'], 'nextBusinessTime' => ['2026-11-02T12:00:00Z'],
                'previousBusinessTime' => ['2026-11-02T13:00:00Z'], 'addBusinessHours' => ['2026-11-02T11:30:12.123456Z', 1.5],
                'diffBusinessMinutes' => ['2026-11-02T11:30:00Z', '2026-11-04T10:00:00Z'],
                'diffBusinessHours' => ['2026-11-02T11:30:00Z', '2026-11-04T10:00:00Z'],
                'subBusinessDays' => ['2026-11-04', 1], 'nextBusinessDay' => ['2026-11-02'],
                'previousBusinessDay' => ['2026-11-04'], 'diffBusinessDays' => ['2026-11-02', '2026-11-09'],
                'calculate' => ['2026-11-02', 3],
            ] as $method => $arguments) {
                $results[$source][$method] = $this->typed($calculator->$method(...$arguments));
            }
            $results[$source]['fold'] = $this->typed($manager->evaluateSla('2026-11-01T00:00:00-04:00', '2026-11-01T03:00:00-05:00', ['profile' => 'fold', 'timezone' => 'America/New_York', 'target_minutes' => 180]));
            $results[$source]['spring'] = $this->typed($manager->evaluateSla('2026-03-08T00:00:00-05:00', '2026-03-08T04:00:00-04:00', ['profile' => 'fold', 'timezone' => 'America/New_York', 'target_minutes' => 180]));
            $results[$source]['macro'] = CarbonImmutable::parse('2026-11-03', 'UTC')->workdayIsBusinessDay();
            $failures = [];
            WorkdayRule::businessDay('warehouse')->validate('day', '2026-11-03', function (string $message) use (&$failures): void {
                $failures[] = $message;
            });
            $results[$source]['rule'] = $failures;
            $recurrence = $manager->recurringSchedule('2026-11-03', ['profile' => 'warehouse', 'timezone' => 'UTC', 'frequency' => 'monthly', 'interval' => 1, 'count' => 3, 'adjustment' => 'next']);
            $results[$source]['monthly'] = $recurrence->toArray();
            $this->assertSame(['2026-11-03', '2026-12-03', '2027-01-03'], array_column($recurrence->toArray()['occurrences'], 'nominal_date'));
        }
        $this->assertSame($results['config'], $results['database']);
        $this->assertFalse($results['database']['macro']);
        $this->assertCount(1, $results['database']['rule']);
    }

    public function test_snapshot_cross_driver_differences_are_only_named_provenance_and_derived_hashes(): void
    {
        $snapshots = [];
        foreach (['config', 'database'] as $source) {
            config()->set('workdays.driver', $source);
            $snapshots[$source] = Workday::captureDeliveryPromise('2026-11-02T10:00:12.123456Z', $this->fixture['plan'], ['horizon_days' => 7])->toArray();
        }
        $config = $snapshots['config'];
        $database = $snapshots['database'];
        $this->assertNotSame($config['calendar_version'], $database['calendar_version']);
        $this->assertNotSame($config['integrity_hash'], $database['integrity_hash']);
        // Assert and map exactly the provenance fields whose contract differs between sources.
        foreach ($config['calendar_evidence']['profiles'] as $name => &$profile) {
            foreach (['custom', 'extra'] as $section) {
                foreach ($profile['holiday_inputs'][$section] as $key => &$metadata) {
                    $this->assertSame('config', $metadata['source']);
                    $this->assertSame('database', $database['calendar_evidence']['profiles'][$name]['holiday_inputs'][$section][$key]['source']);
                    $metadata['source'] = 'database';
                }
                unset($metadata);
            }
            foreach ($profile['holiday_inputs']['recurring'] as $calendar => &$rules) {
                foreach ($rules as $key => &$metadata) {
                    $this->assertSame('config', $metadata['source']);
                    $this->assertSame('database', $database['calendar_evidence']['profiles'][$name]['holiday_inputs']['recurring'][$calendar][$key]['source']);
                    $metadata['source'] = 'database';
                }
                unset($metadata);
            }
            unset($rules);
            foreach ($profile['days'] as &$day) {
                foreach ($day['holiday_reasons'] as &$reason) {
                    if (in_array($reason['type'], ['custom_holiday', 'extra_working_day'], true)) {
                        $this->assertSame('config', $reason['source']);
                        $reason['source'] = 'database';
                    }
                }
                unset($reason);
            }
            unset($day);
        }
        unset($profile);
        $config['calendar_version'] = $database['calendar_version'];
        $config['integrity_hash'] = $database['integrity_hash'];
        $this->assertSame($database, $config);
        $this->assertSame($database, DeliveryPromiseSnapshot::fromArray($database)->toArray());
    }

    public static function sources(): array
    {
        return [['config'], ['database']];
    }

    #[DataProvider('unifiedInheritedSchedules')]
    public function test_inherited_ordinary_exact_and_seasonal_schedules_keep_exact_source_parity_and_pinned_views(string $source, string $schedule, string $timezone): void
    {
        $definitions = $this->fixture['definitions'];
        $hours = [1 => [['09:00', '10:00'], ['11:00', '12:00']]];
        $definitions['profiles']['parent']['working_hours'] = $hours;
        $definitions['profiles']['child'] = ['extends' => 'parent'];
        $definitions['profiles']['child'] += match ($schedule) {
            'exact' => ['special_working_hours' => ['2026-11-02' => $hours[1]]],
            'seasonal' => ['seasonal_working_hours' => [['from' => '2026-11-01', 'through' => '2026-11-30', 'weekdays' => $hours]]],
            'permission' => ['allow_overnight_working_hours' => true],
            default => [],
        };
        $this->replace($definitions);
        config()->set('workdays.driver', $source);
        $old = Workday::profile('child');
        $start = CarbonImmutable::parse('2026-11-02 09:59:30.123456', $timezone);
        $this->assertSame('2026-11-02 11:00:30.123456', $old->addBusinessMinutes($start, 1)->format('Y-m-d H:i:s.u'));
        $this->assertSame(1, $old->diffBusinessMinutes($start, $old->addBusinessMinutes($start, 1)->setTimezone('Pacific/Kiritimati')));
        $definitions['profiles']['child']['special_working_hours'] = ['2026-11-02' => [['15:00', '17:00']]];
        $this->replace($definitions);
        $this->assertSame('11:00:30.123456', $old->addBusinessMinutes($start, 1)->format('H:i:s.u'));
        $this->assertSame('15:01:00.000000', Workday::profile('child')->addBusinessMinutes($start, 1)->format('H:i:s.u'));
    }

    public static function unifiedInheritedSchedules(): iterable
    {
        foreach (['config', 'database'] as $source) {
            foreach (['ordinary', 'exact', 'seasonal', 'permission'] as $schedule) {
                foreach (['UTC', 'Asia/Tehran'] as $zone) {
                    yield $source.' '.$schedule.' '.$zone => [$source, $schedule, $zone];
                }
            }
        }
    }

    private function replace(array $definitions): void
    {
        config()->set('workdays.definitions', $definitions);
        $repository = $this->app->make(DatabaseDefinitionRepository::class);
        $repository->publish($definitions, $repository->current()->revisionId);
    }

    private function standalone(WorkdayManager $manager, array $item): mixed
    {
        return match ($item['operation']) {
            'is_business_day' => $manager->profile($item['profile'])->isBusinessDay($item['date']),
            'working_windows' => $manager->profile($item['profile'])->workingWindowsFor($item['date']),
            'add_business_days' => $manager->profile($item['profile'])->addBusinessDays($item['date'], $item['days']),
            'add_business_minutes' => $manager->profile($item['profile'])->addBusinessMinutes($item['datetime'], $item['minutes']),
            'resolve_cutoff' => $manager->profile($item['profile'])->resolveCutoff($item['received_at']),
            'plan_delivery' => $manager->planDelivery($item['received_at'], $item['plan']),
            'delivery_slots' => $manager->deliverySlots($item['ready_at'], $item['options']),
            'recurring_schedule' => $manager->recurringSchedule($item['anchor_date'], $item['options']),
            'evaluate_sla' => $manager->evaluateSla($item['started_at'], $item['evaluated_at'], $item['options']),
            'reverse_plan_delivery' => $manager->reversePlanDelivery($item['desired_completion_at'], $item['plan'], $item['options']),
        };
    }

    private function typed(mixed $value): array
    {
        return ['type' => get_debug_type($value), 'value' => $this->plain($value)];
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d\TH:i:s.uP') : (is_array($value) ? array_map($this->plain(...), $value) : (is_object($value) ? $value->toArray() : $value));
    }
}
