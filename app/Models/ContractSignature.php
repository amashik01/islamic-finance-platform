<?php

namespace App\Models;

use App\Enums\SignerRole;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence that a person signed the exact document hash. Never updated or deleted (model guard + database trigger). */
class ContractSignature extends Model
{
    use Immutable;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected array $mutableAttributes = [];

    protected $hidden = ['ip_address', 'user_agent'];   // metadata is staff-only and never serialised to ordinary users

    protected function casts(): array
    {
        return ['signer_role' => SignerRole::class, 'identity_check' => 'array', 'signed_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ContractDocument::class, 'contract_document_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }
}
