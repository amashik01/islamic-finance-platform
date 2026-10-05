<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSchedule extends Model
{
    protected $guarded = ['id', 'paid_amount', 'status'];

    protected function casts(): array
    {
        return ['due_date'=>'date','status'=>\App\Enums\PaymentStatus::class];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
