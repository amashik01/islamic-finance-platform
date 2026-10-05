<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MurabahaContract extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stage' => \App\Enums\MurabahaStage::class];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MurabahaAsset::class);
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(MurabahaPurchase::class);
    }

    public function sale(): HasOne
    {
        return $this->hasOne(MurabahaSale::class);
    }
}
