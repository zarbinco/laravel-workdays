<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use Zarbinco\LaravelWorkdays\WorkdaysServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @var array<int, string>
     */
    private array $temporaryIranCalendarDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryIranCalendarDirectories as $directory) {
            if (is_dir($directory)) {
                File::deleteDirectory($directory);
            }
        }

        $this->temporaryIranCalendarDirectories = [];

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            WorkdaysServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function createTemporaryIranOfficialCalendarFixture(int $year = 1405): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-workdays-iran-calendar-'.bin2hex(random_bytes(8));

        File::ensureDirectoryExists($directory);

        $this->temporaryIranCalendarDirectories[] = $directory;

        $dataset = [
            'year' => $year,
            'country' => 'IR',
            'calendar' => 'jalali',
            'source' => [
                'name' => 'Laravel Workdays Test Fixture',
                'url' => 'https://example.test/calendars/iran/'.$year.'.php',
                'retrieved_at' => '2026-03-21',
            ],
            'holidays' => [
                [
                    'date' => '1405-01-01',
                    'gregorian_date' => '2026-03-21',
                    'title' => 'عید سعید فطر و آغاز نوروز',
                    'calendar' => 'jalali',
                    'type' => 'official_holiday',
                    'is_official_holiday' => true,
                ],
            ],
        ];

        File::put(
            $directory.DIRECTORY_SEPARATOR.$year.'.php',
            "<?php\n\nreturn ".var_export($dataset, true).";\n",
        );

        return $directory;
    }
}
