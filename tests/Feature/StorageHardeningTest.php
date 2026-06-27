<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Facades\Workday;
use Zarbinco\LaravelWorkdays\Support\StorageDriver;
use Zarbinco\LaravelWorkdays\Tests\TestCase;

final class StorageHardeningTest extends TestCase
{
    public function test_workday_manager_rejects_unsupported_storage_driver_with_standard_message(): void
    {
        config()->set('workdays.storage.driver', 'redis');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(StorageDriver::unsupportedMessage('redis'));

        Workday::profile('global');
    }

    public function test_database_storage_without_migrations_fails_with_clear_message(): void
    {
        Schema::dropIfExists('workday_special_dates');
        Schema::dropIfExists('workday_holiday_rules');
        config()->set('workdays.storage.driver', StorageDriver::DATABASE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workdays database storage is enabled, but the workday tables are not available. Publish and run the workdays migrations.');

        Workday::profile('global');
    }
}
