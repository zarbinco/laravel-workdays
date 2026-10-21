<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Zarbinco\LaravelWorkdays\Commands\InstallCommand;
use Zarbinco\LaravelWorkdays\Tests\TestCase;
use Zarbinco\LaravelWorkdays\WorkdaysServiceProvider;

final class InstallCommandTest extends TestCase
{
    /**
     * @var array<class-string, array<string, string>>|null
     */
    private ?array $originalPublishes = null;

    /**
     * @var array<string, array<string, string>>|null
     */
    private ?array $originalPublishGroups = null;

    private ?string $temporaryDirectory = null;

    private ?string $publishPath = null;

    private ?string $migrationDirectory = null;

    private ?string $firstMigrationPath = null;

    protected function tearDown(): void
    {
        $this->restorePublishMaps();

        if ($this->temporaryDirectory !== null && is_dir($this->temporaryDirectory)) {
            File::deleteDirectory($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_service_provider_registers_install_command(): void
    {
        $this->assertArrayHasKey('workdays:install', Artisan::all());
        $this->assertInstanceOf(InstallCommand::class, Artisan::all()['workdays:install']);
    }

    public function test_service_provider_marks_migrations_for_publish_time_timestamp_updates_when_supported(): void
    {
        if (! method_exists(ServiceProvider::class, 'publishableMigrationPaths')) {
            $this->markTestSkipped('The installed Laravel version does not expose publishable migration paths.');
        }

        if (! config('database.migrations.update_date_on_publish', false)) {
            $this->markTestSkipped('The installed Laravel version does not update migration dates on publish.');
        }

        $publishableMigrationPaths = array_map(
            static fn (string $path): string|false => realpath($path),
            ServiceProvider::publishableMigrationPaths(),
        );

        $this->assertContains(realpath($this->migrationSourcePath('2026_10_20_000001_create_workday_definitions_tables.php')), $publishableMigrationPaths);
    }

    public function test_install_command_publishes_default_config(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install');
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Laravel Workdays default config was installed.', Artisan::output());
        $this->assertSame('iran', $config['definitions']['default_profile']);
        $this->assertSame('config', $config['driver']);
        $this->assertArrayNotHasKey('storage', $config);
        $this->assertArrayNotHasKey('profiles', $config);
        $this->assertArrayHasKey('iran', $config['definitions']['profiles']);
        $this->assertSame([], $config['definitions']['profiles']['iran']['holidays']['jalali']);
        $this->assertSame(['enabled' => false, 'year' => null, 'profile' => null, 'calendar_path' => null], $config['iran_official']);
    }

    public function test_install_command_publishes_iran_config(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--preset' => 'iran']);
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Laravel Workdays Iran preset config was installed.', Artisan::output());
        $this->assertSame('Nowruz', $config['definitions']['profiles']['iran']['holidays']['jalali']['01-01']);
        $this->assertSame('Eid al-Fitr', $config['definitions']['profiles']['iran']['holidays']['hijri']['10-01']);
        $this->assertSame(['enabled' => false, 'year' => null, 'profile' => null, 'calendar_path' => null], $config['iran_official']);
    }

    public function test_install_command_does_not_import_1405_calendar_by_default(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install');
        $output = Artisan::output();
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('import-iran-calendar', $output);
        $this->assertStringNotContainsString('1405 import', $output);
        $this->assertSame(['enabled' => false, 'year' => null, 'profile' => null, 'calendar_path' => null], $config['iran_official']);
        $this->assertSame([], $this->migrationFiles('create_workday_special_dates_table'));
    }

    public function test_persian_option_publishes_iran_config(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--persian' => true]);
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertSame('Nowruz', $config['definitions']['profiles']['iran']['holidays']['jalali']['01-01']);
    }

    public function test_persian_option_and_iran_preset_publish_same_config_content(): void
    {
        $presetConfig = $this->runInstallAndReturnPublishedConfig(['--preset' => 'iran']);
        $persianConfig = $this->runInstallAndReturnPublishedConfig(['--persian' => true]);

        $this->assertSame($presetConfig, $persianConfig);
    }

    public function test_unsupported_preset_returns_failure_and_clear_message(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--preset' => 'france']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported workdays preset [france]. Supported presets: iran.', Artisan::output());
        $this->assertFileDoesNotExist($this->publishPath);
    }

    #[DataProvider('databasePresetOptions')]
    public function test_database_driver_publishes_config_and_new_schema_without_database_work(array $presetOptions): void
    {
        $this->fakePublishDestination();
        $this->makeDatabaseUnusable();
        $queries = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $exitCode = Artisan::call('workdays:install', ['--driver' => 'database', ...$presetOptions]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No migrations, definition writes, or .env changes were performed', $output);
        $this->assertFileExists($this->publishPath);
        $this->assertMigrationPublished('create_workday_definitions_tables');
        $this->assertSame([], $this->migrationFiles('create_workday_holiday_rules_table'));
        $this->assertSame([], $this->migrationFiles('create_workday_special_dates_table'));
        $this->assertSame([], $queries);
    }

    public static function databasePresetOptions(): array
    {
        return [[[]], [['--preset' => 'iran']], [['--persian' => true]]];
    }

    #[DataProvider('unsupportedDrivers')]
    public function test_removed_or_unknown_driver_is_rejected_without_publishing(string $driver): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--driver' => $driver]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unsupported workdays driver ['.$driver.']', Artisan::output());
        $this->assertFileDoesNotExist($this->publishPath);
    }

    public static function unsupportedDrivers(): array
    {
        return [['chain'], ['hybrid'], ['redis'], [''], ['Config']];
    }

    public function test_install_command_exposes_driver_without_a_storage_alias(): void
    {
        $definition = Artisan::all()['workdays:install']->getDefinition();

        $this->assertTrue($definition->hasOption('driver'));
        $this->assertSame('config', $definition->getOption('driver')->getDefault());
        $this->assertStringContainsString('config or database', $definition->getOption('driver')->getDescription());
        $this->assertFalse($definition->hasOption('storage'));
    }

    public function test_database_install_requires_force_to_overwrite_an_existing_config(): void
    {
        $this->fakePublishDestination();
        File::put($this->publishPath, "<?php return ['application_owned' => true];\n");
        $before = File::get($this->publishPath);

        $exitCode = Artisan::call('workdays:install', ['--driver' => 'database']);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, File::get($this->publishPath));

        $this->assertSame(0, Artisan::call('workdays:install', ['--driver' => 'database', '--force' => true]));
        $this->assertNotSame($before, File::get($this->publishPath));
    }

