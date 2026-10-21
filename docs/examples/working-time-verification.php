<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Data\DeliveryPromiseSnapshot;
use Zarbinco\LaravelWorkdays\Facades\Workday;

/** In a test Laravel application, require this file and call the closure with config or database. */
return static function (string $source): array {
    if (! in_array($source, ['config', 'database'], true)) {
        throw new InvalidArgumentException('Choose config or database.');
    }
    $original = config('workdays');
    $connections = config('database.connections');
    $connectionName = 'workdays_verification_'.bin2hex(random_bytes(6));
    $temporaryDataset = null;
    $checks = [];
    $expect = static function (bool $condition, string $name) use (&$checks): void {
        if (! $condition) {
            throw new RuntimeException('Working-time check failed: '.$name);
        }
        $checks[$name] = true;
    };
    $base = ['weekends' => [], 'holidays' => [], 'custom_holidays' => [], 'extra_working_days' => []];
    $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $definitions = ['default_profile' => 'split', 'include_start_date' => false,
        'hijri' => ['method' => 'mathematical', 'adjustment' => 0], 'profile_routing' => null,
        'profiles' => [
            'split' => $base + ['working_hours' => array_fill_keys($weekdays, [['09:00', '10:00'], ['11:00', '12:00']])],
            'lunch' => $base + ['working_hours' => array_fill_keys($weekdays, [['09:00', '12:00'], ['13:00', '17:00']])],
            'day_dst' => $base + ['working_hours' => ['Sunday' => [['00:00', '04:00']], 'Monday' => [['09:00', '17:00']]]],
            'night_dst' => $base + ['allow_overnight_working_hours' => true, 'working_hours' => ['Saturday' => [['22:00', '06:00']]]],
            'night' => $base + ['allow_overnight_working_hours' => true, 'working_hours' => ['Monday' => [['22:00', '06:00']]]],
            'touching' => $base + ['working_hours' => ['Monday' => [['09:00', '10:00'], ['10:00', '11:00']]]],
            'import_parent' => array_replace($base, ['custom_holidays' => ['2026-11-02' => 'Must remain excluded']]),
            'import_clear' => ['extends' => 'import_parent', 'custom_holidays' => []],
        ]];
    $utc = static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC');
    $newYork = static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value)->setTimezone('America/New_York');
    $plan = static fn (string $profile, int $minutes): array => ['timezone' => 'UTC', 'profile_timezones' => [$profile => 'UTC'],
        'stages' => [['id' => 'work', 'type' => 'work', 'profile' => $profile, 'minutes' => $minutes]]];
    try {
        config()->set('workdays.driver', $source);
        config()->set('workdays.max_scan_days', 14);
        config()->set('workdays.definitions', $definitions);
        $repository = app(DatabaseDefinitionRepository::class);
        $revision = null;
        if ($source === 'database') {
            // The fixture never connects to the application's default or configured database.
            config()->set('database.connections.'.$connectionName, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            config()->set('workdays.database.connection', $connectionName);
            $db = app('db')->connection($connectionName);
            $expect($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:', 'private in-memory database');
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_20_000001_create_workday_definitions_tables.php';
            $migration->up();
            $revision = $repository->publish($definitions, null)->revisionId;
        }
        $split = Workday::profile('split');
        $start = $utc('2026-11-02 09:59:30.123456');
        $added = $split->addBusinessMinutes($start, 1);
        $expect($added->format('H:i:s.u') === '11:00:30.123456', 'fractional one-minute addition');
        $expect($split->diffBusinessMinutes($start, $added) === 1, 'aggregated difference');
        $expect($split->diffBusinessMinutes($added, $start) === -1, 'signed difference');
        $expect($split->previousBusinessTime($utc('2026-11-02 10:00'))->format('H:i:s.u') === '09:59:59.999999', 'previous microsecond');
        $expect($split->nextBusinessTime($utc('2026-11-02 10:00'))->format('H:i:s.u') === '11:00:00.000000', 'next opening');
        $expect($split->isBusinessTime($utc('2026-11-02 09:00')) && ! $split->isBusinessTime($utc('2026-11-02 10:00')), 'half-open membership');
        $dst = Workday::profile('day_dst');
        $expect($dst->diffBusinessMinutes($newYork('2026-03-08T00:00:00-05:00'), $newYork('2026-03-08T04:00:00-04:00')) === 180, 'spring daytime elapsed capacity');
        $expect($dst->diffBusinessMinutes($newYork('2026-11-01T00:00:00-04:00'), $newYork('2026-11-01T04:00:00-05:00')) === 300, 'fall daytime elapsed capacity');
        $expect($dst->addBusinessMinutes($newYork('2026-03-08T03:30:00-04:00'), 60)->format('Y-m-d\TH:i:s.uP') === '2026-03-09T09:30:00.000000-04:00', 'spring daytime addition');
        $night = Workday::profile('night_dst');
        $expect($night->diffBusinessMinutes($newYork('2026-03-07T22:00:00-05:00'), $newYork('2026-03-08T06:00:00-04:00')) === 420, 'spring overnight elapsed capacity');
        $expect($night->diffBusinessMinutes($newYork('2026-10-31T22:00:00-04:00'), $newYork('2026-11-01T06:00:00-05:00')) === 540, 'fall overnight elapsed capacity');
        $lunchPlan = $plan('lunch', 120);
        $reverse = Workday::reversePlanDelivery('2026-11-02T14:00:00Z', $lunchPlan, ['lookback_days' => 1]);
        $expect($reverse->latestOrderAt->format('H:i:s.u') === '11:00:00.000000', 'reverse lunch receipt');
        $expect(Workday::planDelivery($reverse->latestOrderAt, $lunchPlan)->estimatedCompletionAt->format('H:i:s.u') === '14:00:00.000000', 'actual forward validation');
        $expect(Workday::planDelivery($reverse->latestOrderAt->addMicrosecond(), $lunchPlan)->estimatedCompletionAt->format('H:i:s.u') === '14:00:00.000001', 'one microsecond later is late');
        $slaOptions = ['profile' => 'split', 'timezone' => 'UTC', 'target_minutes' => 1];
        $sla = Workday::evaluateSla($start, '2026-11-02T11:02:00Z', $slaOptions);
        $expect($sla->dueAt->equalTo($added), 'no-pause SLA agrees with core');
        $paused = Workday::evaluateSla('2026-11-02T09:59:30Z', '2026-11-02T11:02:00Z', $slaOptions + [
            'pauses' => [['started_at' => '2026-11-02T11:00:00Z', 'ended_at' => '2026-11-02T11:00:30Z']]]);
        $expect($paused->dueAt->format('H:i:s.u') === '11:01:00.000000', 'paused SLA');
        $slotOptions = ['profile' => 'touching', 'timezone' => 'UTC', 'duration_minutes' => 120, 'step_minutes' => 60, 'days' => 1, 'max_slots' => 10];
        $expect(Workday::deliverySlots('2026-11-02T09:00:00Z', $slotOptions)->slots === [], 'touching independent windows do not create a spanning slot');
        $midnight = Workday::deliverySlots('2026-11-02T22:00:00Z', array_replace($slotOptions, ['profile' => 'night']));
        $expect(count($midnight->slots) === 1 && $midnight->slots[0]->endsAt->format('Y-m-d H:i') === '2026-11-03 00:00', 'slot may end at exclusive midnight');
        $snapshot = Workday::captureDeliveryPromise($start, $plan('split', 1), ['horizon_days' => 2]);
        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
        $restored = DeliveryPromiseSnapshot::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $expect($snapshot->runtimeMetadata['calculation_schema_version'] === 2 && $restored->toArray() === $snapshot->toArray(), 'semantics 2 JSON restore');
        $comparison = Workday::compareDeliveryPromise($restored);
        $expect($comparison->status === 'resolved' && $comparison->direction === 'unchanged', 'snapshot comparison');
        $oldFixture = dirname(__DIR__, 2).'/tests/Fixtures/refactor02-semantics-1-snapshot.json';
        if (is_file($oldFixture)) {
            try {
                DeliveryPromiseSnapshot::fromArray(json_decode(file_get_contents($oldFixture), true, 512, JSON_THROW_ON_ERROR));
                throw new RuntimeException('Old semantics fixture was unexpectedly restored.');
            } catch (InvalidArgumentException $error) {
                $expect(str_contains(strtolower($error->getMessage()), 'calculation semantics'), 'unsupported calculation semantics explicitly rejected');
            }
        } else {
            throw new RuntimeException('The saved semantics-1 snapshot fixture is required for this verification.');
        }
        $bulk = Workday::bulkCalculate([['id' => 'one', 'operation' => 'add_business_minutes', 'profile' => 'split', 'datetime' => $start, 'minutes' => 1]]);
        $expect($bulk->items[0]->result->equalTo($added), 'same-source typed Bulk equality');
        $next = $definitions;
        // Move the opening, so the retained calculator and a new operation demonstrably differ.
        $next['profiles']['split']['working_hours']['Monday'] = [['09:00', '10:00'], ['11:30', '12:00']];
        if ($source === 'database') {
            $revision = $repository->publish($next, $revision)->revisionId;
        } else {
            config()->set('workdays.definitions', $next);
        }
        $expect($split->addBusinessMinutes($start, 1)->equalTo($added), 'retained calculator remains pinned');
        $expect(Workday::profile('split')->addBusinessMinutes($start, 1)->format('H:i:s.u') === '11:30:30.123456', 'new operation sees new publication or Config edit');
        if ($source === 'database') {
            $temporaryDataset = sys_get_temp_dir().DIRECTORY_SEPARATOR.'workdays-verification-iran-'.bin2hex(random_bytes(8));
            mkdir($temporaryDataset);
            $dataset = ['year' => 1405, 'country' => 'IR', 'calendar' => 'jalali',
                'source' => ['name' => 'Isolated test fixture', 'url' => 'https://example.test/1405', 'retrieved_at' => '2026-03-21'],
                'holidays' => [['date' => '1405-01-01', 'gregorian_date' => '2026-03-21', 'title' => 'Fixture holiday',
                    'calendar' => 'jalali', 'type' => 'official_holiday', 'is_official_holiday' => true]]];
            file_put_contents($temporaryDataset.DIRECTORY_SEPARATOR.'1405.php', '<?php return '.var_export($dataset, true).';');
            $before = $repository->current();
            $code = Artisan::call('workdays:import-iran-calendar', ['year' => '1405', '--profile' => 'import_clear',
                '--calendar-path' => $temporaryDataset, '--expected-revision' => (string) $revision, '--dry-run' => true, '--force' => true]);
            $expect($code === 1 && str_contains(Artisan::output(), 'unintended effective custom holidays'), 'Iran explicit-clear guard also protects forced dry-run');
            $expect($repository->current()->revisionId === $before->revisionId && $repository->current()->definitions === $before->definitions, 'rejected import leaves publication unchanged');
        }

        return ['source' => $source, 'checks' => $checks, 'observed' => [
            'split_addition' => $added->format('Y-m-d\TH:i:s.uP'), 'reverse_receipt' => $reverse->latestOrderAt->format('Y-m-d\TH:i:s.uP'),
            'sla_due' => $sla->dueAt->format('Y-m-d\TH:i:s.uP'), 'paused_sla_due' => $paused->dueAt->format('Y-m-d\TH:i:s.uP')]];
    } finally {
        if ($temporaryDataset !== null) {
            unlink($temporaryDataset.DIRECTORY_SEPARATOR.'1405.php');
            rmdir($temporaryDataset);
        }
        if ($source === 'database') {
            app('db')->purge($connectionName); // Discard only this fixture's private :memory: connection.
        }
        config(['workdays' => $original, 'database.connections' => $connections]);
    }
};
