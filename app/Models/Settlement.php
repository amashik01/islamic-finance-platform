<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use \App\Models\Concerns\EnforcesBdt;

    protected $guarded = ['id', 'status', 'posted_at', 'approved_by'];

    protected function casts(): array
    {
        return ['status'=>\App\Enums\SettlementStatus::class,'posted_at'=>'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class);
    }
}
