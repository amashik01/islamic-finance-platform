<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MusharakahContract extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['loss_allocation_basis' => \App\Enums\LossAllocationBasis::class];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
