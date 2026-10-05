<?php

namespace App\Models;

use App\Enums\EntryDirection;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use Immutable;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected array $mutableAttributes = [];

    protected function casts(): array
    {
        return ['direction' => EntryDirection::class, 'created_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
