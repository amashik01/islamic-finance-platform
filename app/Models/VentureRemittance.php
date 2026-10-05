<?php

namespace App\Models;

use App\Models\Concerns\EnforcesBdt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash received from the business: either returned capital or INTERIM proceeds (never automatically final profit). */
class VentureRemittance extends Model
{
    use EnforcesBdt;

    public const CAPITAL_RETURN = 'CAPITAL_RETURN';

    public const INTERIM_PROCEEDS = 'INTERIM_PROCEEDS';

    protected $guarded = ['id', 'transaction_id', 'request_hash'];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
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
