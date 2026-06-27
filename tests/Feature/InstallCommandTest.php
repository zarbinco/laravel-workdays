<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Zarbinco\LaravelWorkdays\Commands\InstallCommand;
use Zarbinco\LaravelWorkdays\Support\StorageDriver;
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

    private ?string $secondMigrationPath = null;

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

        $this->assertContains(realpath($this->migrationSourcePath('2026_01_01_000001_create_workday_holiday_rules_table.php')), $publishableMigrationPaths);
        $this->assertContains(realpath($this->migrationSourcePath('2026_01_01_000002_create_workday_special_dates_table.php')), $publishableMigrationPaths);
    }

    public function test_install_command_publishes_default_config(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install');
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Laravel Workdays default config was installed.', Artisan::output());
        $this->assertSame('iran', $config['default_profile']);
        $this->assertSame('config', $config['storage']['driver']);
        $this->assertArrayHasKey('iran', $config['profiles']);
        $this->assertSame([], $config['profiles']['iran']['holidays']['jalali']);
    }

    public function test_install_command_publishes_iran_config(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--preset' => 'iran']);
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Laravel Workdays Iran preset config was installed.', Artisan::output());
        $this->assertSame('Nowruz', $config['profiles']['iran']['holidays']['jalali']['01-01']);
        $this->assertSame('Eid al-Fitr', $config['profiles']['iran']['holidays']['hijri']['10-01']);
    }

    public function test_persian_option_publishes_iran_config(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--persian' => true]);
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertSame('Nowruz', $config['profiles']['iran']['holidays']['jalali']['01-01']);
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

    public function test_database_storage_publishes_migrations_and_sets_driver(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--storage' => 'database']);
        $output = Artisan::output();
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertSame('database', $config['storage']['driver']);
        $this->assertMigrationPublished('create_workday_holiday_rules_table');
        $this->assertMigrationPublished('create_workday_special_dates_table');
        $this->assertStringContainsString('Laravel Workdays database storage migrations were published.', $output);
        $this->assertStringContainsString('No migrations were run automatically.', $output);
    }

    public function test_chain_storage_publishes_migrations_and_sets_driver(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--storage' => 'chain']);
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertSame('chain', $config['storage']['driver']);
        $this->assertMigrationPublished('create_workday_holiday_rules_table');
        $this->assertMigrationPublished('create_workday_special_dates_table');
        $this->assertStringContainsString('Laravel Workdays chain storage migrations were published.', Artisan::output());
    }

    public function test_unsupported_storage_returns_failure_and_clear_message(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--storage' => 'redis']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(StorageDriver::unsupportedMessage('redis'), Artisan::output());
        $this->assertFileDoesNotExist($this->publishPath);
    }

    public function test_iran_preset_with_database_storage_publishes_iran_config_with_database_driver(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--preset' => 'iran', '--storage' => 'database']);
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertSame('database', $config['storage']['driver']);
        $this->assertSame('Nowruz', $config['profiles']['iran']['holidays']['jalali']['01-01']);
        $this->assertMigrationPublished('create_workday_holiday_rules_table');
        $this->assertMigrationPublished('create_workday_special_dates_table');
    }

    public function test_persian_option_with_chain_storage_publishes_iran_config_with_chain_driver(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--persian' => true, '--storage' => 'chain']);
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertSame('chain', $config['storage']['driver']);
        $this->assertSame('Nowruz', $config['profiles']['iran']['holidays']['jalali']['01-01']);
        $this->assertMigrationPublished('create_workday_holiday_rules_table');
        $this->assertMigrationPublished('create_workday_special_dates_table');
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
        $this->firstMigrationPath = $this->migrationDirectory.DIRECTORY_SEPARATOR.'2026_01_01_000001_create_workday_holiday_rules_table.php';
        $this->secondMigrationPath = $this->migrationDirectory.DIRECTORY_SEPARATOR.'2026_01_01_000002_create_workday_special_dates_table.php';

        File::delete($this->publishPath);
        File::delete($this->migrationFiles('create_workday_holiday_rules_table'));
        File::delete($this->migrationFiles('create_workday_special_dates_table'));

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
        $firstMigration = $this->migrationSourcePath('2026_01_01_000001_create_workday_holiday_rules_table.php');
        $secondMigration = $this->migrationSourcePath('2026_01_01_000002_create_workday_special_dates_table.php');

        $publishes[WorkdaysServiceProvider::class] = [
            $defaultConfig => $destination,
            $iranConfig => $destination,
            $firstMigration => $this->firstMigrationPath,
            $secondMigration => $this->secondMigrationPath,
        ];

        $publishGroups['workdays-config'] = [
            $defaultConfig => $destination,
        ];

        $publishGroups['workdays-config-iran'] = [
            $iranConfig => $destination,
        ];

        $publishGroups['workdays-migrations'] = [
            $firstMigration => $this->firstMigrationPath,
            $secondMigration => $this->secondMigrationPath,
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
