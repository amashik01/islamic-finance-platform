<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Investment extends Model
{
    protected $guarded = ['id', 'status', 'invested_at', 'is_demo'];

    protected function casts(): array
    {
        return ['status'=>\App\Enums\InvestmentStatus::class,'invested_at'=>'datetime','maturity_date'=>'date','is_demo'=>'boolean'];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
