<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = ['id', 'transaction_id'];

    protected function casts(): array
    {
        return ['paid_on'=>'date'];
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }
}
