<?php

use App\Enums\ContractType;
use App\Enums\LedgerAccountType as A;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\CapitalDeployment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Contract\CapitalDeploymentService;
use App\Services\Contract\VentureRemittanceService;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\WalletService;
use App\Models\VentureRemittance;
use App\Support\Money\Money;

function custody(): int
{
    return (int) \App\Models\LedgerAccount::where('type', A::CustodyCash)->value('balance');
}

it('21. an investment commits capital with one INVESTMENT posting and moves no cash out of custody', function () {
    $project = realProject(ContractType::Mudarabah);
    $investor = makeInvestor(20000000);
    $before = custody();
    $inv = fund($investor, $project, 4000000);

    expect(Transaction::where('investment_id', $inv->id)->where('type', TransactionType::Investment)->count())->toBe(1)
        ->and(Transaction::whereIn('type', [TransactionType::ProjectFunding, TransactionType::CapitalDeployment])->count())->toBe(0)
        ->and(custody())->toBe($before)->and(pool(A::VentureCapital, $project->id))->toBe(0)->and(pool(A::ProjectFunds, $project->id))->toBe(0);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('22. capital is not double counted: the investor wallet is debited once', function () {
    $project = realProject(ContractType::Mudarabah);
    $investor = makeInvestor(20000000);
    fund($investor, $project, 4000000);
    $w = app(WalletService::class);
    $b = $w->balances($w->walletFor($investor->user));

    expect($b['available']->minor)->toBe(16000000)->and($b['invested']->minor)->toBe(4000000);   // 20,000,000 total preserved
});

it('23. deployment moves exactly the committed capital from custody into the venture, once, and only for an active contract', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 4000000);
    $contract = $project->contract->fresh();
    $svc = app(CapitalDeploymentService::class);
    $by = User::factory()->create();

    expect(fn () => $svc->deploy($contract, Money::minor(4000000), 'REF-1', 'k1', $by))->toThrow(FinancialException::class, 'active contract');   // still funding

    fund(makeInvestor(20000000), $project, 6000000);
    $contract = $project->contract->fresh();
    expect(fn () => $svc->deploy($contract, Money::minor(9000000), 'REF-1', 'k2', $by))->toThrow(FinancialException::class, 'committed capital')
        ->and(fn () => $svc->deploy($contract, Money::minor(10000000), '  ', 'k3', $by))->toThrow(FinancialException::class, 'delivery reference');

    $custodyBefore = custody();
    $d = $svc->deploy($contract, Money::minor(10000000), 'REF-1', 'k4', $by);
    expect($d->amount)->toBe(10000000)->and(custody())->toBe($custodyBefore - 10000000)->and(pool(A::VentureCapital, $project->id))->toBe(10000000);
    expect($svc->deploy($contract, Money::minor(10000000), 'REF-1', 'k4', $by)->id)->toBe($d->id);                      // idempotent replay
    expect(fn () => $svc->deploy($contract, Money::minor(10000000), 'REF-2', 'k5', $by))->toThrow(FinancialException::class, 'already been deployed');
    expect(Transaction::where('type', TransactionType::CapitalDeployment)->count())->toBe(1);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('24. funded_amount always reconciles with the investments, and a corrupted value is reported with the project id', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 4000000);
    fund(makeInvestor(20000000), $project, 3000000);
    expect($project->fresh()->funded_amount)->toBe(7000000);
    expect(reconcile(true)['passed'])->toBeTrue();

    corrupt('projects', ['id' => $project->id], ['funded_amount' => 9000000]);
    $r = reconcile();
    expect($r['passed'])->toBeFalse()->and(implode(' ', $r['results']['Project Funding']->errors))->toContain((string) $project->id);
});

