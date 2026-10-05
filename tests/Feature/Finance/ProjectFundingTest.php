<?php

use App\Enums\ContractType;
use App\Enums\LedgerAccountType as A;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

it('21. an investment creates one claim leg and one PROJECT_FUNDING leg that moves capital into ProjectFunds', function () {
    $project = realProject(ContractType::Mudarabah);
    $investor = makeInvestor(20000000);
    $inv = fund($investor, $project, 4000000);

    expect(Transaction::where('investment_id', $inv->id)->where('type', TransactionType::Investment)->count())->toBe(1)
        ->and(Transaction::where('investment_id', $inv->id)->where('type', TransactionType::ProjectFunding)->count())->toBe(1)
        ->and(pool(A::ProjectFunds, $project->id))->toBe(4000000)
        ->and(pool(A::CapitalDeployed, $project->id))->toBe(4000000);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('22. capital is not double counted: the investor wallet is debited exactly once', function () {
    $project = realProject(ContractType::Mudarabah);
    $investor = makeInvestor(20000000);
    fund($investor, $project, 4000000);
    $b = app(WalletService::class)->balances(app(WalletService::class)->walletFor($investor->user));

    expect($b['available']->minor)->toBe(16000000)->and($b['invested']->minor)->toBe(4000000);   // 20,000,000 total preserved
    // Investor-side buckets hold 20,000,000 in total; the pool mirror is offset by CapitalDeployed (net zero for the platform).
    expect(pool(A::ProjectFunds, $project->id) - pool(A::CapitalDeployed, $project->id))->toBe(0);
});

it('23. ProjectFunds cannot be drawn below zero, so the pool cannot be over-distributed', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $contract = $project->contract->fresh();

    // Profit settlement without a business remittance would pay out money the pool does not hold.
    expect(fn () => app(SettlementService::class)->settle($contract, Money::minor(2000000), User::factory()->create()))->toThrow(FinancialException::class);
    expect(pool(A::ProjectFunds, $project->id))->toBe(10000000)
        ->and(\App\Models\Settlement::where('contract_id', $contract->id)->count())->toBe(0);
});

it('24. funded_amount always reconciles with the investments and their funding transactions', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 4000000);
    fund(makeInvestor(20000000), $project, 3000000);
    expect($project->fresh()->funded_amount)->toBe(7000000)->and(pool(A::ProjectFunds, $project->id))->toBe(7000000);
    expect(reconcile(true)['passed'])->toBeTrue();

    // Deliberately corrupt funded_amount -> reconciliation must name the project.
    corrupt('projects', ['id' => $project->id], ['funded_amount' => 9000000]);
    $r = reconcile();
    expect($r['passed'])->toBeFalse()
        ->and(implode(' ', $r['results']['Project Funding']->errors))->toContain((string) $project->id);
});

it('a settled project releases capital: ProjectFunds and CapitalDeployed return to zero', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $contract = $project->contract->fresh();
    remit($contract, 2000000);
    app(SettlementService::class)->settle($contract, Money::minor(2000000), User::factory()->create());

    expect(pool(A::ProjectFunds, $project->id))->toBe(0)->and(pool(A::CapitalDeployed, $project->id))->toBe(0);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('missing PROJECT_FUNDING transaction is detected by reconciliation with the investment id', function () {
    $project = realProject(ContractType::Mudarabah);
    $inv = fund(makeInvestor(20000000), $project, 4000000);
    $tx = Transaction::where('investment_id', $inv->id)->where('type', TransactionType::ProjectFunding)->first();
    corrupt('transactions', ['id' => $tx->id], ['type' => 'ADJUSTMENT']);
    $r = reconcile();
    expect($r['passed'])->toBeFalse()
        ->and(implode(' ', $r['results']['Project Funding']->errors))->toContain((string) $inv->id);
});
