<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MurabahaAsset extends Model
{
    protected $guarded = ['id'];

    public function murabahaContract(): BelongsTo
    {
        return $this->belongsTo(MurabahaContract::class);
    }
}
