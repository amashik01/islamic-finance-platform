<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receivable extends Model
{
    protected $guarded = ['id', 'paid_amount', 'status'];

    protected function casts(): array
    {
        return ['status'=>\App\Enums\PaymentStatus::class];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(MurabahaSale::class, 'murabaha_sale_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function outstanding(): int
    {
        return $this->total_amount - $this->paid_amount;
    }
}
