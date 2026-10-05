<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use \App\Models\Concerns\EnforcesBdt;

    /** True only while LedgerService is posting; balances can never be edited anywhere else. */
    private static bool $ledgerWriting = false;

    protected static function booted(): void
    {
        static::updating(function (self $account): void {
            if ($account->isDirty('balance') && ! self::$ledgerWriting) {
                throw new \LogicException('Account balances are derived from the ledger and cannot be edited directly.');
            }
        });
    }

    /** Run a callback in which balance writes are permitted (used only by LedgerService). */
    public static function withBalanceWrites(\Closure $callback): mixed
    {
        $previous = self::$ledgerWriting;
        self::$ledgerWriting = true;
        try {
            return $callback();
        } finally {
            self::$ledgerWriting = $previous;
        }
    }

    protected $guarded = ['id', 'balance'];

    protected function casts(): array
    {
        return ['type'=>\App\Enums\LedgerAccountType::class];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
