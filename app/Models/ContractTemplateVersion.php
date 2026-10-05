<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One immutable version of a template. A change is a NEW version; contracts already generated keep the version they used. */
class ContractTemplateVersion extends Model
{
    protected $guarded = ['id', 'shariah_review_status', 'shariah_reviewer_id', 'shariah_reviewed_at', 'shariah_notes', 'content_hash'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'shariah_reviewed_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function clauses(): HasMany
    {
        return $this->hasMany(ContractClause::class, 'template_version_id')->orderBy('position');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shariah_reviewer_id');
    }

    /** Hash of the clause content; stored at creation and re-checked by reconciliation. */
    public function computeContentHash(): string
    {
        return hash('sha256', $this->clauses()->get()->map(fn ($c) => implode("\x1f", [$c->position, $c->code, $c->heading, $c->body, (string) $c->condition]))->implode("\x1e"));
    }
}
