<?php

namespace App\Models;

use App\Models\Concerns\EnforcesBdt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The recorded delivery of participant capital to the business (Ras-ul-Mal / partnership capital). Once per contract. */
class CapitalDeployment extends Model
{
    use EnforcesBdt;

    protected $guarded = ['id', 'transaction_id', 'request_hash'];

    protected function casts(): array
    {
        return ['deployed_on' => 'date'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
