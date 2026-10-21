<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays;

use Illuminate\Support\ServiceProvider;
use Zarbinco\LaravelWorkdays\Commands\ExportDefinitionsCommand;
use Zarbinco\LaravelWorkdays\Commands\ImportDefinitionsCommand;
use Zarbinco\LaravelWorkdays\Commands\ImportIranCalendarCommand;
use Zarbinco\LaravelWorkdays\Commands\InstallCommand;
use Zarbinco\LaravelWorkdays\Configuration\CalculationContextFactory;
use Zarbinco\LaravelWorkdays\Configuration\ConfigDefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionRepository;
use Zarbinco\LaravelWorkdays\Configuration\DatabaseDefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\DefinitionSource;
use Zarbinco\LaravelWorkdays\Configuration\SelectedDefinitionSource;
use Zarbinco\LaravelWorkdays\Support\CarbonMacroRegistrar;

final class WorkdaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workdays.php', 'workdays');

        $this->app->singleton(DatabaseDefinitionRepository::class, fn (): DatabaseDefinitionRepository => new DatabaseDefinitionRepository($this->app['config'], $this->app['db']));
        $this->app->singleton(DefinitionSource::class, fn (): DefinitionSource => new SelectedDefinitionSource($this->app['config'], new ConfigDefinitionSource($this->app['config']), new DatabaseDefinitionSource($this->app->make(DatabaseDefinitionRepository::class))));
        $this->app->singleton(CalculationContextFactory::class, fn (): CalculationContextFactory => new CalculationContextFactory($this->app['config'], $this->app->make(DefinitionSource::class)));
        $this->app->singleton(WorkdayManager::class, fn (): WorkdayManager => new WorkdayManager($this->app->make(CalculationContextFactory::class)));
        $this->app->alias(WorkdayManager::class, 'workdays');
    }

    public function boot(): void
    {
        CarbonMacroRegistrar::register();

        $migrationPath = __DIR__.'/../database/migrations';
        $migrations = [
            $migrationPath.'/2026_10_20_000001_create_workday_definitions_tables.php' => database_path('migrations/2026_10_20_000001_create_workday_definitions_tables.php'),
        ];

        $this->publishes([
            __DIR__.'/../config/workdays.php' => config_path('workdays.php'),
        ], 'workdays-config');

        $this->publishes([
            __DIR__.'/../config/workdays-iran.php' => config_path('workdays.php'),
        ], 'workdays-config-iran');

        if (method_exists($this, 'publishesMigrations')) {
            $this->publishesMigrations($migrations, 'workdays-migrations');
        } else {
            $this->publishes($migrations, 'workdays-migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportIranCalendarCommand::class,
                InstallCommand::class,
                ExportDefinitionsCommand::class,
                ImportDefinitionsCommand::class,
            ]);
        }
    }
}
