<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Withdrawal;

class WithdrawalPolicy
{
    public function view(User $user, Withdrawal $withdrawal): bool
    {
        return $user->can('withdrawals.view') || $withdrawal->user_id === $user->id;
    }

    public function approve(User $user, Withdrawal $withdrawal): bool
    {
        return $user->can('withdrawals.approve');
    }

    public function reject(User $user, Withdrawal $withdrawal): bool
    {
        return $user->can('withdrawals.reject');
    }

    public function cancel(User $user, Withdrawal $withdrawal): bool
    {
        return $withdrawal->user_id === $user->id;
    }
}
