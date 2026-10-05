<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A registered Wakil's organisation profile. Verification uses the platform's existing KycStatus; eligibility is staff-managed. */
class WakilProfile extends Model
{
    public const ACTIVE = 'ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    protected $guarded = ['id', 'kyc_status', 'kyc_reviewed_at', 'status'];

    protected function casts(): array
    {
        return ['kyc_status' => KycStatus::class, 'kyc_reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** Registered, verified, active, WAKIL-role, email-verified users only: the single definition of "eligible Wakil". */
    public function scopeEligible(Builder $q): Builder
    {
        return $q->where('kyc_status', KycStatus::Approved->value)->where('status', self::ACTIVE)
            ->whereHas('user', fn ($u) => $u->where('status', 'ACTIVE')->whereNotNull('email_verified_at')->role(\App\Enums\UserRole::Wakil->value));
    }
}
