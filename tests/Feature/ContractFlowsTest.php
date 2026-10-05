<?php

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\InvestmentStatus;
use App\Enums\KycStatus;
use App\Enums\MurabahaStage;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RecoveryStatus;
use App\Enums\SettlementItemType;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\MurabahaAsset;
use App\Models\User;
use App\Services\Murabaha\MurabahaService;
use App\Services\Project\ProjectWorkflow;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

function bal($investor): array
{
    $b = app(WalletService::class)->balances(app(WalletService::class)->walletFor($investor->user));

    return array_map(fn (Money $m) => $m->minor, $b);
}

/** Two investors fund a 100,000 Mudarabah (60k + 40k). */
function fundedMudarabah(): array
{
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    $a = makeInvestor(20000000);
    $b = makeInvestor(20000000);
    $svc = app(InvestmentService::class);
    $svc->invest($a, $project, Money::minor(6000000), 'm-a');
    $svc->invest($b, $project, Money::minor(4000000), 'm-b');

    return [$contract->fresh(), $a, $b, $project->fresh()];
}

it('mudarabah: 100k capital, 20k profit, 70/30 -> investors get principal plus 14k profit, shared pro rata', function () {
    [$contract, $a, $b] = fundedMudarabah();
    $admin = User::factory()->create();

    closeOut($contract, 2000000);
    $s = app(SettlementService::class)->settle($contract, Money::minor(2000000), $admin);

    // A: 60% of 14,000 = 8,400 ; B: 40% = 5,600. Principal back in full.
    expect(bal($a))->toMatchArray(['available' => 14000000 + 6000000 + 840000, 'invested' => 0])
        ->and(bal($b))->toMatchArray(['available' => 16000000 + 4000000 + 560000, 'invested' => 0]);
    expect($s->items->where('item_type', SettlementItemType::InvestmentProfit)->sum('amount'))->toBe(1400000)
        ->and($s->items->where('item_type', SettlementItemType::Principal)->sum('amount'))->toBe(10000000)
        ->and($contract->fresh()->status)->toBe(ContractStatus::Completed)
        ->and($contract->project->fresh()->status)->toBe(ProjectStatus::Completed)
        ->and($contract->mudarabah->fresh()->actual_net_result)->toBe(2000000);
    expect(AuditLog::where('action', 'settlement.posted')->count())->toBe(1);
});

it('mudarabah: cannot be settled twice', function () {
    [$contract] = fundedMudarabah();
    $svc = app(SettlementService::class);
    closeOut($contract, 100000);
    $svc->settle($contract, Money::minor(100000), User::factory()->create());
    expect(fn () => $svc->settle($contract->fresh(), Money::minor(100000), User::factory()->create()))->toThrow(FinancialException::class, 'active contract');
});

it('mudarabah: a loss reduces returned principal and never creates profit', function () {
    [$contract, $a, $b] = fundedMudarabah();
    closeOut($contract, -1000000);
    app(SettlementService::class)->settle($contract, Money::minor(-1000000), User::factory()->create());

    // 10% loss on 60k = 6k, on 40k = 4k
    expect(bal($a)['available'])->toBe(14000000 + 5400000)->and(bal($b)['available'])->toBe(16000000 + 3600000)
        ->and(bal($a)['invested'])->toBe(0);
});

it('mudarabah: loss caused by manager fault is flagged for recovery', function () {
    [$contract] = fundedMudarabah();
    closeOut($contract, -1000000);
    app(SettlementService::class)->settle($contract, Money::minor(-1000000), User::factory()->create(), managerAtFault: true, reason: 'Funds misused');
    expect($contract->fresh()->recovery_status)->toBe(RecoveryStatus::None)   // an allegation is not yet a recovery
        ->and(\App\Models\ManagerRecovery::first()->status)->toBe(\App\Enums\ManagerRecoveryStatus::Suspected);
});

