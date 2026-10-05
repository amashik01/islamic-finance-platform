<?php

namespace App\Models;

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Enums\RiskLevel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Project extends Model
{
    use \App\Models\Concerns\EnforcesBdt;

    /** Workflow fields (status, funded_amount, publication) change only through services. */
    protected $guarded = ['id', 'status', 'funded_amount', 'published_at', 'reviewer_id', 'is_demo'];

    protected function casts(): array
    {
        return [
            'contract_type' => ContractType::class,
            'status' => ProjectStatus::class,
            'risk_level' => RiskLevel::class,
            'assumptions' => 'array',
            'published_at' => 'datetime',
            'closing_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** The appointed Wakil (agent) for this project's Wakalah arrangement. */
    public function wakil(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wakil_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function shariahReviews(): HasMany
    {
        return $this->hasMany(ShariahReview::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeOpenForFunding(Builder $q): Builder
    {
        return $q->where('status', ProjectStatus::Funding)->where(fn ($q) => $q->whereNull('closing_at')->orWhere('closing_at', '>', now()));
    }

    public function fundingTarget(): Money
    {
        return Money::minor($this->funding_target, $this->currency);
    }

    public function fundedAmount(): Money
    {
        return Money::minor($this->funded_amount, $this->currency);
    }

    public function fundingPercent(): int
    {
        return $this->funding_target > 0 ? min(100, intdiv($this->funded_amount * 100, $this->funding_target)) : 0;
    }

    public function remainingCapacity(): Money
    {
        return Money::minor(max(0, $this->funding_target - $this->funded_amount), $this->currency);
    }
}
