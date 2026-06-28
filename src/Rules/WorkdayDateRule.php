<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Rules\Concerns\ParsesRuleDates;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class WorkdayDateRule implements ValidationRule
{
    use ParsesRuleDates;

    private ?string $customMessage = null;

    public function __construct(
        private readonly string $method,
        private readonly bool $expected,
        private readonly ?string $profile,
        private readonly string $defaultMessage,
    ) {}

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

        try {
            $result = $this->target()->{$this->method}($date);
        } catch (\Throwable) {
            $fail($this->messageText('The :attribute could not be validated as a workday date.', $attribute));

            return;
        }

        if ($result !== $this->expected) {
            $fail($this->messageText($this->customMessage ?? $this->defaultMessage, $attribute));
        }
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
