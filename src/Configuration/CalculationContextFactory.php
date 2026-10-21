<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Configuration;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Throwable;
use Zarbinco\LaravelWorkdays\Exceptions\DefinitionSourceException;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;

/** Explicit framework boundary. It stores services, never an operation's captured values. */
final readonly class CalculationContextFactory
{
    public function __construct(private Repository $config, private DefinitionSource $source) {}

    public function open(?callable $observer = null): CalculationContext
    {
        foreach (['storage', 'profiles', 'default_profile', 'include_start_date', 'hijri', 'profile_routing'] as $old) {
            if ($this->config->has('workdays.'.$old)) {
                throw new DefinitionSourceException('Removed workdays.'.$old.' key: use workdays.driver and place operational settings under workdays.definitions; no legacy translation is supported.');
            }
        }
        $driver = $this->config->get('workdays.driver');
        if (! in_array($driver, ['config', 'database'], true)) {
            throw new DefinitionSourceException('workdays.driver must be config or database; chain, hybrid, null, empty and unknown selections are unsupported.');
        }
        try {
            $maximum = ProfileConfigValidator::validateMaxScanDays($this->config->get('workdays.max_scan_days'));
        } catch (InvalidArgumentException $error) {
            throw new DefinitionSourceException('Invalid bootstrap workdays.max_scan_days: '.$error->getMessage(), previous: $error);
        }

        try {
            $view = $this->source instanceof SelectedDefinitionSource
                ? $this->source->openForDriver($driver)
                : $this->source->open();
        } catch (DefinitionSourceException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new DefinitionSourceException('Unable to open the selected definition source: '.$error->getMessage(), previous: $error);
        }

        return new CalculationContext($view, $maximum, $observer);
    }
}
