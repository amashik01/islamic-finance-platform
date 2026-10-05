<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\RecoveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contract extends Model
{
    use \App\Models\Concerns\EnforcesBdt;

    protected $guarded = ['id', 'status', 'approved_by', 'approved_at', 'aqd_terms', 'aqd_form_version'];

    protected function casts(): array
    {
        return [
            'contract_type' => ContractType::class,
            'status' => ContractStatus::class,
            'recovery_status' => RecoveryStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
            'aqd_terms' => 'array',
        ];
    }

    /** The only sanctioned way to change status: validates the transition. Caller owns the transaction and row lock. */
    public function transitionTo(ContractStatus $to): static
    {
        if ($this->status === $to) {
            return $this;
        }
        if (! $this->status->canTransitionTo($to)) {
            throw new \App\Exceptions\FinancialException("A contract cannot move from {$this->status->label()} to {$to->label()}.");
        }
        $this->forceFill(['status' => $to])->save();

        return $this;
    }

    public function musharakahContribution(): HasOne
    {
        return $this->hasOne(MusharakahCapitalContribution::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function mudarabah(): HasOne
    {
        return $this->hasOne(MudarabahContract::class);
    }

    public function musharakah(): HasOne
    {
        return $this->hasOne(MusharakahContract::class);
    }

    public function murabaha(): HasOne
    {
        return $this->hasOne(MurabahaContract::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    /** Contract-specific terms row, resolved from the type (extension point for new contract types). */
    public function terms(): HasOne
    {
        return match ($this->contract_type) {
            ContractType::Mudarabah => $this->mudarabah(),
            ContractType::Musharakah => $this->musharakah(),
            ContractType::Murabaha => $this->murabaha(),
        };
    }

    public static function nextNumber(ContractType $type): string
    {
        $prefix = ['MUDARABAH' => 'MDB', 'MUSHARAKAH' => 'MSK', 'MURABAHA' => 'MRB'][$type->value];

        return sprintf('%s-%s-%s', $prefix, now()->format('Ymd'), strtoupper(\Illuminate\Support\Str::random(6)));
    }
}
