<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MurabahaSale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sold_on'=>'date'];
    }

    public function murabahaContract(): BelongsTo
    {
        return $this->belongsTo(MurabahaContract::class);
    }

    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class);
    }
}
