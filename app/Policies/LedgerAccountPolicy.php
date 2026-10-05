<?php

namespace App\Policies;

use App\Models\LedgerAccount;
use App\Models\User;

class LedgerAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ledger.view');
    }

    public function adjust(User $user, ?LedgerAccount $account = null): bool
    {
        return $user->can('ledger.adjust');
    }
}
