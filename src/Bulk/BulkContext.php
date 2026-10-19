<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Bulk;

use Closure;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Calendars\HijriCalendarAdapter;
use Zarbinco\LaravelWorkdays\Calendars\JalaliCalendarAdapter;
use Zarbinco\LaravelWorkdays\Holidays\ChainHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\ConfigHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\DatabaseHolidayProvider;
use Zarbinco\LaravelWorkdays\Holidays\HolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Holidays\RangePrefetchableHolidayProviderInterface;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigResolver;
use Zarbinco\LaravelWorkdays\Support\ProfileConfigValidator;
use Zarbinco\LaravelWorkdays\Support\StorageDriver;

/** @internal One call's detached settings, selected profiles, providers and calculator identities. */
final class BulkContext
{
    private array $profiles = [];

    private array $errors = [];

    private array $calculators = [];

    private array $settings;

    private readonly bool $validProfileRegistry;

    public readonly array $knownProfiles;

    private ?HolidayProviderInterface $provider = null;

    private ?HijriCalendarAdapter $hijri = null;

    private ?JalaliCalendarAdapter $jalali = null;

    public function __construct(array $configuration, array $referenced, private readonly ?array $range, private readonly ?Closure $observer)
    {
        $raw = Arr::get($configuration, 'profiles', []);
        $this->validProfileRegistry = is_array($raw);
        $this->knownProfiles = is_array($raw) ? array_map('strval', array_keys($raw)) : [];
        $this->settings = [
            'max_scan_days' => Arr::get($configuration, 'max_scan_days', 3660),
            'include_start_date' => Arr::get($configuration, 'include_start_date', false),
            'driver' => Arr::get($configuration, 'storage.driver', 'config'),
            'hijri_method' => Arr::get($configuration, 'hijri.method', HijriCalendarAdapter::METHOD_UMM_AL_QURA),
            'hijri_adjustment' => Arr::get($configuration, 'hijri.adjustment', 0),
        ];

        foreach ($referenced as $profile) {
            try {
                $this->validateProfileRegistry();

                if (! array_key_exists($profile, $raw)) {
                    throw new InvalidArgumentException('Workdays profile ['.$profile.'] is not configured.');
                }

                $effective = is_array($raw[$profile]) && array_key_exists('extends', $raw[$profile])
                    ? (new ProfileConfigResolver($raw))->resolve($profile)
                    : ProfileConfigValidator::validate($profile, $raw[$profile]);
                $this->profiles[$profile] = $this->detach($effective);
            } catch (InvalidArgumentException $exception) {
                $this->errors[$profile] = $exception;
            }
        }
    }

    public function limit(): int
    {
        return ProfileConfigValidator::validateMaxScanDays($this->settings['max_scan_days']);
    }

    public function validateProfileRegistry(): void
    {
        if (! $this->validProfileRegistry) {
            throw new InvalidArgumentException('The workdays profiles config value must be an array.');
        }
    }

    public function scanSettings(): array
    {
        return ['max_scan_days' => $this->settings['max_scan_days']];
    }

    public function configuration(string $profile): array
    {
        if (isset($this->errors[$profile])) {
            throw $this->errors[$profile];
        }

        return $this->profiles[$profile] ?? throw new InvalidArgumentException('Workdays profile ['.$profile.'] is not configured.');
    }

    public function calculator(string $profile, ?int $scanLimit = null): BusinessDayCalculator
    {
        $effective = $this->configuration($profile);
        $include = ProfileConfigValidator::validateIncludeStartDate($this->settings['include_start_date']);
        $scanLimit ??= $this->limit();

        if (isset($this->calculators[$profile][$scanLimit])) {
            return $this->calculators[$profile][$scanLimit];
        }

        if (! is_string($this->settings['hijri_method'])) {
            throw new InvalidArgumentException('The workdays hijri.method config value must be a string.');
        }

        if (! is_int($this->settings['hijri_adjustment'])) {
            throw new InvalidArgumentException('The workdays hijri.adjustment config value must be an integer.');
        }

        $this->hijri ??= new HijriCalendarAdapter($this->settings['hijri_method'], $this->settings['hijri_adjustment']);
        $this->jalali ??= new JalaliCalendarAdapter;
        $provider = $this->provider();
        $calculator = new BusinessDayCalculator($profile, $effective, $include, $scanLimit, $this->jalali, $this->hijri, $provider);
        ($this->observer)?->__invoke('calculator', $profile, $scanLimit);

        return $this->calculators[$profile][$scanLimit] = $calculator;
    }

    private function provider(): HolidayProviderInterface
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $driver = $this->settings['driver'];

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('The workdays storage.driver config value must be a non-empty string.');
        }

        if (! in_array($driver, [StorageDriver::CONFIG, StorageDriver::DATABASE, StorageDriver::CHAIN], true)) {
            throw new InvalidArgumentException(StorageDriver::unsupportedMessage($driver));
        }

        $config = $database = null;

        if ($driver !== StorageDriver::DATABASE) {
            $config = new ConfigHolidayProvider($this->profiles);
            ($this->observer)?->__invoke('provider', 'config', null);
        }

        if ($driver !== StorageDriver::CONFIG) {
            $database = new DatabaseHolidayProvider;
            ($this->observer)?->__invoke('provider', 'database', null);
        }

        $this->provider = match ($driver) {
            StorageDriver::CONFIG => $config,
            StorageDriver::DATABASE => $database,
            StorageDriver::CHAIN => new ChainHolidayProvider($config, $database),
        };

        if ($driver === StorageDriver::CHAIN) {
            ($this->observer)?->__invoke('provider', 'chain', null);
        }

        if ($this->range !== null && $this->provider instanceof RangePrefetchableHolidayProviderInterface && $this->profiles !== []) {
            $this->provider->prefetch(array_map('strval', array_keys($this->profiles)), $this->range['from'], $this->range['through']);
        }

        return $this->provider;
    }

    private function detach(array $values, int $depth = 0): array
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Selected bulk profile configuration exceeds the supported nesting depth.');
        }

        $copy = [];

        foreach ($values as $key => $value) {
            $copy[$key] = is_array($value) ? $this->detach($value, $depth + 1) : $value;
        }

        return $copy;
    }
}
