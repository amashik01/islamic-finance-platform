<?php

namespace App\Policies;

use App\Models\Investment;
use App\Models\User;

class InvestmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInvestor() || $user->can('investments.view');
    }

    public function view(User $user, Investment $investment): bool
    {
        return $user->can('investments.view') || $user->investor?->id === $investment->investor_id;
    }

    public function manage(User $user, Investment $investment): bool
    {
        return $user->can('investments.manage');
    }
}
