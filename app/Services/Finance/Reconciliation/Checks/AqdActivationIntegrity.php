<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * No project is funding, active or completed without an executed, Shariah-reviewed agreement whose reviewed terms are the signed terms,
 * and no funds are held without an approved platform role (outside the sandbox). Pre-engine projects are reported as LEGACY warnings.
 */
class AqdActivationIntegrity extends Check
{
    private array $warnings = [];

    public function name(): string
    {
        return 'Aqd Shariah Activation';
    }

    protected function errors(): array
    {
        $e = [];
        $contracts = DB::table('contracts as c')->join('projects as p', 'p.id', '=', 'c.project_id')->whereIn('p.status', ['FUNDING', 'ACTIVE', 'COMPLETED'])
            ->select('c.id', 'c.project_id', 'c.aqd_form_version', 'p.status as pstatus', 'c.contract_type')->get();
        foreach ($contracts as $c) {
            $tag = "Contract #{$c->id} (project #{$c->project_id}, {$c->pstatus})";
            if ($c->aqd_form_version === null) {
                $this->warnings[] = "$tag is LEGACY: it predates the contract-specific terms and generated agreements.";

                continue;
            }
            $master = DB::table('contract_documents')->where('contract_id', $c->id)->where('kind', 'MASTER_AQD')->where('status', 'EXECUTED')->orderByDesc('id')->first();
            if (! $master) {
                $e[] = "$tag has no executed master agreement.";

                continue;
            }
            $review = $master->shariah_review_id ? DB::table('shariah_reviews')->find($master->shariah_review_id) : null;
            if (! $review || $review->status !== 'APPROVED') {
                $e[] = "$tag: the executed agreement #{$master->id} has no approved Shariah review.";
            } elseif ($review->reviewed_terms_hash !== $master->terms_hash) {
                $e[] = "$tag: the executed agreement #{$master->id} differs from the terms the Shariah reviewer approved.";
            }
            if (! $this->hasTerms($c->id)) {
                $e[] = "$tag has an executed agreement but no recorded contract-specific terms.";
            }
        }

        $settings = app(SettingsService::class);
        $holdsFunds = DB::table('investments')->whereIn('status', ['CONFIRMED', 'ACTIVE'])->exists();
        if ($holdsFunds && $settings->get('shariah.platform_role') === 'UNSET') {
            $msg = 'Investor funds are held but the platform\'s contractual role has not been approved by its Shariah board.';
            // The sandbox is explicitly exempt (no real money); anywhere else this is a broken Shariah invariant.
            config('finance.sandbox') || $e[] = $msg;
        }

        return $this->capped($e);
    }

    private function hasTerms(int $contractId): bool
    {
        $t = DB::table('contracts')->where('id', $contractId)->value('aqd_terms');

        return $t !== null && $t !== '' && $t !== '[]';
    }

    protected function warnings(): array
    {
        return $this->capped($this->warnings);
    }
}
