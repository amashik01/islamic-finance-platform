<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $guarded = ['id', 'status', 'reviewed_by', 'reviewed_at', 'transaction_id'];

    protected function casts(): array
    {
        return ['status'=>\App\Enums\WithdrawalStatus::class,'reviewed_at'=>'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
