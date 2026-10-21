<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Benchmarks;

use Composer\InstalledVersions;
use Illuminate\Database\Events\QueryExecuted;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

/** Opt-in observations; deliberately outside the default suite and without timing thresholds. */
final class UnifiedWorkingTimeBenchmarkTest extends TestCase
{
    public function test_repeated_bounded_operations_report_time_memory_and_definition_selects(): void
    {
        if (getenv('WORKDAYS_RUN_UNIFIED_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set WORKDAYS_RUN_UNIFIED_BENCHMARK=1 and WORKDAYS_UNIFIED_BENCHMARK_REPORT to run.');
        }
        $path = getenv('WORKDAYS_UNIFIED_BENCHMARK_REPORT');
        $this->assertTrue(is_string($path) && $path !== '', 'An explicit external report path is required.');
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        $definitions = ['default_profile' => 'warehouse', 'include_start_date' => false,
            'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null,
            'profiles' => ['warehouse' => ['weekends' => ['Saturday', 'Sunday'], 'holidays' => [],
                'custom_holidays' => [], 'extra_working_days' => [],
                'working_hours' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '12:00'], ['13:00', '17:00']])]]];
        $this->app->make(DatabaseDefinitionRepository::class)->publish($definitions, null);
        config()->set('workdays.definitions', $definitions);
        config()->set('workdays.max_scan_days', 14);
        config()->set('workdays.driver', 'config');
        $selects = 0;
        $this->app['db']->connection()->listen(static function (QueryExecuted $query) use (&$selects): void {
            if (preg_match('/\Aselect\b/i', ltrim($query->sql)) === 1 && str_contains($query->sql, 'workday_definition_')) {
                $selects++;
            }
        });
        $plan = ['timezone' => 'UTC', 'profile_timezones' => ['warehouse' => 'UTC'],
            'stages' => [['id' => 'work', 'type' => 'work', 'profile' => 'warehouse', 'minutes' => 120]]];
        $sla = ['profile' => 'warehouse', 'timezone' => 'UTC', 'target_minutes' => 120];
        $start = '2026-11-02T11:00:00.000000Z';
        $deadline = '2026-11-02T14:00:00.000000Z';
        $this->assertSame('14:00:00.000000', Workday::profile('warehouse')->addBusinessMinutes($start, 120)->format('H:i:s.u'));
        $this->assertSame('14:00:00.000000', Workday::planDelivery($start, $plan)->estimatedCompletionAt->format('H:i:s.u'));
        $this->assertSame('11:00:00.000000', Workday::reversePlanDelivery($deadline, $plan, ['lookback_days' => 1])->latestOrderAt->format('H:i:s.u'));
        $this->assertSame('14:00:00.000000', Workday::evaluateSla($start, $deadline, $sla)->dueAt->format('H:i:s.u'));
        $operations = [
            'ordinary_core' => static fn () => Workday::profile('warehouse')->addBusinessMinutes('2026-11-02T11:59:30.123456Z', 120),
            'forward_work' => static fn () => Workday::planDelivery($start, $plan),
            'reverse_work' => static fn () => Workday::reversePlanDelivery($deadline, $plan, ['lookback_days' => 1]),
            'sla_no_pause' => static fn () => Workday::evaluateSla($start, $deadline, $sla),
        ];
        $report = ['fixture' => 'UTC Monday 2026-11-02; weekday 09-12/13-17; 120 working minutes', 'php' => PHP_VERSION,
            'laravel' => $this->app->version(), 'carbon' => InstalledVersions::getPrettyVersion('nesbot/carbon'),
            'tzdb' => timezone_version_get(), 'samples_per_cell' => 3, 'cells' => []];
        foreach ([100, 1000] as $count) {
            foreach ($operations as $name => $operation) {
                $samples = [];
                for ($sample = 0; $sample < 3; $sample++) {
                    gc_collect_cycles();
                    $selects = 0;
                    memory_reset_peak_usage();
                    $memory = memory_get_usage(true);
                    $started = hrtime(true);
                    for ($index = 0; $index < $count; $index++) {
                        $operation();
                    }
                    $samples[] = ['milliseconds' => (hrtime(true) - $started) / 1e6,
                        'allocated_growth_bytes' => memory_get_usage(true) - $memory,
                        'peak_allocated_bytes' => memory_get_peak_usage(true), 'definition_selects' => $selects];
                    $this->assertSame(0, $selects, 'Config calculations must issue zero definition SQL.');
                }
                $report['cells'][] = ['source' => 'config', 'operation' => $name, 'operations' => $count, 'samples' => $samples];
            }
            $items = [];
            for ($index = 0; $index < $count; $index++) {
                $items[] = ['id' => (string) $index] + match ($index % 5) {
                    0 => ['operation' => 'add_business_minutes', 'profile' => 'warehouse', 'datetime' => $start, 'minutes' => 120],
                    1 => ['operation' => 'plan_delivery', 'received_at' => $start, 'plan' => $plan],
                    2 => ['operation' => 'reverse_plan_delivery', 'desired_completion_at' => $deadline, 'plan' => $plan, 'options' => ['lookback_days' => 1]],
                    3 => ['operation' => 'evaluate_sla', 'started_at' => $start, 'evaluated_at' => $deadline, 'options' => $sla],
                    4 => ['operation' => 'working_windows', 'profile' => 'warehouse', 'date' => '2026-11-02'],
                };
            }
            config()->set('workdays.driver', 'database');
            $expected = Workday::bulkCalculate($items)->toArray();
            $this->assertSame($expected, Workday::bulkCalculate($items, ['prefetch' => ['from' => '2026-11-02', 'through' => '2026-11-02']])->toArray());
            $samples = [];
            for ($sample = 0; $sample < 3; $sample++) {
                gc_collect_cycles();
                $selects = 0;
                memory_reset_peak_usage();
                $memory = memory_get_usage(true);
                $started = hrtime(true);
                $result = Workday::bulkCalculate($items);
                $elapsed = (hrtime(true) - $started) / 1e6;
                $samples[] = ['milliseconds' => $elapsed, 'allocated_growth_bytes' => memory_get_usage(true) - $memory,
                    'peak_allocated_bytes' => memory_get_peak_usage(true), 'definition_selects' => $selects];
                $this->assertSame($count, $result->succeeded);
                $this->assertSame($expected, $result->toArray());
                $this->assertSame(2, $selects, 'One pinned head plus one referenced profile, irrespective of item count.');
                unset($result);
            }
            $report['cells'][] = ['source' => 'database', 'operation' => 'mixed_bulk', 'operations' => $count, 'samples' => $samples];
            config()->set('workdays.driver', 'config');
        }
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
