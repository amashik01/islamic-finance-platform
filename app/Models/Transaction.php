<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A ledger transaction. Only `status` may change (POSTED -> REVERSED); everything else is write-once. */
class Transaction extends Model
{
    use Immutable;

    protected $guarded = ['id'];

    protected array $mutableAttributes = ['status', 'updated_at'];

    protected function casts(): array
    {
        return ['type' => TransactionType::class, 'status' => TransactionStatus::class, 'meta' => 'array', 'posted_at' => 'datetime'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
