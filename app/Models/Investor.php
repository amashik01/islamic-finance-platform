<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Investor extends Model
{
    protected $guarded = ['id', 'kyc_status', 'kyc_reviewed_at', 'bank_verified', 'risk_flag', 'is_demo'];

    protected function casts(): array
    {
        return ['kyc_status' => KycStatus::class, 'date_of_birth' => 'date', 'bank_verified' => 'boolean', 'risk_flag' => 'boolean', 'is_demo' => 'boolean', 'kyc_reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isVerified(): bool
    {
        return $this->kyc_status === KycStatus::Approved;
    }
}
