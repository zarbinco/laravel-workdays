<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Holidays;

use Carbon\CarbonImmutable;
use RuntimeException;
use Zarbinco\LaravelWorkdays\Data\Holiday;

/** Read-only operation-local provider. No configuration, database access or global caches. */
final readonly class SnapshotHolidayProvider implements HolidayProviderInterface
{
    public function __construct(private array $profiles) {}

    public function recurringHolidays(string $profile, string $calendar): array
    {
        $values = [];

        foreach ($this->definition($profile)['holiday_inputs']['recurring'][$calendar] ?? [] as $key => $rule) {
            $values[$key] = $rule['title'];
        }

        return $values;
    }

    public function customHoliday(string $profile, CarbonImmutable $date): ?Holiday
    {
        return $this->dated($profile, $date, 'custom');
    }

    public function hasCustomHoliday(string $profile, CarbonImmutable $date): bool
    {
        return $this->customHoliday($profile, $date) !== null;
    }

    public function extraWorkingDay(string $profile, CarbonImmutable $date): ?Holiday
    {
        return $this->dated($profile, $date, 'extra');
    }

    public function hasExtraWorkingDay(string $profile, CarbonImmutable $date): bool
    {
        return $this->extraWorkingDay($profile, $date) !== null;
    }

    private function dated(string $profile, CarbonImmutable $date, string $section): ?Holiday
    {
        $definition = $this->definition($profile);
        $label = $date->toDateString();

        if ($label < $definition['coverage']['provider_from'] || $label > $definition['coverage']['through']) {
            throw new RuntimeException(sprintf('Snapshot calendar for profile [%s] does not cover date [%s]; the bounded planning horizon cannot be extended.', $profile, $label));
        }

        $rule = $definition['holiday_inputs'][$section][$label] ?? null;

        return $rule === null ? null : new Holiday($date, $rule['title'], $rule['source']);
    }

    private function definition(string $profile): array
    {
        return $this->profiles[$profile] ?? throw new RuntimeException(sprintf('Snapshot calendar profile [%s] is not available.', $profile));
    }
}
