<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Configuration\ConfigDefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\WorkdayDefinitions;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class ConfigurationSourceTest extends TestCase
{
    #[DataProvider('rejectedDrivers')]
    public function test_source_selection_never_falls_back(mixed $driver): void
    {
        config()->set('workdays.driver', $driver);
        config()->set('database.default', 'unusable');
        config()->set('database.connections.unusable', ['driver' => 'nonexistent']);
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage($driver === 'database' ? 'Database' : 'workdays.driver');

        Workday::profile('global')->isBusinessDay('2026-11-02');
    }

    public static function rejectedDrivers(): array
    {
        return [['database'], ['chain'], ['hybrid'], ['redis'], [null], [''], [false], [1]];
    }

    #[DataProvider('removedKeys')]
    public function test_old_root_operational_keys_are_rejected_even_when_null(string $key): void
    {
        config()->set('workdays.'.$key, null);
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('Removed workdays.'.$key);

        Workday::isBusinessDay('2026-11-02');
    }

    public static function removedKeys(): array
    {
        return array_map(static fn (string $key): array => [$key], ['storage', 'profiles', 'default_profile', 'include_start_date', 'hijri', 'profile_routing']);
    }

    public function test_missing_driver_and_missing_definitions_do_not_use_hidden_defaults(): void
    {
        $config = config('workdays');
        unset($config['driver']);
        config()->set('workdays', $config);
        try {
            Workday::isBusinessDay('2026-11-02');
            $this->fail('A missing published driver must fail.');
        } catch (DefinitionSourceException $exception) {
            $this->assertStringContainsString('workdays.driver', $exception->getMessage());
        }
        config()->set('workdays', ['driver' => 'config', 'max_scan_days' => 31]);
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('workdays.definitions');

        Workday::isBusinessDay('2026-11-02');
    }

    #[DataProvider('invalidIncludes')]
    public function test_new_include_start_definition_is_a_strict_native_boolean(mixed $value): void
    {
        config()->set('workdays.definitions.include_start_date', $value);
        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('native boolean');

        Workday::isBusinessDay('2026-11-02');
    }

    public static function invalidIncludes(): array
    {
        return [[null], [0], [1], ['false'], ['true'], [[]]];
    }

    public function test_config_operations_need_no_database_and_invalid_unrelated_capabilities_are_lazy(): void
    {
        config()->set('database.default', 'unusable');
        config()->set('database.connections.unusable', ['driver' => 'nonexistent']);
        config()->set('workdays.definitions.profile_routing', ['malformed' => true]);
        config()->set('workdays.definitions.profiles.unrelated', ['working_hours' => 'invalid']);
        config()->set('workdays.definitions.profiles.day_only', ['weekends' => []]);
        $this->assertTrue(Workday::profile('day_only')->isBusinessDay('2026-11-02'));
        $this->assertTrue(Workday::bulkCalculate([['id' => 'day', 'operation' => 'is_business_day', 'profile' => 'day_only', 'date' => '2026-11-02']],
            ['prefetch' => ['from' => '2026-11-01', 'through' => '2026-11-30']])->items[0]->result);
        $this->expectException(\InvalidArgumentException::class);

        Workday::profile('day_only')->workingWindowsFor('2026-11-02');
    }

    public function test_executable_or_mutable_profile_data_is_rejected_only_for_that_selected_profile(): void
    {
        $called = false;
        config()->set('workdays.definitions.profiles.invalid', ['weekends' => [], 'unused' => static function () use (&$called): void {
            $called = true;
        }]);
        $result = Workday::bulkCalculate([
            ['id' => 'bad', 'operation' => 'is_business_day', 'profile' => 'invalid', 'date' => '2026-11-02'],
            ['id' => 'good', 'operation' => 'is_business_day', 'profile' => 'global', 'date' => '2026-11-02'],
        ]);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->succeeded);
        $this->assertStringContainsString('profile [invalid]', $result->items[0]->error['message']);
        $this->assertFalse($called);
    }

    public function test_source_read_errors_keep_their_underlying_cause_and_are_not_bulk_item_failures(): void
    {
        $failure = new \RuntimeException('Selected backend read failed.');
        $source = new class($failure) implements DefinitionSource
        {
            public function __construct(private \RuntimeException $failure) {}

            public function open(): WorkdayDefinitions
            {
                throw $this->failure;
            }
        };
        $manager = new WorkdayManager(new CalculationContextFactory($this->app['config'], $source));
        try {
            $manager->bulkCalculate([['id' => 'day', 'operation' => 'is_business_day', 'profile' => 'global', 'date' => '2026-11-02']]);
            $this->fail('Source failures must escape.');
        } catch (DefinitionSourceException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function test_computation_code_has_no_implicit_configuration_reads(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        foreach (['Calculator', 'Bulk', 'Calendar', 'Calendars', 'Delivery', 'Recurring', 'Sla', 'Rules'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/\b(?:config|env|fromConfig)\s*\(|Config\s*::|\bapp\s*\(\s*[\'\"]config[\'\"]|\$app\s*\[\s*[\'\"]config[\'\"]\s*\]/', $source, $file->getPathname());
            }
        }
        $this->assertStringNotContainsString('config(', file_get_contents($root.'/WorkdayManager.php'));
    }

    public function test_captured_outputs_preserve_unchanged_features_and_reviewed_current_numerics(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/refactor01-definitions-and-operations.json'), true, 512, JSON_THROW_ON_ERROR);
        $legacy = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/refactor01-config-parity.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/refactor03-config-parity.json'), true, 512, JSON_THROW_ON_ERROR);
        // Keep the original golden bytes and prove every unaffected family remains identical.
        // Only exact work timestamps and their plan/Bulk/snapshot/comparison propagation change.
        foreach ($legacy as $key => $value) {
            if (! in_array($key, ['minutes', 'plan', 'bulk', 'snapshot', 'restore', 'comparison'], true)) {
                $this->assertSame($value, $expected[$key], $key);
            }
        }
        config()->set('workdays', ['driver' => 'config', 'max_scan_days' => 31, 'definitions' => $fixture['definitions']]);
        $manager = new WorkdayManager(new CalculationContextFactory($this->app['config'], new ConfigDefinitionSource($this->app['config'])));
        $actual = [];
        foreach ($fixture['operations'] as $item) {
            $value = match ($item['operation']) {
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
            $actual[$item['id']] = $this->typed($value);
        }
        $actual['routing'] = $this->typed($manager->resolveProfile(['kind' => 'support']));
        $actual['routed_holiday'] = $this->typed($manager->profileFor(['kind' => 'support'])->explain('2026-11-03'));
        $actual['bulk'] = $this->typed($manager->bulkCalculate($fixture['operations']));
        $actual['night'] = $this->typed($manager->profile('night')->addBusinessMinutes('2026-11-02T23:45:12.123456Z', 120));
        $actual['fold'] = $this->typed($manager->evaluateSla('2026-11-01T00:00:00-04:00', '2026-11-01T03:00:00-05:00', ['profile' => 'fold', 'timezone' => 'America/New_York', 'target_minutes' => 180]));
        $snapshot = $manager->captureDeliveryPromise('2026-11-02T10:00:12.123456Z', $fixture['plan'], ['horizon_days' => 3]);
        $actual['snapshot'] = $this->typed($snapshot);
        $actual['restore'] = $this->typed(DeliveryPromiseSnapshot::fromArray(json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)));
        $actual['comparison'] = $this->typed($manager->compareDeliveryPromise($snapshot));
        $this->assertSame(2, $actual['snapshot']['value']['runtime_metadata']['calculation_schema_version']);
        $this->assertSame('2026-11-02T14:30:12.123456+00:00', $actual['minutes']['value']);
        $this->assertSame('2026-11-02T13:00:12.123456+00:00', $actual['plan']['value']['stages'][0]['completed_at']);
        $this->assertSame('2026-11-02T14:00:12.123456+00:00', $actual['snapshot']['value']['original_result']['estimated_completion_at']);
        // Runtime metadata/integrity are environment-specific; current restoration above
        // validates consistency. The reviewed new golden keeps all other evidence fields.
        foreach (['snapshot', 'restore'] as $key) {
            unset($actual[$key]['value']['runtime_metadata'], $actual[$key]['value']['integrity_hash'], $expected[$key]['value']['runtime_metadata'], $expected[$key]['value']['integrity_hash']);
        }
        $this->assertSame($expected, $actual);
    }

    private function typed(mixed $value): array
    {
        return ['type' => get_debug_type($value), 'value' => $value instanceof CarbonImmutable ? $value->format('Y-m-d\TH:i:s.uP')
            : (is_object($value) ? $value->toArray() : (is_array($value) ? array_map($this->typed(...), $value) : $value))];
    }
}
