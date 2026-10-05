<?php

namespace App\Models\Concerns;

use LogicException;

/** Prevents updates/deletes of financial and audit records; corrections must be new reversal records. */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function ($model): void {
            $allowed = $model->mutableAttributes ?? [];
            $illegal = array_diff(array_keys($model->getDirty()), $allowed);
            if ($illegal !== []) {
                throw new LogicException(class_basename($model).' records are immutable: '.implode(', ', $illegal));
            }
        });

        static::deleting(function ($model): void {
            throw new LogicException(class_basename($model).' records cannot be deleted.');
        });
    }
}
