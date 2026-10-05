<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Business extends Model
{
    protected $guarded = ['id', 'kyc_status', 'kyc_reviewed_at', 'is_demo'];

    protected function casts(): array
    {
        return ['kyc_status' => KycStatus::class, 'is_demo' => 'boolean', 'kyc_reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