it('musharakah: 700k investor + 300k business, 50/50 profit on 100k -> investor profit 50k, capital returned', function () {
    $project = makeProject(['funding_target' => 70000000, 'contract_type' => ContractType::Musharakah]);
    $contract = activeContract($project);
    $inv = makeInvestor(80000000);
    app(InvestmentService::class)->invest($inv, $project, Money::minor(70000000), 'msk');

    closeOut($contract->fresh(), 10000000);
    app(SettlementService::class)->settle($contract->fresh(), Money::minor(10000000), User::factory()->create());

    expect(bal($inv)['available'])->toBe(10000000 + 70000000 + 5000000)->and(bal($inv)['invested'])->toBe(0);
});

it('musharakah: loss follows capital ratio (investor bears 70%)', function () {
    $project = makeProject(['funding_target' => 70000000, 'contract_type' => ContractType::Musharakah]);
    $contract = activeContract($project);
    $inv = makeInvestor(70000000);
    app(InvestmentService::class)->invest($inv, $project, Money::minor(70000000), 'msk2');

    closeOut($contract->fresh(), -10000000);
    app(SettlementService::class)->settle($contract->fresh(), Money::minor(-10000000), User::factory()->create());
    expect(bal($inv)['available'])->toBe(70000000 - 7000000);
});

/** Murabaha fixture: contract with an asset, stage REQUESTED. */
function murabahaContract(): array
{
    $project = makeProject(['funding_target' => 10000000, 'contract_type' => ContractType::Murabaha, 'status' => ProjectStatus::Approved]);
    $contract = activeContract($project);
    $contract->forceFill(['status' => ContractStatus::Approved])->save();
    $m = $contract->murabaha;
    $m->assets()->create(['name' => 'Refrigeration units', 'supplier_name' => 'Supplier Ltd', 'quantity' => 4, 'unit_cost' => 2500000]);

    return [$contract, $m, User::factory()->create()];
}

it('murabaha: steps must follow request -> verify -> purchase -> own -> possess -> sell', function () {
    [, $m, $admin] = murabahaContract();
    $svc = app(MurabahaService::class);

    expect(fn () => $svc->recordPurchase($m, Money::minor(10000000), 'INV-1', now(), $admin))->toThrow(FinancialException::class, 'in order');
    $svc->verifySupplierAndAsset($m, $admin);
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV-1', now(), $admin);
    expect(fn () => $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin))->toThrow(FinancialException::class, 'in possession');
    $svc->recordOwnership($m->fresh(), now(), $admin);
    expect(fn () => $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin))->toThrow(FinancialException::class, 'in possession');
    $svc->recordPossession($m->fresh(), now(), 'Inspected and held', $admin);
    $receivable = $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin);

    expect($receivable->total_amount)->toBe(11000000)->and($receivable->schedules()->count())->toBe(4)
        ->and((int) $receivable->schedules()->sum('amount'))->toBe(11000000)
        ->and($m->fresh()->stage)->toBe(MurabahaStage::Sold);
});

it('murabaha: purchase must match approved cost and verification needs an asset', function () {
    [, $m, $admin] = murabahaContract();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    expect(fn () => $svc->recordPurchase($m->fresh(), Money::minor(9000000), 'INV', now(), $admin))->toThrow(FinancialException::class, 'approved purchase cost');

    [, $empty, $admin2] = murabahaContract();
    MurabahaAsset::where('murabaha_contract_id', $empty->id)->delete();
    expect(fn () => $svc->verifySupplierAndAsset($empty, $admin2))->toThrow(FinancialException::class, 'asset');
});

function soldMurabaha(): array
{
    [$contract, $m, $admin] = murabahaContract();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV-1', now(), $admin);
    $svc->recordOwnership($m->fresh(), now(), $admin);
    $svc->recordPossession($m->fresh(), now(), 'ok', $admin);

    return [$svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin), $contract, $svc];
}

it('murabaha: payments reduce the outstanding receivable and are idempotent', function () {
    [$r, , $svc] = soldMurabaha();
    $svc->recordPayment($r, Money::minor(2750000), 'p1', now());
    $svc->recordPayment($r, Money::minor(2750000), 'p1', now()); // duplicate

    $r->refresh();
    expect($r->paid_amount)->toBe(2750000)->and($r->outstanding())->toBe(8250000)->and($r->status)->toBe(PaymentStatus::Partial)
        ->and($r->schedules()->orderBy('sequence')->first()->status)->toBe(PaymentStatus::Paid);
});

