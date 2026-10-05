<?php

use App\Enums\DepositStatus;
use App\Enums\EntryDirection;
use App\Enums\InvestmentStatus;
use App\Enums\LedgerAccountType;
use App\Enums\ProjectStatus;
use App\Enums\TransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

const HUNDRED_K = 10000000;

function wallets(): WalletService
{
    return app(WalletService::class);
}

it('deposit stays pending until verified, then becomes available', function () {
    $investor = makeInvestor();
    $admin = User::factory()->create();
    $dep = wallets()->requestDeposit($investor->user, Money::minor(HUNDRED_K), 'k1');
    expect(wallets()->balances(wallets()->walletFor($investor->user))['available']->minor)->toBe(0);

    wallets()->verifyDeposit($dep, $admin);
    $b = wallets()->balances(wallets()->walletFor($investor->user));
    expect($b['available']->minor)->toBe(HUNDRED_K)->and($dep->fresh()->status)->toBe(DepositStatus::Verified);
});

it('does not double-credit a deposit when verified twice', function () {
    $investor = makeInvestor();
    $dep = wallets()->requestDeposit($investor->user, Money::minor(HUNDRED_K), 'k2');
    wallets()->verifyDeposit($dep, User::factory()->create());
    wallets()->verifyDeposit($dep->fresh(), User::factory()->create());
})->throws(FinancialException::class);

it('deposit requests are idempotent', function () {
    $investor = makeInvestor();
    wallets()->requestDeposit($investor->user, Money::minor(100000), 'same');
    wallets()->requestDeposit($investor->user, Money::minor(100000), 'same');
    expect(App\Models\Deposit::count())->toBe(1);
});

it('moves available balance into invested balance and updates project funding', function () {
    $investor = makeInvestor(HUNDRED_K);
    $project = makeProject();
    $inv = app(InvestmentService::class)->invest($investor, $project, Money::minor(6000000), 'inv-1');

    $b = wallets()->balances(wallets()->walletFor($investor->user));
    expect($b['available']->minor)->toBe(4000000)->and($b['invested']->minor)->toBe(6000000)
        ->and($inv->status)->toBe(InvestmentStatus::Confirmed)
        ->and($project->fresh()->funded_amount)->toBe(6000000);
});

it('confirming an investment twice creates only one investment', function () {
    $investor = makeInvestor(HUNDRED_K);
    $project = makeProject();
    $svc = app(InvestmentService::class);
    $svc->invest($investor, $project, Money::minor(1000000), 'dup');
    $svc->invest($investor, $project, Money::minor(1000000), 'dup');

    expect(Investment::count())->toBe(1)
        ->and(wallets()->balances(wallets()->walletFor($investor->user))['available']->minor)->toBe(9000000);
});

it('prevents the available balance from going negative across competing investments', function () {
    $investor = makeInvestor(1000000); // BDT 10,000
    $project = makeProject();
    $svc = app(InvestmentService::class);
    $svc->invest($investor, $project, Money::minor(800000), 'a');

    expect(fn () => $svc->invest($investor, $project, Money::minor(800000), 'b'))
        ->toThrow(FinancialException::class, 'Insufficient available balance.');

    $b = wallets()->balances(wallets()->walletFor($investor->user));
    expect($b['available']->minor)->toBe(200000)->and(Investment::count())->toBe(1)
        ->and($project->fresh()->funded_amount)->toBe(800000); // rolled back with the failed attempt
});

it('rejects investing in a project that is not funding or over capacity or unverified', function () {
    $investor = makeInvestor(HUNDRED_K);
    $svc = app(InvestmentService::class);

    expect(fn () => $svc->invest($investor, makeProject(['status' => ProjectStatus::Active]), Money::minor(1000000), 'x1'))
        ->toThrow(FinancialException::class, 'no longer accepting');
    expect(fn () => $svc->invest($investor, makeProject(['funding_target' => 1000000]), Money::minor(2000000), 'x2'))
        ->toThrow(FinancialException::class, 'remaining funding capacity');
    expect(fn () => $svc->invest(makeInvestor(HUNDRED_K, verified: false), makeProject(), Money::minor(1000000), 'x3'))
        ->toThrow(FinancialException::class, 'verification');
});

