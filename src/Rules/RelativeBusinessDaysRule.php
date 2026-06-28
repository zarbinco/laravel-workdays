<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Rules;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Rules\Concerns\ParsesRuleDates;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class RelativeBusinessDaysRule implements ValidationRule
{
    use ParsesRuleDates;

    private ?string $customMessage = null;

    public function __construct(
        private readonly string $direction,
        private readonly int $days,
        private readonly string|DateTimeInterface|null $from,
        private readonly ?string $profile,
    ) {
        if ($days < 0) {
            throw new InvalidArgumentException('Business days must be zero or greater.');
        }

        if (! in_array($direction, ['after', 'before'], true)) {
            throw new InvalidArgumentException('Relative business day direction must be after or before.');
        }
    }

    public function message(string $message): self
    {
        $this->customMessage = $message;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $date = $this->parseDateValue($value);

        if ($date === null) {
            $fail($this->messageText('The :attribute is not a valid date.', $attribute));

            return;
        }

        $from = $this->fromDate();

        if ($from === null) {
            $fail($this->messageText('The :attribute comparison date is not valid.', $attribute));

            return;
        }

        try {
            $target = $this->targetDate($from);
        } catch (\Throwable) {
            $fail($this->messageText('The :attribute could not be validated against business days.', $attribute));

            return;
        }

        if (! $this->passesComparison($date, $target)) {
            $fail($this->messageText($this->customMessage ?? $this->defaultMessage(), $attribute));
        }
    }

    private function fromDate(): ?CarbonImmutable
    {
        if ($this->from === null) {
            return CarbonImmutable::now()->startOfDay();
        }

        return $this->parseDateValue($this->from);
    }

    private function targetDate(CarbonImmutable $from): CarbonImmutable
    {
        $target = $this->target();

        $date = match ($this->direction) {
            'after' => $target->addBusinessDays($from, $this->days),
            'before' => $target->subBusinessDays($from, $this->days),
        };

        return $date->startOfDay();
    }

    private function passesComparison(CarbonImmutable $date, CarbonImmutable $target): bool
    {
        return $this->direction === 'after'
            ? $date->greaterThanOrEqualTo($target)
            : $date->lessThanOrEqualTo($target);
    }

    private function defaultMessage(): string
    {
        return $this->direction === 'after'
            ? sprintf('The :attribute must be after %d business days.', $this->days)
            : sprintf('The :attribute must be before %d business days.', $this->days);
    }

    private function target(): WorkdayManager|BusinessDayCalculator
    {
        $manager = app(WorkdayManager::class);

        if ($this->profile === null || $this->profile === '') {
            return $manager;
        }

        return $manager->profile($this->profile);
    }
}
