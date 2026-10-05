<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Investor;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Public self-registration: only INVESTOR and BUSINESS roles can ever be self-assigned. */
class CreateAccount
{
    public function __construct(private WalletService $wallets) {}

    public function __invoke(array $data, UserRole $role): User
    {
        abort_unless(in_array($role, [UserRole::Investor, UserRole::Business], true), 403);

        return DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $user->assignRole($role->value);

            if ($role === UserRole::Investor) {
                Investor::create(['user_id' => $user->id]);
                $this->wallets->walletFor($user);
            } else {
                Business::create(['user_id' => $user->id, 'name' => $data['business_name']]);
            }

            return $user;
        });
    }
}
