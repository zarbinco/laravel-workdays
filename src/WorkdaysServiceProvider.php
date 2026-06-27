<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays;

use Illuminate\Support\ServiceProvider;
use Zarbinco\LaravelWorkdays\Commands\InstallCommand;

final class WorkdaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workdays.php', 'workdays');

        $this->app->singleton(WorkdayManager::class, fn (): WorkdayManager => new WorkdayManager);
        $this->app->alias(WorkdayManager::class, 'workdays');
    }

    public function boot(): void
    {
        $migrationPath = __DIR__.'/../database/migrations';
        $migrations = [
            $migrationPath.'/2026_01_01_000001_create_workday_holiday_rules_table.php' => database_path('migrations/2026_01_01_000001_create_workday_holiday_rules_table.php'),
            $migrationPath.'/2026_01_01_000002_create_workday_special_dates_table.php' => database_path('migrations/2026_01_01_000002_create_workday_special_dates_table.php'),
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
                InstallCommand::class,
            ]);
        }
    }
}
