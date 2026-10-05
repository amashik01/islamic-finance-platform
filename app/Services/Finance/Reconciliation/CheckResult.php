<?php

namespace App\Services\Finance\Reconciliation;

final readonly class CheckResult
{
    /** @param list<string> $errors invariant violations @param list<string> $warnings legacy/soft findings (fail only in --strict) */
    public function __construct(public string $name, public array $errors = [], public array $warnings = []) {}

    public function passed(bool $strict = false): bool
    {
        return $this->errors === [] && (! $strict || $this->warnings === []);
    }
}
