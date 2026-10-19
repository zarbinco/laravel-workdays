<?php

declare(strict_types=1);

/**
 * Opt in: php tests/Benchmarks/BulkCalculationsBenchmark.php --run
 *
 * Each sample runs in a fresh PHP process against its own in-memory SQLite database.
 * No application database, orders, network service, or persistent dataset is accessed.
 */

use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Zarbinco\LaravelWorkdays\Bulk\BulkCalculator;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
use Zarbinco\LaravelWorkdays\Models\WorkdayHolidayRule;
use Zarbinco\LaravelWorkdays\Models\WorkdaySpecialDate;
use Zarbinco\LaravelWorkdays\WorkdayManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class BulkCalculationsBenchmark
{
    private const CHUNK_SIZE = 1000;

    private const SAMPLES = 3;

    private const PROFILES = ['bench_warehouse_a', 'bench_warehouse_b', 'bench_warehouse_child', 'bench_carrier_a', 'bench_carrier_b'];

    public static function main(array $arguments): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('This opt-in benchmark runs only from the CLI.');
        }

        if (($arguments[1] ?? null) === '--worker') {
            $mode = $arguments[2] ?? '';
            $size = $arguments[3] ?? '';

            if (! in_array($mode, ['standalone', 'bulk'], true) || ! in_array($size, ['100', '1000', '10000'], true) || count($arguments) !== 4) {
                throw new InvalidArgumentException('Invalid internal benchmark worker arguments.');
            }

            echo json_encode(self::sample($mode, (int) $size), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

            return;
        }

        if ($arguments !== [$arguments[0], '--run']) {
            echo 'Opt in with: php tests/Benchmarks/BulkCalculationsBenchmark.php --run', PHP_EOL;

            return;
        }

        $report = [
            'benchmark' => 'Phase 10 bulk calculations',
            'database' => 'SQLite :memory:; a separate identical fixture in every child process',
            'runtime' => self::versions(),
            'methodology' => [
                'samples_per_mode_and_size' => self::SAMPLES,
                'process' => 'Fresh PHP child for every measured sample.',
                'warmup' => 'Ten unmeasured calculations warm class loading and SQLite pages. Every measured provider/calculator context starts fresh.',
                'timing' => 'Includes operation construction, calculation, chunking and full-result parity hashing; excludes bootstrap, schema creation, seeding and warm-up.',
                'memory' => 'Peak allocated PHP bytes after a post-warm-up peak reset; both absolute peak and delta from the measurement baseline are recorded.',
                'query_counts' => 'Measured holiday SELECTs only; schema, fixture inserts and warm-up queries are excluded. Query logging is disabled.',
                'construction_counts' => 'Bulk creations are observed by the internal service callback. Standalone counts are explicitly derived from successful two-profile plan allocation paths, not instrumented.',
                'parity' => 'SHA-256 of every complete serialized DeliveryPlanResult in original operation order; no result history is retained.',
                'cache_lifetime' => 'Ordinary calls and each bulk chunk create fresh provider contexts; no cache survives between chunks or samples.',
                'limitations' => 'SQLite measurements do not predict MySQL production performance. No MySQL benchmark is performed.',
            ],
            'fixture' => [
                'profiles' => self::PROFILES,
                'inherited_profile' => 'bench_warehouse_child extends bench_warehouse_a; database rows retain the child name',
                'storage' => 'database',
                'prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30'],
                'actual_fetched_range' => 'The exact 30 requested Gregorian labels; no automatic margin.',
                'operations' => 'Repeated UTC dates, split working shifts, exact holidays/extra working days, and three-stage work/handoff/work delivery plans.',
                'chunk_size' => self::CHUNK_SIZE,
            ],
            'workloads' => [],
        ];

        foreach ([100, 1000, 10000] as $size) {
            $samples = ['standalone' => [], 'bulk' => []];

            for ($sample = 0; $sample < self::SAMPLES; $sample++) {
                // Alternate the order to avoid always favoring the second mode.
                foreach ($sample % 2 === 0 ? ['standalone', 'bulk'] : ['bulk', 'standalone'] as $mode) {
                    fwrite(STDERR, sprintf("Running %s, %d operations, sample %d/%d...\n", $mode, $size, $sample + 1, self::SAMPLES));
                    $samples[$mode][] = self::worker($mode, $size);
                }
            }

            $digests = array_unique(array_column(array_merge(...array_values($samples)), 'parity_sha256'));

            if (count($digests) !== 1) {
                throw new RuntimeException('Full-result parity failed for benchmark workload ['.$size.'].');
            }

            $workload = ['operations' => $size, 'chunks' => (int) ceil($size / self::CHUNK_SIZE), 'parity_sha256' => $digests[0]];

            foreach ($samples as $mode => $measurements) {
                $workload[$mode] = [
                    'median_elapsed_seconds' => self::median(array_column($measurements, 'elapsed_seconds')),
                    'median_holiday_select_queries' => self::median(array_column($measurements, 'holiday_select_queries')),
                    'median_recurring_rule_queries' => self::median(array_column($measurements, 'recurring_rule_queries')),
                    'median_special_date_queries' => self::median(array_column($measurements, 'special_date_queries')),
                    'median_peak_memory_bytes' => self::median(array_column($measurements, 'peak_memory_bytes')),
                    'median_peak_memory_delta_bytes' => self::median(array_column($measurements, 'peak_memory_delta_bytes')),
                    'construction_counts' => $measurements[0]['construction_counts'],
                    'construction_counts_source' => $measurements[0]['construction_counts_source'],
                    'processed' => $measurements[0]['processed'],
                    'succeeded' => $measurements[0]['succeeded'],
                    'failed' => $measurements[0]['failed'],
                    'samples' => $measurements,
                ];
            }

            $report['workloads'][] = $workload;
        }

        echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }

    private static function worker(string $mode, int $size): array
    {
        $pipes = [];
        // Array form bypasses shell parsing on Windows and Unix.
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $mode, (string) $size], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a fresh benchmark PHP process.');
        }

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            throw new RuntimeException('Benchmark child failed: '.trim($errors."\n".$output));
        }

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function sample(string $mode, int $size): array
    {
        $capsule = self::fixture();
        $manager = new WorkdayManager;
        $queries = ['rules' => 0, 'special' => 0];
        $observeQueries = false;
        $capsule->getConnection()->listen(static function (QueryExecuted $query) use (&$queries, &$observeQueries): void {
            if (! $observeQueries || preg_match('/\A\s*select\b/i', $query->sql) !== 1) {
                return;
            }

            if (str_contains($query->sql, 'workday_holiday_rules')) {
                $queries['rules']++;
            } elseif (str_contains($query->sql, 'workday_special_dates')) {
                $queries['special']++;
            }
        });

        $creations = [];
        $observer = static function (string $kind, ?string $profile = null, ?int $scanLimit = null) use (&$creations): void {
            $key = $kind === 'provider' ? $profile.'_provider' : $kind;
            $creations[$key] = ($creations[$key] ?? 0) + 1;

            if ($kind === 'provider') {
                $creations['holiday_provider_total'] = ($creations['holiday_provider_total'] ?? 0) + 1;
            }
        };

        self::execute($mode, 10, $manager, $observer);
        $creations = [];
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baselineMemory = memory_get_usage(true);
        $observeQueries = true;
        $started = hrtime(true);
        $result = self::execute($mode, $size, $manager, $observer);
        $elapsed = (hrtime(true) - $started) / 1000000000;
        $peak = memory_get_peak_usage(true);
        $observeQueries = false;
        ksort($creations);

        return [
            'mode' => $mode,
            'elapsed_seconds' => $elapsed,
            'holiday_select_queries' => $queries['rules'] + $queries['special'],
            'recurring_rule_queries' => $queries['rules'],
            'special_date_queries' => $queries['special'],
            'peak_memory_bytes' => $peak,
            'peak_memory_delta_bytes' => max(0, $peak - $baselineMemory),
            'measurement_baseline_memory_bytes' => $baselineMemory,
            'construction_counts' => $mode === 'bulk' ? $creations : [
                'calculator' => $size * 2,
                'database_provider' => $size * 2,
                'config_provider' => $size * 2,
                'holiday_provider_total' => $size * 4,
            ],
            'construction_counts_source' => $mode === 'bulk'
                ? 'Observed successful operation-local cache creation events from BulkCalculator.'
                : 'Derived: every successful UTC plan references two profiles; the unchanged standalone manager constructs one calculator and both config/database provider objects per profile.',
            ...$result,
        ];
    }

    private static function execute(string $mode, int $size, WorkdayManager $manager, Closure $observer): array
    {
        $hash = hash_init('sha256');
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        for ($offset = 0; $offset < $size; $offset += self::CHUNK_SIZE) {
            $operations = self::operations($offset, min(self::CHUNK_SIZE, $size - $offset));

            if ($mode === 'standalone') {
                foreach ($operations as $operation) {
                    $value = $manager->planDelivery($operation['received_at'], $operation['plan']);
                    self::digest($hash, $value);
                    $processed++;
                    $succeeded++;
                    unset($value);
                }
            } else {
                // The public manager delegates to this exact service. The observer only
                // counts internal allocations and never changes a calculator or result.
                $batch = (new BulkCalculator($observer))->calculate($operations, [
                    'on_error' => 'collect', 'prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30'],
                ]);

                foreach ($batch->items as $item) {
                    $processed++;

                    if (! $item->success) {
                        $failed++;
                        throw new RuntimeException('The deterministic benchmark item ['.$item->id.'] failed: '.json_encode($item->error, JSON_THROW_ON_ERROR));
                    }

                    if (! $item->result instanceof DeliveryPlanResult) {
                        throw new RuntimeException('Benchmark bulk result lost its DeliveryPlanResult type.');
                    }

                    self::digest($hash, $item->result);
                    $succeeded++;
                }

                unset($batch, $item);
            }

            unset($operations);
        }

        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed, 'parity_sha256' => hash_final($hash)];
    }

    private static function digest(HashContext $hash, DeliveryPlanResult $result): void
    {
        hash_update($hash, json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private static function operations(int $offset, int $count): array
    {
        $operations = [];
        $times = ['09:15:00.123456', '10:30:00.654321', '11:45:00.000001', '13:15:00.999999'];

        for ($index = $offset; $index < $offset + $count; $index++) {
            $warehouse = self::PROFILES[$index % 3];
            $carrier = self::PROFILES[3 + $index % 2];
            $operations[] = [
                'id' => 'order-'.$index, 'operation' => 'plan_delivery',
                'received_at' => sprintf('2026-11-%02dT%sZ', 2 + intdiv($index, 5) % 19, $times[$index % 4]),
                'plan' => [
                    'timezone' => 'UTC',
                    'profile_timezones' => [$warehouse => 'UTC', $carrier => 'UTC'],
                    'stages' => [
                        ['id' => 'prepare', 'type' => 'work', 'profile' => $warehouse, 'minutes' => 60 + $index % 3 * 30],
                        ['id' => 'handoff', 'type' => 'handoff', 'profiles' => [$warehouse, $carrier]],
                        ['id' => 'deliver', 'type' => 'work', 'profile' => $carrier, 'minutes' => 30],
                    ],
                ],
            ];
        }

        return $operations;
    }

    private static function fixture(): Capsule
    {
        date_default_timezone_set('UTC');
        $container = new Container;
        Container::setInstance($container);
        $config = new Repository(['workdays' => require dirname(__DIR__, 2).'/config/workdays.php']);
        $container->instance('config', $config);
        $capsule = new Capsule($container);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->bind('db.schema', static fn () => $capsule->schema());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }

        $hours = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '12:00'], ['13:00', '17:00']]);
        $base = [
            'weekends' => ['Saturday', 'Sunday'], 'working_hours' => $hours,
            'extra_working_day_hours' => [['09:00', '12:00'], ['13:00', '17:00']],
            'holidays' => ['gregorian' => [], 'jalali' => [], 'hijri' => []],
            'custom_holidays' => [], 'extra_working_days' => [],
        ];
        $profiles = [];

        foreach (self::PROFILES as $profile) {
            $profiles[$profile] = $base;
        }

        $profiles['bench_warehouse_child'] = ['extends' => 'bench_warehouse_a'];
        $config->set('workdays.profiles', $profiles);
        $config->set('workdays.storage.driver', 'database');
        $config->set('workdays.max_scan_days', 45);
        $config->set('workdays.include_start_date', false);

        foreach (self::PROFILES as $index => $profile) {
            foreach ([['gregorian', 11, 12, true], ['jalali', 1, 1, true], ['hijri', 1, 1, true], ['gregorian', 11, 13, false]] as [$calendar, $month, $day, $active]) {
                WorkdayHolidayRule::create(['profile' => $profile, 'calendar_type' => $calendar, 'month' => $month, 'day' => $day, 'title' => 'Benchmark recurring '.$calendar, 'is_active' => $active]);
            }

            foreach ([4, 11, 18] as $day) {
                WorkdaySpecialDate::create(['profile' => $profile, 'date' => sprintf('2026-11-%02d', $day), 'type' => 'holiday', 'title' => 'Benchmark closure '.$day, 'is_active' => true]);
            }

            WorkdaySpecialDate::create(['profile' => $profile, 'date' => '2026-11-07', 'type' => 'working_day', 'title' => 'Benchmark Saturday override', 'is_active' => $index % 2 === 0]);
            WorkdaySpecialDate::create(['profile' => $profile, 'date' => '2026-11-09', 'type' => 'holiday', 'title' => 'Inactive benchmark closure', 'is_active' => false]);
        }

        return $capsule;
    }

    private static function median(array $values): int|float
    {
        sort($values, SORT_NUMERIC);

        return $values[intdiv(count($values), 2)];
    }

    private static function versions(): array
    {
        $versions = ['php' => PHP_VERSION, 'sqlite' => (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn()];

        foreach (['laravel/framework', 'orchestra/testbench', 'nesbot/carbon', 'hekmatinasser/verta', 'phpunit/phpunit'] as $package) {
            $versions[$package] = InstalledVersions::getPrettyVersion($package);
        }

        return $versions;
    }
}

BulkCalculationsBenchmark::main($argv);