    public function test_config_driver_install_works_without_a_usable_database(): void
    {
        $this->fakePublishDestination();
        $this->makeDatabaseUnusable();
        $queries = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $exitCode = Artisan::call('workdays:install', ['--driver' => 'config']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->publishPath);
        $this->assertSame([], $queries);
        $this->assertSame([], $this->migrationFiles('create_workday_special_dates_table'));
    }

    #[DataProvider('cacheableDriverSelections')]
    public function test_real_configuration_cache_and_package_boot_require_no_database(string $driver): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-workdays-cache-'.bin2hex(random_bytes(8));
        $directory = $this->temporaryDirectory;
        foreach (['config', 'bootstrap/cache', 'storage/framework', 'storage/logs'] as $relative) {
            File::ensureDirectoryExists($directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
        }
        $configuration = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workdays.php';
        $configuration['driver'] = $driver;
        File::put($directory.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workdays.php', '<?php return '.var_export($configuration, true).';');
        File::put($directory.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php', '<?php return '.var_export([
            'name' => 'Workdays cache fixture', 'env' => 'testing', 'debug' => true, 'url' => 'http://localhost', 'timezone' => 'UTC',
            'key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'cipher' => 'AES-256-CBC',
            'providers' => [...ServiceProvider::defaultProviders()->toArray(), WorkdaysServiceProvider::class],
        ], true).';');
        File::put($directory.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'database.php', '<?php return '.var_export([
            'default' => 'unusable', 'connections' => ['unusable' => ['driver' => 'workdays_database_must_not_be_used', 'database' => ':memory:']],
        ], true).';');
        File::put($directory.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'cache.php', '<?php return '.var_export([
            'default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
        ], true).';');
        File::put($directory.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php', <<<'PHP'
<?php
$app = new Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton(Illuminate\Contracts\Console\Kernel::class, Illuminate\Foundation\Console\Kernel::class);
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Illuminate\Foundation\Exceptions\Handler::class);
return $app;
PHP);
        $script = $directory.DIRECTORY_SEPARATOR.'console.php';
        File::put($script, <<<'PHP'
<?php
require $argv[1];
try {
$app = require __DIR__.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$input = new Symfony\Component\Console\Input\ArrayInput(['command' => 'config:cache']);
$output = new Symfony\Component\Console\Output\BufferedOutput;
$status = $kernel->handle($input, $output);
$cache = $app->getCachedConfigPath();
$cached = is_file($cache) ? require $cache : [];
$discoveryOutput = new Symfony\Component\Console\Output\BufferedOutput;
$discoveryStatus = $kernel->call('list', ['namespace' => 'workdays', '--raw' => true], $discoveryOutput);
echo json_encode(['status' => $status, 'output' => $output->fetch(), 'workdays' => $cached['workdays'] ?? null,
    'commands' => $discoveryOutput->fetch(), 'discovery_status' => $discoveryStatus, 'database' => $cached['database'] ?? null], JSON_THROW_ON_ERROR);
$kernel->terminate($input, $status);
exit($status);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL.$error->getTraceAsString());
    exit(1);
}
PHP);
        $process = new Process([PHP_BINARY, $script, dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php'], $directory, [
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
            'APP_SERVICES_CACHE' => 'bootstrap/cache/services.php',
            'APP_PACKAGES_CACHE' => 'bootstrap/cache/packages.php',
        ]);
        $process->mustRun();
        $this->assertJson($process->getOutput(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame($configuration, $result['workdays']);
        $this->assertSame('workdays_database_must_not_be_used', $result['database']['connections']['unusable']['driver']);
        $this->assertSame(0, $result['discovery_status']);
        $this->assertStringContainsString('workdays:install', $result['commands']);
        $this->assertStringContainsString('workdays:import-iran-calendar', $result['commands']);
        $this->assertStringContainsString('workdays:definitions:import', $result['commands']);
        $this->assertStringContainsString('workdays:definitions:export', $result['commands']);
    }

    public static function cacheableDriverSelections(): array
    {
        return [['config'], ['database']];
    }

    private function makeDatabaseUnusable(): void
    {
        config()->set('database.default', 'unusable');
        config()->set('database.connections.unusable', ['driver' => 'workdays_database_must_not_be_used']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function runInstallAndReturnPublishedConfig(array $arguments): array
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', $arguments);

        $this->assertSame(0, $exitCode);

        return require $this->publishPath;
    }

    private function fakePublishDestination(): void
    {
        $this->temporaryDirectory ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-workdays-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->temporaryDirectory);

        if (method_exists($this->app, 'useConfigPath')) {
            $this->app->useConfigPath($this->temporaryDirectory);
        }

        $this->publishPath = config_path('workdays.php');
        $this->migrationDirectory = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'migrations';
        $this->firstMigrationPath = $this->migrationDirectory.DIRECTORY_SEPARATOR.'2026_10_20_000001_create_workday_definitions_tables.php';

        File::delete($this->publishPath);
        File::delete($this->migrationFiles('create_workday_holiday_rules_table'));
        File::delete($this->migrationFiles('create_workday_special_dates_table'));
        File::delete($this->migrationFiles('create_workday_definitions_tables'));

        $this->replacePublishMaps($this->publishPath);
    }

    private function replacePublishMaps(string $destination): void
    {
        $publishes = $this->staticServiceProviderProperty('publishes');
        $publishGroups = $this->staticServiceProviderProperty('publishGroups');

        $this->originalPublishes ??= $publishes;
        $this->originalPublishGroups ??= $publishGroups;

        $defaultConfig = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workdays.php';
        $iranConfig = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'workdays-iran.php';
        $firstMigration = $this->migrationSourcePath('2026_10_20_000001_create_workday_definitions_tables.php');

        $publishes[WorkdaysServiceProvider::class] = [
            $defaultConfig => $destination,
            $iranConfig => $destination,
            $firstMigration => $this->firstMigrationPath,
        ];

        $publishGroups['workdays-config'] = [
            $defaultConfig => $destination,
        ];

        $publishGroups['workdays-config-iran'] = [
            $iranConfig => $destination,
        ];

        $publishGroups['workdays-migrations'] = [
            $firstMigration => $this->firstMigrationPath,
        ];

        $this->setStaticServiceProviderProperty('publishes', $publishes);
        $this->setStaticServiceProviderProperty('publishGroups', $publishGroups);
    }

    private function restorePublishMaps(): void
    {
        if ($this->originalPublishes !== null) {
            $this->setStaticServiceProviderProperty('publishes', $this->originalPublishes);
        }

        if ($this->originalPublishGroups !== null) {
            $this->setStaticServiceProviderProperty('publishGroups', $this->originalPublishGroups);
        }
    }

    /**
     * @return array<mixed>
     */
    private function staticServiceProviderProperty(string $name): array
    {
        $property = (new ReflectionClass(ServiceProvider::class))->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue();
    }

    /**
     * @param  array<mixed>  $value
     */
    private function setStaticServiceProviderProperty(string $name, array $value): void
    {
        $property = (new ReflectionClass(ServiceProvider::class))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    private function assertMigrationPublished(string $migration): void
    {
        $this->assertNotSame([], $this->migrationFiles($migration), sprintf('Migration [%s] was not published.', $migration));
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(string $migration): array
    {
        if ($this->migrationDirectory === null) {
            return [];
        }

        return File::glob($this->migrationDirectory.DIRECTORY_SEPARATOR.'*_'.$migration.'.php') ?: [];
    }

    private function migrationSourcePath(string $filename): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.$filename;
    }
}
