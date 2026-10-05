<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @param array<string,mixed>|null $old @param array<string,mixed>|null $new */
    public function record(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?string $reason = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
        ]);
    }
}
