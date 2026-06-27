<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays;

use Illuminate\Support\ServiceProvider;
use Zarbinco\LaravelWorkdays\Commands\InstallCommand;

final class WorkdaysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/workdays.php', 'workdays');

        $this->app->singleton(WorkdayManager::class, fn (): WorkdayManager => new WorkdayManager());
        $this->app->alias(WorkdayManager::class, 'workdays');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/workdays.php' => config_path('workdays.php'),
        ], 'workdays-config');

        $this->publishes([
            __DIR__ . '/../config/workdays-iran.php' => config_path('workdays.php'),
        ], 'workdays-config-iran');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}
