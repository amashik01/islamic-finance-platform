<?php

namespace App\Services\Wakalah;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Models\WakilProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Registration and eligibility of Wakils, staff-managed (permission wakils.manage). Verification itself reuses the
 * platform's KYC review (KycService::review), so there is one verification mechanism, not a parallel one.
 */
class WakilRegistry
{
    public function __construct(private AuditLogger $audit) {}

    /** Gives an existing user the WAKIL role and a profile awaiting verification. */
    public function register(User $user, string $displayName, User $by): WakilProfile
    {
        $this->authorize($by);
        if ($user->isStaffMember() || $user->isInvestor() || $user->isBusiness()) {
            throw new FinancialException('A user with another platform role cannot be registered as a Wakil.');
        }

        return DB::transaction(function () use ($user, $displayName, $by) {
            $user->assignRole(UserRole::Wakil->value);
            $profile = WakilProfile::firstOrCreate(['user_id' => $user->id], ['display_name' => $displayName]);
            $profile->forceFill(['kyc_status' => $profile->wasRecentlyCreated ? KycStatus::Pending : $profile->kyc_status])->save();
            $this->audit->record('wakil.registered', $profile, null, ['user_id' => $user->id, 'display_name' => $displayName], null);

            return $profile;
        });
    }

    public function suspend(WakilProfile $profile, User $by, string $reason): WakilProfile
    {
        return $this->setStatus($profile, $by, WakilProfile::SUSPENDED, 'wakil.suspended', $reason);
    }

    public function reinstate(WakilProfile $profile, User $by, ?string $reason = null): WakilProfile
    {
        return $this->setStatus($profile, $by, WakilProfile::ACTIVE, 'wakil.reinstated', $reason);
    }

    private function setStatus(WakilProfile $profile, User $by, string $status, string $action, ?string $reason): WakilProfile
    {
        $this->authorize($by);
        if ($status === WakilProfile::SUSPENDED && blank($reason)) {
            throw new FinancialException('Give a reason for suspending a Wakil.');
        }
        $old = $profile->status;
        $profile->forceFill(['status' => $status])->save();
        $this->audit->record($action, $profile, ['status' => $old], ['status' => $status], $reason);

        return $profile;
    }

    private function authorize(User $by): void
    {
        if (! $by->can('wakils.manage')) {
            throw new FinancialException('You are not allowed to manage Wakils.');
        }
    }
}
