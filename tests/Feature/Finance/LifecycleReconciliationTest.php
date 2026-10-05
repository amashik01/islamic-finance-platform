<?php

use App\Enums\ContractType;
use App\Enums\LedgerAccountType as A;
use App\Models\Contract;
use App\Models\MusharakahCapitalContribution;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use App\Support\Money\Money;

function errorsOf(string $check): string
{
    return implode(' | ', reconcile()['results'][$check]->errors);
}

it('a clean real Mudarabah and Musharakah lifecycle reconciles (strict)', function () {
    $m = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $m, 10000000);
    $k = realProject(ContractType::Musharakah);
    fund(makeInvestor(80000000), $k, 70000000);
    recordBusinessCapital($k->contract->fresh());
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('detects an Active contract whose project was never fully funded, naming the contract and project', function () {
    $p = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $p, 4000000);
    corrupt('contracts', ['id' => $p->contract->id], ['status' => 'ACTIVE']);
    $e = errorsOf('Contract Lifecycle');
    expect(reconcile()['passed'])->toBeFalse()->and($e)->toContain((string) $p->contract->id);
});

it('detects a Musharakah contract with no business contribution record', function () {
    $p = realProject(ContractType::Musharakah);
    fund(makeInvestor(80000000), $p, 70000000);
    recordBusinessCapital($p->contract->fresh());
    \Illuminate\Support\Facades\DB::table('musharakah_capital_contributions')->where('contract_id', $p->contract->id)->delete();
    expect(reconcile()['passed'])->toBeFalse()->and(errorsOf('Contract Lifecycle'))->toContain((string) $p->contract->id);
});

it('detects a contribution amount that differs from the contract terms', function () {
    $p = realProject(ContractType::Musharakah);
    fund(makeInvestor(80000000), $p, 70000000);
    $row = recordBusinessCapital($p->contract->fresh());
    if (! corrupt('musharakah_capital_contributions', ['id' => $row->id], ['amount' => 29000000])) {
        $this->markTestSkipped('Database constraint prevented the corruption.');
    }
    expect(reconcile()['passed'])->toBeFalse()->and(errorsOf('Contract Lifecycle'))->toContain((string) $p->contract->id);
});

it('detects a duplicated activation audit entry', function () {
    $p = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $p, 10000000);
    $log = \App\Models\AuditLog::where('action', 'contract.activated')->firstOrFail();
    \Illuminate\Support\Facades\DB::table('audit_logs')->insert(collect($log->getAttributes())->except('id')->all());
    expect(reconcile()['passed'])->toBeFalse()->and(errorsOf('Contract Lifecycle'))->toContain((string) $p->contract->id);
});

it('detects a completed contract with no settlement', function () {
    $p = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $p, 10000000);
    corrupt('contracts', ['id' => $p->contract->id], ['status' => 'COMPLETED']);
    expect(reconcile()['passed'])->toBeFalse()->and(errorsOf('Contract Lifecycle'))->toContain((string) $p->contract->id);
});

it('detects an unexplained ProjectFunds balance (cached balance edited behind the ledger)', function () {
    $p = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $p, 4000000);
    app(\App\Services\Ledger\LedgerService::class)->systemAccount(A::ProjectFunds, 'BDT', $p->id);
    corrupt('ledger_accounts', ['type' => 'PROJECT_FUNDS', 'project_id' => $p->id], ['balance' => 9000000]);
    $r = reconcile();
    expect($r['passed'])->toBeFalse();
});

it('detects negative ProjectFunds and reports the project id', function () {
    $p = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $p, 4000000);
    app(\App\Services\Ledger\LedgerService::class)->systemAccount(A::ProjectFunds, 'BDT', $p->id);
    if (! corrupt('ledger_accounts', ['type' => 'PROJECT_FUNDS', 'project_id' => $p->id], ['balance' => -1])) {
        $this->markTestSkipped('Database constraint prevented the corruption.');
    }
    expect(reconcile()['passed'])->toBeFalse()->and(errorsOf('Project Funding'))->toContain((string) $p->id);
});

it('detects a settlement item that no longer matches the contributions (Musharakah capital completeness)', function () {
    $p = realProject(ContractType::Musharakah);
    fund(makeInvestor(80000000), $p, 70000000);
    $c = $p->contract->fresh();
    recordBusinessCapital($c);
    closeOut($c->fresh(), -10000000);
    $s = app(SettlementService::class)->settle($c->fresh(), Money::minor(-10000000), User::factory()->create());
    $item = $s->items()->where('item_type', 'BUSINESS_CAPITAL_LOSS')->firstOrFail();
    corrupt('settlement_items', ['id' => $item->id], ['amount' => -1000000]);
    expect(reconcile()['passed'])->toBeFalse()->and(implode(' | ', reconcile()['results']['Settlement Integrity']->errors))->toContain((string) $s->id);
});
