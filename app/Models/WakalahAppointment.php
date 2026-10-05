<?php

namespace App\Models;

use App\Enums\WakalahRole;
use App\Enums\WakalahStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Wakalah appointment of a Wakil on a Project for one explicit role. History is kept; revoked rows are never reused. */
class WakalahAppointment extends Model
{
    protected $guarded = ['id', 'status', 'is_current', 'confirmed_at', 'revoked_at', 'revoked_by', 'revocation_reason', 'accepted_by', 'accepted_at', 'rejected_at', 'rejection_reason', 'shariah_reviewer_id', 'shariah_reviewed_at', 'shariah_decision', 'shariah_notes'];

    protected function casts(): array
    {
        return [
            'wakalah_role' => WakalahRole::class, 'status' => WakalahStatus::class, 'is_current' => 'boolean',
            'appointed_at' => 'datetime', 'confirmed_at' => 'datetime', 'revoked_at' => 'datetime', 'accepted_at' => 'datetime', 'rejected_at' => 'datetime', 'shariah_reviewed_at' => 'datetime',
            'authority' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function wakil(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wakil_id');
    }

    public function appointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }
}
