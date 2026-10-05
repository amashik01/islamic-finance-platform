<?php

namespace App\Models;

use App\Enums\ContractDocumentKind;
use App\Enums\ContractDocumentStatus;
use App\Models\Concerns\EnforcesBdt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A generated agreement. Content, hash, terms and template version never change after generation; only the lifecycle
 * status moves forward. Once EXECUTED the database itself refuses edits (triggers) - see the contract_engine migration.
 */
class ContractDocument extends Model
{
    use EnforcesBdt;

    protected $guarded = ['id', 'status', 'executed_at', 'consumed_by_investment_id'];

    protected function casts(): array
    {
        return ['kind' => ContractDocumentKind::class, 'status' => ContractDocumentStatus::class, 'terms_snapshot' => 'array', 'generated_at' => 'datetime', 'executed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $d): void {
            $locked = ['content', 'terms_snapshot', 'terms_hash', 'document_hash', 'template_version_id', 'kind', 'project_id', 'contract_id', 'amount', 'party_user_id', 'version_no', 'reference'];
            $dirty = array_intersect(array_keys($d->getDirty()), $locked);
            if ($dirty !== []) {
                throw new \LogicException('A generated agreement is immutable: '.implode(', ', $dirty).'. Generate a new version instead.');
            }
        });
        static::deleting(function (self $d): void {
            if ($d->status->isFinal()) {
                throw new \LogicException('Executed agreements cannot be deleted.');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(User::class, 'party_user_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ContractTemplateVersion::class, 'template_version_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** True when the stored content still hashes to the stored hash (detects any tampering). */
    public function hashIntact(): bool
    {
        return hash_equals($this->document_hash, hash('sha256', $this->content));
    }

    public function isExecuted(): bool
    {
        return $this->status === ContractDocumentStatus::Executed;
    }
}
