<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zarbinco\LaravelWorkdays\WorkdaysServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            WorkdaysServiceProvider::class,
        ];
    }
}
