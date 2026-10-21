<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class StorageHardeningTest extends TestCase
{
    public function test_workday_manager_rejects_an_unknown_definition_driver(): void
    {
        config()->set('workdays.driver', 'redis');

        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('workdays.driver must be config or database');

        Workday::profile('global');
    }

    public function test_invalid_database_connection_is_visible_and_never_falls_back(): void
    {
        config()->set('workdays.driver', 'database');
        config()->set('database.default', 'unusable');
        config()->set('database.connections.unusable', ['driver' => 'must_not_connect']);

        $this->expectException(DefinitionSourceException::class);
        $this->expectExceptionMessage('Database');

        Workday::profile('global');
    }
}
