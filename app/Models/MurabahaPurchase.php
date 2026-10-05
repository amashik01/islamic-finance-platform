<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MurabahaPurchase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['purchased_on'=>'date','ownership_acquired_on'=>'date','possession_on'=>'date'];
    }

    public function murabahaContract(): BelongsTo
    {
        return $this->belongsTo(MurabahaContract::class);
    }
}
