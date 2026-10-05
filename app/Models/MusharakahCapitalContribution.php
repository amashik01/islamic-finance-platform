<?php

namespace App\Models;

use App\Enums\CapitalContributionStatus;
use App\Models\Concerns\EnforcesBdt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The business partner's capital contribution to a Musharakah, traceable to the ledger transaction that received it. */
class MusharakahCapitalContribution extends Model
{
    use EnforcesBdt;

    protected $guarded = ['id', 'status', 'transaction_id', 'request_hash'];

    protected function casts(): array
    {
        return ['status' => CapitalContributionStatus::class, 'received_on' => 'date'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