it('fully funding a project activates it', function () {
    $investor = makeInvestor(HUNDRED_K);
    $project = makeProject(['funding_target' => 2000000]);
    app(InvestmentService::class)->invest($investor, $project, Money::minor(2000000), 'full');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

it('withdrawal reserves funds and follows the review workflow to paid', function () {
    $investor = makeInvestor(HUNDRED_K);
    $admin = User::factory()->create();
    $w = wallets()->requestWithdrawal($investor->user, Money::minor(2000000), 'w1');
    $b = wallets()->balances(wallets()->walletFor($investor->user));
    expect($b['available']->minor)->toBe(8000000)->and($b['pending']->minor)->toBe(2000000);

    foreach ([WithdrawalStatus::UnderReview, WithdrawalStatus::Approved, WithdrawalStatus::Processing, WithdrawalStatus::Paid] as $s) {
        wallets()->advanceWithdrawal($w, $s, $admin);
    }
    $b = wallets()->balances(wallets()->walletFor($investor->user));
    expect($b['pending']->minor)->toBe(0)->and($b['available']->minor)->toBe(8000000);
});

it('releases reserved funds when a withdrawal is rejected and blocks illegal transitions', function () {
    $investor = makeInvestor(HUNDRED_K);
    $admin = User::factory()->create();
    $w = wallets()->requestWithdrawal($investor->user, Money::minor(2000000), 'w2');
    wallets()->advanceWithdrawal($w, WithdrawalStatus::Rejected, $admin, 'Bank details mismatch');
    expect(wallets()->balances(wallets()->walletFor($investor->user))['available']->minor)->toBe(HUNDRED_K);
    expect(fn () => wallets()->advanceWithdrawal($w->fresh(), WithdrawalStatus::Paid, $admin))->toThrow(FinancialException::class);
});

it('validates withdrawal limits, balance and verification', function () {
    $investor = makeInvestor(HUNDRED_K);
    expect(fn () => wallets()->requestWithdrawal($investor->user, Money::minor(100), 'm1'))->toThrow(FinancialException::class, 'minimum');
    expect(fn () => wallets()->requestWithdrawal($investor->user, Money::minor(HUNDRED_K + 100000), 'm2'))->toThrow(FinancialException::class, 'Insufficient');
    expect(fn () => wallets()->requestWithdrawal(makeInvestor(HUNDRED_K, false)->user, Money::minor(200000), 'm3'))->toThrow(FinancialException::class, 'verification');
});

it('ledger records are immutable and entries always balance', function () {
    makeInvestor(HUNDRED_K);
    $tx = Transaction::first();
    expect(fn () => $tx->update(['amount' => 1]))->toThrow(LogicException::class);
    expect(fn () => $tx->delete())->toThrow(LogicException::class);
    $entry = LedgerEntry::first();
    expect(fn () => $entry->update(['amount' => 1]))->toThrow(LogicException::class);

    foreach (Transaction::with('entries')->get() as $t) {
        $d = $t->entries->where('direction', EntryDirection::Debit)->sum('amount');
        $c = $t->entries->where('direction', EntryDirection::Credit)->sum('amount');
        expect($d)->toBe($c);
    }
});

it('rejects unbalanced transactions and rolls back partial writes', function () {
    $ledger = app(LedgerService::class);
    $cash = $ledger->systemAccount(LedgerAccountType::PlatformCash);
    $fees = $ledger->systemAccount(LedgerAccountType::PlatformFees);
    expect(fn () => $ledger->post(TransactionType::Adjustment, [
        ['account' => $cash, 'direction' => EntryDirection::Debit, 'amount' => Money::minor(100)],
        ['account' => $fees, 'direction' => EntryDirection::Credit, 'amount' => Money::minor(90)],
    ]))->toThrow(FinancialException::class);
    expect(Transaction::count())->toBe(0)->and(LedgerEntry::count())->toBe(0);
});

it('reverses a transaction with opposite entries instead of editing history', function () {
    $investor = makeInvestor(HUNDRED_K);
    $tx = Transaction::where('type', TransactionType::Deposit)->first();
    // Move funds out first so the reversal cannot overdraw; then reverse a fee adjustment instead.
    $ledger = app(LedgerService::class);
    $cash = $ledger->systemAccount(LedgerAccountType::PlatformCash);
    $fees = $ledger->systemAccount(LedgerAccountType::PlatformFees);
    $adj = $ledger->post(TransactionType::Adjustment, [
        ['account' => $cash, 'direction' => EntryDirection::Debit, 'amount' => Money::minor(500)],
        ['account' => $fees, 'direction' => EntryDirection::Credit, 'amount' => Money::minor(500)],
    ], 'adj-1');
    $rev = $ledger->reverse($adj, 'Posted in error');

    expect($rev->reverses_transaction_id)->toBe($adj->id)->and($adj->fresh()->status->value)->toBe('REVERSED')
        ->and($ledger->recomputeBalance($cash->fresh()))->toBe($cash->fresh()->balance);
    expect(fn () => $ledger->reverse($adj->fresh(), 'again'))->toThrow(FinancialException::class);
});

it('cached balances always equal balances recomputed from entries', function () {
    $investor = makeInvestor(HUNDRED_K);
    app(InvestmentService::class)->invest($investor, makeProject(), Money::minor(3000000), 'rc');
    $ledger = app(LedgerService::class);
    foreach (App\Models\LedgerAccount::all() as $acct) {
        expect($ledger->recomputeBalance($acct))->toBe($acct->balance);
    }
});
