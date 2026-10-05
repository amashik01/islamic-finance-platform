<?php

use App\Enums\EntryDirection;
use App\Enums\LedgerAccountType as A;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Services\Ledger\LedgerService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

it('wallet balances cannot be edited by hand', function () {
    $inv = makeInvestor(1000000);
    $account = LedgerAccount::where('type', A::InvestorAvailable)->first();
    $account->update(['balance' => 99999999]);   // mass assignment of a guarded column is ignored
    expect($account->fresh()->balance)->toBe(1000000);
    expect(fn () => $account->forceFill(['balance' => 5])->save())->toThrow(LogicException::class)   // direct writes are refused
        ->and(fn () => LedgerAccount::whereKey($account->id)->first()->setAttribute('balance', 7)->save())->toThrow(LogicException::class);
    expect($account->fresh()->balance)->toBe(1000000);
});

it('a failed multi-leg transaction rolls back completely', function () {
    $inv = makeInvestor(1000000);
    $wallets = app(WalletService::class);
    $w = $wallets->walletFor($inv->user);
    $available = $wallets->account($w, A::InvestorAvailable);
    $invested = $wallets->account($w, A::InvestorInvested);
    $before = [Transaction::count(), LedgerEntry::count(), $available->fresh()->balance, $invested->fresh()->balance];

    // First leg is fine (credits invested), second would overdraw available => the whole posting must vanish.
    expect(fn () => app(LedgerService::class)->post(TransactionType::Investment, [
        ['account' => $invested, 'direction' => EntryDirection::Credit, 'amount' => Money::minor(5000000)],
        ['account' => $available, 'direction' => EntryDirection::Debit, 'amount' => Money::minor(5000000)],
    ]))->toThrow(FinancialException::class, 'Insufficient');

    expect([Transaction::count(), LedgerEntry::count(), $available->fresh()->balance, $invested->fresh()->balance])->toBe($before);
});

it('wallet balance never goes negative across sequential overspends', function () {
    $inv = makeInvestor(1000000);   // BDT 10,000
    $project = makeProject();
    $svc = app(InvestmentService::class);
    $wallets = app(WalletService::class);
    fund($inv, $project, 600000, 'ns-1');
    foreach (['ns-2', 'ns-3'] as $k) {
        expect(fn () => fund($inv, $project, 600000, $k))->toThrow(FinancialException::class, 'Insufficient');
    }
    expect(fn () => $wallets->requestWithdrawal($inv->user, Money::minor(500000), 'ns-w'))->toThrow(FinancialException::class);
    $b = $wallets->balances($wallets->walletFor($inv->user));
    expect($b['available']->minor)->toBe(400000)->and($b['available']->minor)->toBeGreaterThanOrEqual(0);
});

it('investment and withdrawal cannot both spend the same balance', function () {
    $inv = makeInvestor(1000000);   // BDT 10,000
    $project = makeProject();
    $wallets = app(WalletService::class);
    $wallets->requestWithdrawal($inv->user, Money::minor(800000), 'race-w');
    expect(fn () => fund($inv, $project, 800000, 'race-i'))->toThrow(FinancialException::class, 'Insufficient');
    $b = $wallets->balances($wallets->walletFor($inv->user));
    expect($b['available']->minor)->toBe(200000)->and($b['pending']->minor)->toBe(800000);
});

it('ledger history is immutable and corrected only by reversal', function () {
    makeInvestor(1000000);
    $tx = Transaction::first();
    $entry = LedgerEntry::first();
    expect(fn () => $tx->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $entry->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('every posted transaction balances across a realistic flow', function () {
    $inv = makeInvestor(5000000);
    fund($inv, makeProject(), 1000000, 'bal-1');
    app(WalletService::class)->requestWithdrawal($inv->user, Money::minor(200000), 'bal-w');
    foreach (Transaction::with('entries')->get() as $t) {
        expect($t->entries->where('direction', EntryDirection::Debit)->sum('amount'))->toBe($t->entries->where('direction', EntryDirection::Credit)->sum('amount'));
    }
    expect(reconcile()['passed'])->toBeTrue();
});
