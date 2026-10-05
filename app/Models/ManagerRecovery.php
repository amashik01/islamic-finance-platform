<?php

namespace App\Models;

use App\Enums\ManagerRecoveryStatus;
use App\Models\Concerns\EnforcesBdt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An amount recoverable from a Mudarabah manager found at fault (negligence, misconduct or breach). */
class ManagerRecovery extends Model
{
    use EnforcesBdt;

    protected $guarded = ['id', 'recovered_amount', 'status'];

    protected function casts(): array
    {
        return ['status' => ManagerRecoveryStatus::class];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outstanding(): int
    {
        return $this->amount - $this->recovered_amount;
    }
}