it('murabaha: overpayment is refused and full payment settles with sale profit kept separate', function () {
    [$r, $contract, $svc] = soldMurabaha();
    expect(fn () => $svc->recordPayment($r, Money::minor(11000001), 'over', now()))->toThrow(FinancialException::class, 'exceeds');

    $svc->recordPayment($r, Money::minor(5500000), 'a', now());
    $svc->recordPayment($r->fresh(), Money::minor(5500000), 'b', now());

    $s = $contract->fresh()->settlements()->with('items')->first();
    expect($r->fresh()->status)->toBe(PaymentStatus::Paid)->and($contract->fresh()->status)->toBe(ContractStatus::Completed)
        ->and($s->items->firstWhere('item_type', SettlementItemType::MurabahaSaleProfit)->amount)->toBe(1000000)
        ->and($s->items->firstWhere('item_type', SettlementItemType::Principal)->amount)->toBe(10000000);
});

it('murabaha: overdue installments are flagged', function () {
    [$r, , $svc] = soldMurabaha();
    expect($svc->markOverdue(now()->addMonths(2)))->toBeGreaterThan(0)
        ->and($r->schedules()->where('status', PaymentStatus::Overdue)->exists())->toBeTrue();
});

it('murabaha contracts cannot be invested in like a pool or settled by profit-share', function () {
    [$contract] = murabahaContract();
    expect(fn () => app(SettlementService::class)->settle($contract, Money::minor(1), User::factory()->create()))->toThrow(FinancialException::class);
    expect(fn () => app(InvestmentService::class)->invest(makeInvestor(10000000), makeProject(['contract_type' => ContractType::Murabaha]), Money::minor(1000000), 'mrb'))->toThrow(FinancialException::class, 'not an investment');
});

/* ------------------------------ Project workflow ------------------------------ */

it('project workflow: submit -> shariah review -> approve -> publish, all audited', function () {
    $project = makeProject(['status' => ProjectStatus::Draft]);
    $contract = activeContract($project);
    $contract->forceFill(['status' => ContractStatus::Draft])->save();
    $admin = User::factory()->create();
    $wf = app(ProjectWorkflow::class);

    $wf->submit($project->fresh(), $admin);
    expect(fn () => $wf->publish($project->fresh(), $admin))->toThrow(FinancialException::class);   // not approved yet

    $wf->approve($project->fresh(), $admin);
    expect(fn () => $wf->publish($project->fresh(), $admin))->toThrow(FinancialException::class, 'Shariah');

    $wf->recordShariahReview($project->fresh(), $admin, ShariahReviewStatus::Approved, 'Structure reviewed');
    $wf->publish($project->fresh(), $admin);

    expect($project->fresh()->status)->toBe(ProjectStatus::Funding)->and($project->fresh()->published_at)->not->toBeNull();
    expect(AuditLog::whereIn('action', ['project.submit', 'project.approve', 'project.publish', 'shariah.approved'])->count())->toBe(4);
});

it('project workflow: unverified business cannot submit; invalid transitions are refused', function () {
    $project = makeProject(['status' => ProjectStatus::Draft]);
    activeContract($project);
    $project->business->forceFill(['kyc_status' => KycStatus::Pending])->save();
    $wf = app(ProjectWorkflow::class);
    expect(fn () => $wf->submit($project->fresh(), User::factory()->create()))->toThrow(FinancialException::class, 'verified');
    expect(fn () => $wf->approve($project->fresh(), User::factory()->create()))->toThrow(FinancialException::class, 'current status');
});

it('project workflow: funded projects cannot be cancelled', function () {
    [, , , $project] = fundedMudarabah();
    expect(fn () => app(ProjectWorkflow::class)->cancel($project, User::factory()->create(), 'x'))->toThrow(FinancialException::class, 'refund');
});
