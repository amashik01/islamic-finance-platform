<?php

namespace App\Services\Finance\Reconciliation;

/** A read-only financial invariant check. Output contains record ids only, never personal data. */
abstract class Check
{
    abstract public function name(): string;

    /** @return list<string> */
    abstract protected function errors(): array;

    /** @return list<string> */
    protected function warnings(): array
    {
        return [];
    }

    public function run(): CheckResult
    {
        return new CheckResult($this->name(), $this->errors(), $this->warnings());
    }

    /** Cap very long lists so output stays readable. @param list<string> $messages @return list<string> */
    protected function capped(array $messages, int $max = 25): array
    {
        return count($messages) <= $max ? $messages : [...array_slice($messages, 0, $max), '… and '.(count($messages) - $max).' more.'];
    }
}
