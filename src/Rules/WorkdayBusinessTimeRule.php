<?php

declare(strict_types=1);

namespace Zarbinco\LaravelWorkdays\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Zarbinco\LaravelWorkdays\Calculator\BusinessDayCalculator;
use Zarbinco\LaravelWorkdays\Exceptions\WorkdayConfigurationException;
use Zarbinco\LaravelWorkdays\Rules\Concerns\ParsesRuleDates;
use Zarbinco\LaravelWorkdays\WorkdayManager;

final class WorkdayBusinessTimeRule implements ValidationRule
{
    use ParsesRuleDates;

    private ?string $customMessage = null;

    public function __construct(
        private readonly bool $expected,
        private readonly ?string $profile,
    ) {}

    public function message(string $message): self
    {
        $this->customMessage = $message;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $datetime = $this->parseDateTimeValue($value);

        if ($datetime === null) {
            $fail($this->messageText('The :attribute is not a valid date.', $attribute));

            return;
        }

        try {
            $result = $this->target()->isBusinessTime($datetime);
        } catch (WorkdayConfigurationException) {
            $fail($this->messageText('The :attribute could not be validated because working hours are not configured.', $attribute));

            return;
        } catch (\Throwable) {
            $fail($this->messageText('The :attribute could not be validated as business time.', $attribute));

            return;
        }

        if ($result !== $this->expected) {
            $fail($this->messageText($this->customMessage ?? $this->defaultMessage(), $attribute));
        }
    }

    private function defaultMessage(): string
    {
        return $this->expected
            ? 'The :attribute must be within business hours.'
            : 'The :attribute must not be within business hours.';
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
