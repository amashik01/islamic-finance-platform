<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShariahReview extends Model
{
    protected $guarded = ['id', 'status', 'reviewed_at'];

    protected function casts(): array
    {
        return ['status'=>\App\Enums\ShariahReviewStatus::class,'reviewed_at'=>'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
