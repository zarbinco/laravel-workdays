<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Zarbinco\LaravelWorkdays\WorkdaysServiceProvider;

final class InstallCommand extends Command
{
    protected $signature = 'workdays:install
        {--preset= : Preset to publish, for example iran}
        {--persian : Alias for --preset=iran}
        {--force : Overwrite existing config}
        {--storage=config : Storage driver; only config is supported in this phase}';

    protected $description = 'Install the Laravel Workdays configuration file.';

    public function handle(): int
    {
        $storage = (string) $this->option('storage');

        if ($storage !== 'config') {
            if ($storage === 'database') {
                $this->error('Database storage is not implemented yet. Database mode is planned for Phase 5.');

                return SymfonyCommand::FAILURE;
            }

            $this->error(sprintf('Unsupported storage driver [%s]. Only [config] is supported in this phase.', $storage));

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

        if ($preset === 'iran') {
            $this->info('Laravel Workdays Iran preset config was installed.');
            $this->line('Review config/workdays.php and add exact custom_holidays for official bridge/company overrides when needed.');

            return SymfonyCommand::SUCCESS;
        }

        $this->info('Laravel Workdays default config was installed.');

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
}
