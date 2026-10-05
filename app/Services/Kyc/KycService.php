<?php

namespace App\Services\Kyc;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVerificationStatus;
use App\Enums\KycStatus;
use App\Exceptions\FinancialException;
use App\Models\Business;
use App\Models\Investor;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class KycService
{
    public function __construct(private AuditLogger $audit, private \App\Services\Notify\Notifier $notify) {}

    public function submit(Investor|Business $party): void
    {
        $required = config('finance.kyc_required.'.($party instanceof Investor ? 'investor' : 'business'));
        $have = $party->documents()->pluck('category')->map(fn ($c) => $c->value)->unique()->all();
        $missing = array_diff($required, $have);
        if ($missing) {
            throw new FinancialException('Upload the required documents first: '.collect($missing)->map(fn ($c) => DocumentCategory::from($c)->label())->implode(', ').'.');
        }
        if (! in_array($party->kyc_status, [KycStatus::NotSubmitted, KycStatus::Rejected], true)) {
            throw new FinancialException('Your verification is already under review or complete.');
        }
        $party->forceFill(['kyc_status' => KycStatus::Pending])->save();
        $this->audit->record('kyc.submitted', $party);
        $this->notify->toStaffWith('kyc.review', 'New KYC submission', ($party instanceof Investor ? $party->user->name : $party->name).' submitted documents for verification.', route('admin.kyc'));
    }

    public function review(Investor|Business $party, User $by, bool $approve, ?string $reason = null): void
    {
        if (! $by->can($approve ? 'kyc.approve' : 'kyc.reject')) {
            throw new FinancialException('You are not allowed to '.($approve ? 'approve' : 'reject').' verification.');
        }
        if ($party->kyc_status !== KycStatus::Pending) {
            throw new FinancialException('Only submissions that are pending review can be decided.');
        }
        if (! $approve && blank($reason)) {
            throw new FinancialException('Give a reason so the applicant knows what to fix.');
        }

        DB::transaction(function () use ($party, $by, $approve, $reason) {
            $old = $party->kyc_status->value;
            $party->forceFill(['kyc_status' => $approve ? KycStatus::Approved : KycStatus::Rejected, 'kyc_reviewed_at' => now()])->save();
            if ($approve) {
                $party->documents()->where('verification_status', DocumentVerificationStatus::Pending)->update(['verification_status' => DocumentVerificationStatus::Verified, 'verified_by' => $by->id, 'verified_at' => now()]);
            }
            $this->notify->to($party->user, $approve ? 'Verification approved' : 'Verification not accepted', $approve ? 'You are now verified.' : 'Reason: '.$reason, $approve ? 'success' : 'warning');
            $this->audit->record($approve ? 'kyc.approved' : 'kyc.rejected', $party, ['kyc_status' => $old], ['kyc_status' => $party->kyc_status->value], $reason);
        });
    }
}
