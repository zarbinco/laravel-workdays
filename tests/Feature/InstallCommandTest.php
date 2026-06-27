<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Zarbinco\LaravelWorkdays\Commands\InstallCommand;
use Zarbinco\LaravelWorkdays\WorkdaysServiceProvider;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

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

    public function test_install_command_publishes_default_config(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install');
        $config = require $this->publishPath;

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Laravel Workdays default config was installed.', Artisan::output());
        $this->assertSame('iran', $config['default_profile']);
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

    public function test_database_storage_returns_failure_and_clear_message(): void
    {
        $this->fakePublishDestination();

        $exitCode = Artisan::call('workdays:install', ['--storage' => 'database']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Database storage is not implemented yet. Database mode is planned for Phase 5.', Artisan::output());
        $this->assertFileDoesNotExist($this->publishPath);
    }

    /**
     * @param array<string, mixed> $arguments
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
        $this->temporaryDirectory ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-workdays-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->temporaryDirectory);

        $this->publishPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'workdays-' . bin2hex(random_bytes(4)) . '.php';

        $this->replacePublishMaps($this->publishPath);
    }

    private function replacePublishMaps(string $destination): void
    {
        $publishes = $this->staticServiceProviderProperty('publishes');
        $publishGroups = $this->staticServiceProviderProperty('publishGroups');

        $this->originalPublishes ??= $publishes;
        $this->originalPublishGroups ??= $publishGroups;

        $defaultConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'workdays.php';
        $iranConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'workdays-iran.php';

        $publishes[WorkdaysServiceProvider::class] = [
            $defaultConfig => $destination,
            $iranConfig => $destination,
        ];

        $publishGroups['workdays-config'] = [
            $defaultConfig => $destination,
        ];

        $publishGroups['workdays-config-iran'] = [
            $iranConfig => $destination,
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
     * @param array<mixed> $value
     */
    private function setStaticServiceProviderProperty(string $name, array $value): void
    {
        $property = (new ReflectionClass(ServiceProvider::class))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
