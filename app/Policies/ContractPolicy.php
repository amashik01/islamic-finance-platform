<?php

namespace App\Policies;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function view(User $user, Contract $contract): bool
    {
        if ($user->can('contracts.view')) {
            return true;
        }
        if ($user->business?->id === $contract->project->business_id) {
            return true;
        }

        return $user->investor && $contract->investments()->where('investor_id', $user->investor->id)->exists();
    }

    /** Terms are frozen after approval; only managers can change them (and must audit it). */
    public function update(User $user, Contract $contract): bool
    {
        return $user->can('contracts.manage') && in_array($contract->status, [ContractStatus::Draft, ContractStatus::PendingApproval], true);
    }
}
