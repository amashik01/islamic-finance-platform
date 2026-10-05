<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementItem extends Model
{
    protected $guarded = ['id', 'transaction_id'];

    protected function casts(): array
    {
        return ['item_type'=>\App\Enums\SettlementItemType::class];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
