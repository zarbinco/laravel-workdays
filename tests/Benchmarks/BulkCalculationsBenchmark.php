<?php

declare(strict_types=1);

/**
 * Opt in: php tests/Benchmarks/BulkCalculationsBenchmark.php --run
 *
 * Each sample runs in a fresh PHP process with its own detached Config definitions.
 * No application database, orders, network service, or persistent dataset is accessed.
 */

use Composer\InstalledVersions;
use Illuminate\Config\Repository;
use Zarbinco\LaravelWorkdays\Bulk\BulkCalculator;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Configuration\ConfigDefinitionSource;
use Zarbinco\LaravelWorkdays\Data\DeliveryPlanResult;
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
            'benchmark' => 'Refactor 01 Config-source bulk calculations',
            'driver' => 'config',
            'runtime' => self::versions(),
            'methodology' => [
                'samples_per_mode_and_size' => self::SAMPLES,
                'process' => 'Fresh PHP child for every measured sample.',
                'warmup' => 'Ten unmeasured calculations warm class loading. Every measured operation or bulk chunk opens a fresh source view and context.',
                'timing' => 'Includes operation construction, source views, calculation, chunking and full-result parity hashing; excludes fixture construction and warm-up.',
                'memory' => 'Peak allocated PHP bytes after a post-warm-up peak reset; both absolute peak and delta from the measurement baseline are recorded.',
                'construction_counts' => 'Bulk creations are observed by the internal service callback. Standalone counts are explicitly derived from successful two-profile plan allocation paths, not instrumented.',
                'parity' => 'SHA-256 of every complete serialized DeliveryPlanResult in original operation order; no result history is retained.',
                'cache_lifetime' => 'Each ordinary call and bulk chunk opens one detached definition view and context; calculator/provider caches stay inside that context.',
                'limitations' => 'This measures Config only. No database backend, SQL query performance, or cross-driver parity is measured.',
            ],
            'fixture' => [
                'profiles' => self::PROFILES,
                'inherited_profile' => 'bench_warehouse_child extends bench_warehouse_a, including its logical holidays and working hours',
                'source' => 'Canonical workdays.driver=config and workdays.definitions, with no database service binding',
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
        $contexts = self::fixture();
        $manager = new WorkdayManager($contexts);

        $creations = [];
        $observer = static function (string $kind, ?string $profile = null, ?int $scanLimit = null) use (&$creations): void {
            $key = $kind === 'provider' ? $profile.'_provider' : $kind;
            $creations[$key] = ($creations[$key] ?? 0) + 1;

            if ($kind === 'provider') {
                $creations['holiday_provider_total'] = ($creations['holiday_provider_total'] ?? 0) + 1;
            }
        };

        self::execute($mode, 10, $manager, $contexts, $observer);
        $creations = [];
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baselineMemory = memory_get_usage(true);
        $started = hrtime(true);
        $result = self::execute($mode, $size, $manager, $contexts, $observer);
        $elapsed = (hrtime(true) - $started) / 1000000000;
        $peak = memory_get_peak_usage(true);
        ksort($creations);

        return [
            'mode' => $mode,
            'elapsed_seconds' => $elapsed,
            'peak_memory_bytes' => $peak,
            'peak_memory_delta_bytes' => max(0, $peak - $baselineMemory),
            'measurement_baseline_memory_bytes' => $baselineMemory,
            'construction_counts' => $mode === 'bulk' ? $creations : [
                'calculator' => $size * 2,
                'config_provider' => $size,
                'holiday_provider_total' => $size,
            ],
            'construction_counts_source' => $mode === 'bulk'
                ? 'Observed successful operation-local cache creation events from BulkCalculator.'
                : 'Derived: every successful UTC plan resolves two calculators and one shared Config holiday provider inside its single operation context.',
            ...$result,
        ];
    }

    private static function execute(string $mode, int $size, WorkdayManager $manager, CalculationContextFactory $contexts, Closure $observer): array
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
                $batch = (new BulkCalculator($contexts, $observer))->calculate($operations, ['on_error' => 'collect']);

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

    private static function fixture(): CalculationContextFactory
    {
        date_default_timezone_set('UTC');
        $config = new Repository(['workdays' => require dirname(__DIR__, 2).'/config/workdays.php']);

        $hours = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], [['09:00', '12:00'], ['13:00', '17:00']]);
        $base = [
            'weekends' => ['Saturday', 'Sunday'], 'working_hours' => $hours,
            'extra_working_day_hours' => [['09:00', '12:00'], ['13:00', '17:00']],
            'holidays' => ['gregorian' => ['11-12' => 'Annual November closure'], 'jalali' => ['01-01' => 'Nowruz'], 'hijri' => ['01-01' => 'Hijri new year']],
            'custom_holidays' => ['2026-11-04' => 'November closure 4', '2026-11-11' => 'November closure 11', '2026-11-18' => 'November closure 18'],
            'extra_working_days' => [],
        ];
        $profiles = [];

        foreach (self::PROFILES as $index => $profile) {
            $profiles[$profile] = $base;
            if ($index % 2 === 0) {
                $profiles[$profile]['extra_working_days'] = ['2026-11-07' => 'Benchmark Saturday override'];
            }
        }

        $profiles['bench_warehouse_child'] = ['extends' => 'bench_warehouse_a'];
        $config->set('workdays.driver', 'config');
        $config->set('workdays.max_scan_days', 45);
        $config->set('workdays.definitions', ['default_profile' => self::PROFILES[0], 'include_start_date' => false,
            'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null, 'profiles' => $profiles]);

        return new CalculationContextFactory($config, new ConfigDefinitionSource($config));
    }

    private static function median(array $values): int|float
    {
        sort($values, SORT_NUMERIC);

        return $values[intdiv(count($values), 2)];
    }

    private static function versions(): array
    {
        $versions = ['php' => PHP_VERSION];

        foreach (['laravel/framework', 'orchestra/testbench', 'nesbot/carbon', 'hekmatinasser/verta', 'phpunit/phpunit'] as $package) {
            $versions[$package] = InstalledVersions::getPrettyVersion($package);
        }

        return $versions;
    }
}

BulkCalculationsBenchmark::main($argv);
