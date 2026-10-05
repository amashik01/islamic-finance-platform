<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    use \App\Models\Concerns\EnforcesBdt;

    protected $guarded = ['id', 'status', 'verified_by', 'verified_at', 'transaction_id'];

    protected function casts(): array
    {
        return ['status'=>\App\Enums\DepositStatus::class,'verified_at'=>'datetime'];
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
