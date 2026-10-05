<?php

namespace App\Services\Finance\Reconciliation\Checks;

use App\Services\Finance\Reconciliation\Check;
use Illuminate\Support\Facades\DB;

/** A Wakalah is effective only when accepted by the Wakil and reviewed; it has no place on Mudarabah/Musharakah; acts need an authorised role. */
class WakalahIntegrity extends Check
{
    private array $warnings = [];

    public function name(): string
    {
        return 'Wakalah Integrity';
    }

    protected function errors(): array
    {
        $e = [];
        $live = DB::table('wakalah_appointments as a')->join('projects as p', 'p.id', '=', 'a.project_id')->where('a.is_current', 1)->select('a.*', 'p.status as pstatus', 'p.contract_type')->get();
        foreach ($live as $a) {
            $tag = "Wakalah #{$a->id} (project #{$a->project_id}, {$a->slot})";
            if ($a->contract_type !== 'MURABAHA') {
                $e[] = "$tag: a Wakil is appointed on a {$a->contract_type} project, which defines no Wakalah role.";
            }
            if ($a->muwakkil === null) {
                $this->warnings[] = "$tag is LEGACY: its principal (Muwakkil) was never recorded.";

                continue;
            }
            if ($a->status === 'CONFIRMED') {
                if ($a->accepted_at === null) {
                    $e[] = "$tag is confirmed without the Wakil's acceptance.";
                }
                if ($a->shariah_reviewer_id === null && $a->shariah_decision === null && config('finance.sandbox') === false) {
                    $this->warnings[] = "$tag is confirmed without an appointment-level Shariah review.";
                }
                if (empty(json_decode((string) $a->authority, true))) {
                    $e[] = "$tag is confirmed without any recorded authority.";
                }
            } elseif (in_array($a->pstatus, ['FUNDING', 'ACTIVE', 'COMPLETED'], true)) {
                $e[] = "$tag is {$a->status} while the project is {$a->pstatus}.";
            }
        }
        // A purchase recorded by a Wakil needs a confirmed (now or at the time) appointment of that Wakil.
        foreach (DB::table('murabaha_purchases as pu')->join('murabaha_contracts as m', 'm.id', '=', 'pu.murabaha_contract_id')->join('contracts as c', 'c.id', '=', 'm.contract_id')->whereNotNull('pu.acting_wakil_id')->select('pu.id', 'pu.acting_wakil_id', 'c.project_id')->get() as $p) {
            if (! DB::table('wakalah_appointments')->where('project_id', $p->project_id)->where('wakil_id', $p->acting_wakil_id)->where('slot', 'PURCHASE')->whereNotNull('confirmed_at')->exists()) {
                $e[] = "Murabaha purchase #{$p->id} was recorded by a Wakil with no confirmed PURCHASE Wakalah.";
            }
        }

        return $this->capped($e);
    }

    protected function warnings(): array
    {
        return $this->capped($this->warnings);
    }
}