it('remittances need a deployment; returned capital cannot exceed what was deployed; interim proceeds are held separately', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $contract = $project->contract->fresh();
    $svc = app(VentureRemittanceService::class);
    $by = User::factory()->create();

    expect(fn () => $svc->record($contract, VentureRemittance::INTERIM_PROCEEDS, Money::minor(100), 'R', 'r0', $by))->toThrow(FinancialException::class, 'not been deployed');
    deployCapital($contract);
    expect(fn () => $svc->record($contract, VentureRemittance::CAPITAL_RETURN, Money::minor(10000001), 'R', 'r1', $by))->toThrow(FinancialException::class, 'venture capital cannot go below zero');
    expect(fn () => $svc->record($contract, 'PROFIT', Money::minor(100), 'R', 'r2', $by))->toThrow(FinancialException::class, 'returned capital or interim proceeds');

    $svc->record($contract, VentureRemittance::INTERIM_PROCEEDS, Money::minor(500000), 'R', 'r3', $by);
    $svc->record($contract, VentureRemittance::CAPITAL_RETURN, Money::minor(4000000), 'R', 'r4', $by);
    expect(pool(A::ProjectFunds, $project->id))->toBe(500000)->and(pool(A::VentureCapital, $project->id))->toBe(6000000);
    // Idempotent replay posts nothing; the same key with a different amount is a conflict.
    $n = Transaction::count();
    $svc->record($contract, VentureRemittance::INTERIM_PROCEEDS, Money::minor(500000), 'R', 'r3', $by);
    expect(Transaction::count())->toBe($n);
    expect(fn () => $svc->record($contract, VentureRemittance::INTERIM_PROCEEDS, Money::minor(600000), 'R', 'r3', $by))->toThrow(\App\Exceptions\IdempotencyConflictException::class);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('settlement refuses a result that the recorded remittances do not imply, and a profit before the capital is back', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $contract = $project->contract->fresh();
    $svc = app(SettlementService::class);
    $admin = User::factory()->create();

    expect(fn () => $svc->settle($contract, Money::minor(2000000), $admin))->toThrow(FinancialException::class, 'not been deployed');
    deployCapital($contract);
    returnCapital($contract, 10000000);
    expect(fn () => $svc->settle($contract->fresh(), Money::minor(2000000), $admin))->toThrow(FinancialException::class, 'imply a result of BDT 0.00');
    remit($contract, 2000000);
    expect(fn () => $svc->settle($contract->fresh(), Money::minor(3000000), $admin))->toThrow(FinancialException::class, 'imply a result');
    expect(\App\Models\Settlement::count())->toBe(0)->and(pool(A::VentureCapital, $project->id))->toBe(0);
});

it('a settled project leaves nothing behind: venture capital, interim proceeds, capital at risk and Clearing are all zero', function () {
    $project = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(20000000);
    fund($inv, $project, 10000000);
    $contract = $project->contract->fresh();
    settleNow($contract, 2000000);

    expect(pool(A::VentureCapital, $project->id))->toBe(0)->and(pool(A::ProjectFunds, $project->id))->toBe(0)
        ->and((int) \App\Models\LedgerAccount::where('type', A::InvestorInvested)->sum('balance'))->toBe(0)
        ->and((int) \App\Models\LedgerAccount::where('type', A::Clearing)->sum('balance'))->toBe(0)
        ->and((int) \App\Models\LedgerAccount::where('type', A::PlatformCash)->sum('balance'))->toBe(0);   // platform money untouched
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('an unexplained Clearing balance is reported with its account id', function () {
    $project = realProject(ContractType::Mudarabah);
    $acc = app(\App\Services\Ledger\LedgerService::class)->systemAccount(A::Clearing, 'BDT', $project->id);
    corrupt('ledger_accounts', ['id' => $acc->id], ['balance' => 500]);
    $r = reconcile();
    expect($r['passed'])->toBeFalse()->and(implode(' ', $r['results']['Project Funding']->errors))->toContain('Clearing')->toContain((string) $acc->id);
});

it('platform own funds are only touched by Murabaha events', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    expect((int) \App\Models\LedgerAccount::where('type', A::PlatformCash)->count())->toBe(0);
    settleNow($project->contract->fresh(), -1000000);
    expect((int) \App\Models\LedgerAccount::where('type', A::PlatformCash)->count())->toBe(0);
});
