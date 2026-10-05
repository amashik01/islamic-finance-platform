<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The recorded promise (wa'd) that preceded a Murabaha sale. A promise is never the sale and never creates a receivable. */
class MurabahaPromise extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function murabahaContract(): BelongsTo
    {
        return $this->belongsTo(MurabahaContract::class);
    }
}
