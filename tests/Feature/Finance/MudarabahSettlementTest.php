<?php

use App\Enums\ContractStatus;
use App\Enums\LedgerAccountType as A;
use App\Enums\ManagerRecoveryStatus;
use App\Enums\RecoveryStatus;
use App\Enums\SettlementItemType as Item;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Models\LedgerAccount;
use App\Models\ManagerRecovery;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

/** Two investors fund a BDT 100,000 Mudarabah (60k + 40k), 70/30 profit ratio. */
function mudarabahFixture(): array
{
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    $a = makeInvestor(20000000);
    $b = makeInvestor(20000000);
    $svc = app(InvestmentService::class);
    $svc->invest($a, $project, Money::minor(6000000), 'md-a');
    $svc->invest($b, $project, Money::minor(4000000), 'md-b');

    return [$contract->fresh(), $a, $b, $project->fresh()];
}

function acct(string $type, ?int $projectId): int
{
    return (int) LedgerAccount::where('type', $type)->where('project_id', $projectId)->value('balance');
}

it('records investor principal, investor profit AND the business profit share as separate items and ledger entries', function () {
    [$contract, $a, $b, $project] = mudarabahFixture();
    $s = app(SettlementService::class)->settle($contract, Money::minor(2000000), User::factory()->create());

    $sum = fn (Item $t) => (int) $s->items->where('item_type', $t)->sum('amount');
    expect($sum(Item::Principal))->toBe(10000000)->and($sum(Item::InvestmentProfit))->toBe(1400000)->and($sum(Item::BusinessProfitShare))->toBe(600000)
        ->and($sum(Item::InvestmentProfit) + $sum(Item::BusinessProfitShare))->toBe(2000000)   // = actual profit, nothing dropped
        ->and($s->items->where('item_type', Item::ManagerLiability)->count())->toBe(0);

    $business = $s->items->firstWhere('item_type', Item::BusinessProfitShare);
    expect($business->user_id)->toBe($project->business->user_id)->and($business->transaction_id)->not->toBeNull();
    // Business funds hold its share; project funds were debited by the whole profit.
    expect(acct(A::BusinessFunds->value, $project->id))->toBe(600000)->and(acct(A::ProjectFunds->value, $project->id))->toBe(-2000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('returns principal and profit pro rata to investors', function () {
    [$contract, $a, $b] = mudarabahFixture();
    app(SettlementService::class)->settle($contract, Money::minor(2000000), User::factory()->create());
    $w = app(WalletService::class);
    expect($w->balances($w->walletFor($a->user))['available']->minor)->toBe(14000000 + 6000000 + 840000)
        ->and($w->balances($w->walletFor($b->user))['available']->minor)->toBe(16000000 + 4000000 + 560000)
        ->and($w->balances($w->walletFor($a->user))['invested']->minor)->toBe(0);
});

it('ordinary business loss falls on capital and creates no manager liability or recovery', function () {
    [$contract, $a, $b, $project] = mudarabahFixture();
    $s = app(SettlementService::class)->settle($contract, Money::minor(-1000000), User::factory()->create());

    expect($s->items->where('item_type', Item::InvestmentProfit)->count())->toBe(0)->and($s->items->where('item_type', Item::BusinessProfitShare)->count())->toBe(0)
        ->and($s->items->where('item_type', Item::ManagerLiability)->count())->toBe(0)
        ->and(ManagerRecovery::count())->toBe(0)->and($contract->fresh()->recovery_status)->toBe(RecoveryStatus::None);
    $w = app(WalletService::class);
    expect($w->balances($w->walletFor($a->user))['available']->minor)->toBe(14000000 + 5400000);   // 10% loss borne by the investor
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('documented fault records the recoverable amount, not just a status', function () {
    [$contract, $a, $b, $project] = mudarabahFixture();
    $s = app(SettlementService::class)->settle($contract, Money::minor(-1000000), User::factory()->create(), managerAtFault: true, reason: 'Funds diverted to unrelated purchases (audit ref A-12)');

    $recovery = ManagerRecovery::first();
    expect($recovery->amount)->toBe(1000000)->and($recovery->recovered_amount)->toBe(0)->and($recovery->outstanding())->toBe(1000000)
        ->and($recovery->status)->toBe(ManagerRecoveryStatus::Open)->and($recovery->settlement_id)->toBe($s->id)->and($recovery->business_id)->toBe($project->business_id)
        ->and($recovery->reason)->toContain('diverted')->and($recovery->currency)->toBe('BDT');
    $liability = $s->items->firstWhere('item_type', Item::ManagerLiability);
    expect($liability->amount)->toBe(1000000)->and($contract->fresh()->recovery_status)->toBe(RecoveryStatus::InRecovery);
    expect(\App\Models\AuditLog::where('action', 'settlement.posted')->first()->new_values['manager_liability'])->toBe(1000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('refuses to attribute a loss to the manager without a documented reason', function () {
    [$contract] = mudarabahFixture();
    expect(fn () => app(SettlementService::class)->settle($contract, Money::minor(-1000000), User::factory()->create(), managerAtFault: true))
        ->toThrow(FinancialException::class, 'documented negligence');
    expect(ManagerRecovery::count())->toBe(0)->and($contract->fresh()->status)->toBe(ContractStatus::Active);   // nothing half-posted
});

it('a profitable settlement never creates a recovery even if fault is flagged', function () {
    [$contract] = mudarabahFixture();
    app(SettlementService::class)->settle($contract, Money::minor(2000000), User::factory()->create(), managerAtFault: true, reason: 'n/a');
    expect(ManagerRecovery::count())->toBe(0);
});

it('settlement rolls back entirely if any step fails', function () {
    [$contract, $a] = mudarabahFixture();
    // Corrupt: wipe the investor's invested bucket so the principal return would overdraw it.
    corrupt('ledger_accounts', ['type' => A::InvestorInvested->value], ['balance' => 0]);
    $txBefore = \App\Models\Transaction::count();
    expect(fn () => app(SettlementService::class)->settle($contract, Money::minor(2000000), User::factory()->create()))->toThrow(FinancialException::class);
    expect(\App\Models\Transaction::count())->toBe($txBefore)->and(\App\Models\Settlement::count())->toBe(0)->and($contract->fresh()->status)->toBe(ContractStatus::Active);
});
