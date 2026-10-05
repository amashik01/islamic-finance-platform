<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAmendment extends Model
{
    protected $guarded = ['id', 'status', 'to_document_id', 'shariah_reviewer_id', 'shariah_reviewed_at', 'shariah_notes', 'legal_reviewed_by'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'legal_review_required' => 'boolean', 'shariah_reviewed_at' => 'datetime'];
    }

    public function fromDocument(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class, 'from_document_id');
    }

    public function toDocument(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class, 'to_document_id');
    }
}
