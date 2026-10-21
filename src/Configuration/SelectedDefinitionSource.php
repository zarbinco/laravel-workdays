<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

use Illuminate\Contracts\Config\Repository;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;

/** Framework selector. Engines receive only the selected, pinned view. */
final readonly class SelectedDefinitionSource implements DefinitionSource
{
    public function __construct(private Repository $config, private ConfigDefinitionSource $configSource, private DatabaseDefinitionSource $databaseSource) {}

    /** Direct source consumers capture their driver here; operation factories pass their capture below. */
    public function open(): WorkdayDefinitions
    {
        return $this->openForDriver($this->config->get('workdays.driver'));
    }

    public function openForDriver(mixed $driver): WorkdayDefinitions
    {
        return match ($driver) {
            'config' => $this->configSource->open(),
            'database' => $this->databaseSource->open(),
            default => throw new DefinitionSourceException('workdays.driver must be config or database; chain, hybrid, null, empty and unknown selections are unsupported.'),
        };
    }
}
