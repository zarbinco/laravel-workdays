<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Zarbinco\LaravelWorkdays\Support\StorageDriver;
use Zarbinco\LaravelWorkdays\WorkdaysServiceProvider;

final class InstallCommand extends Command
{
    protected $signature = 'workdays:install
        {--preset= : Preset to publish, for example iran}
        {--persian : Alias for --preset=iran}
        {--force : Overwrite existing config}
        {--storage=config : Storage driver: config, database, or chain}';

    protected $description = 'Install the Laravel Workdays configuration file.';

    public function handle(): int
    {
        $storage = (string) $this->option('storage');

        if (! StorageDriver::isSupported($storage)) {
            $this->error(StorageDriver::unsupportedMessage($storage));

            return SymfonyCommand::FAILURE;
        }

        $preset = $this->resolvePreset();
        $tag = 'workdays-config';

        if ($preset === 'iran') {
            $tag = 'workdays-config-iran';
        } elseif ($preset !== null) {
            $this->error(sprintf('Unsupported workdays preset [%s]. Supported presets: iran.', $preset));

            return SymfonyCommand::FAILURE;
        }

        $arguments = [
            '--provider' => WorkdaysServiceProvider::class,
            '--tag' => $tag,
        ];

        if ((bool) $this->option('force')) {
            $arguments['--force'] = true;
        }

        $result = $this->call('vendor:publish', $arguments);

        if ($result !== SymfonyCommand::SUCCESS) {
            return $result;
        }

        if ($storage !== StorageDriver::CONFIG && ! $this->patchStorageDriver($storage)) {
            return SymfonyCommand::FAILURE;
        }

        if ($storage !== StorageDriver::CONFIG) {
            $migrationArguments = [
                '--provider' => WorkdaysServiceProvider::class,
                '--tag' => 'workdays-migrations',
            ];

            if ((bool) $this->option('force')) {
                $migrationArguments['--force'] = true;
            }

            $migrationResult = $this->call('vendor:publish', $migrationArguments);

            if ($migrationResult !== SymfonyCommand::SUCCESS) {
                return $migrationResult;
            }
        }

        if ($preset === 'iran') {
            $this->info('Laravel Workdays Iran preset config was installed.');
            $this->line('Review config/workdays.php and add exact custom_holidays for official bridge/company overrides when needed.');
        } else {
            $this->info('Laravel Workdays default config was installed.');
        }

        if ($storage === StorageDriver::DATABASE) {
            $this->info('Laravel Workdays database storage migrations were published.');
            $this->line('No migrations were run automatically.');
        } elseif ($storage === StorageDriver::CHAIN) {
            $this->info('Laravel Workdays chain storage migrations were published.');
            $this->line('No migrations were run automatically.');
        }

        return SymfonyCommand::SUCCESS;
    }

    private function resolvePreset(): ?string
    {
        if ((bool) $this->option('persian')) {
            return 'iran';
        }

        $preset = $this->option('preset');

        return is_string($preset) && $preset !== '' ? $preset : null;
    }

    private function patchStorageDriver(string $storage): bool
    {
        $configPath = config_path('workdays.php');

        if (! File::exists($configPath)) {
            $this->error(sprintf('Unable to update storage driver because [%s] does not exist.', $configPath));

            return false;
        }

        $contents = File::get($configPath);
        $pattern = "/('storage'\\s*=>\\s*\\[\\s*\\R\\s*'driver'\\s*=>\\s*)'config'(\\s*,)/m";
        $patched = preg_replace_callback(
            $pattern,
            static fn (array $matches): string => $matches[1]."'".$storage."'".$matches[2],
            $contents,
            1,
            $count,
        );

        if ($patched === null || $count !== 1) {
            $this->error('Unable to update storage driver in config/workdays.php.');

            return false;
        }

        File::put($configPath, $patched);

        $this->line(sprintf('Storage driver set to [%s].', $storage));

        return true;
    }
}
