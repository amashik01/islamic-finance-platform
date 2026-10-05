<?php

namespace App\Models\Concerns;

use App\Support\Money\Currency;

/** Persisted records can only carry BDT. Defaults to BDT when unset; anything else is rejected before it is saved. */
trait EnforcesBdt
{
    public static function bootEnforcesBdt(): void
    {
        static::saving(function ($model): void {
            $model->currency ??= Currency::CODE;
            Currency::require($model->currency, class_basename($model).' currency');
        });
    }
}
