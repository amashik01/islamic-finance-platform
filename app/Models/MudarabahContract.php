<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MudarabahContract extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
