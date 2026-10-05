<?php

use App\Enums\EntryDirection;
use App\Enums\LedgerAccountType;
use App\Enums\TransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Deposit;
use App\Models\Investment;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\Murabaha\MurabahaService;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;

const CONFLICT = 'Idempotency key has already been used for a different request.';

it('deposits: same key + same request returns the original; a different request is rejected', function () {
    $u = makeInvestor()->user;
    $svc = app(WalletService::class);
    $a = $svc->requestDeposit($u, Money::minor(500000), 'dep-key', 'REF-1');
    expect($svc->requestDeposit($u, Money::minor(500000), 'dep-key', 'REF-1')->id)->toBe($a->id)->and($a->request_hash)->not->toBeNull();
    expect(fn () => $svc->requestDeposit($u, Money::minor(900000), 'dep-key', 'REF-1'))->toThrow(IdempotencyConflictException::class, CONFLICT);
    expect(Deposit::count())->toBe(1);
});

it('deposits: a different user cannot reuse someone else\'s key', function () {
    $svc = app(WalletService::class);
    $svc->requestDeposit(makeInvestor()->user, Money::minor(500000), 'shared-key');
    expect(fn () => $svc->requestDeposit(makeInvestor()->user, Money::minor(500000), 'shared-key'))->toThrow(IdempotencyConflictException::class);
});

it('withdrawals: same key + same request is safe; different amount is rejected', function () {
    $u = makeInvestor(10000000)->user;
    $svc = app(WalletService::class);
    $w = $svc->requestWithdrawal($u, Money::minor(200000), 'wd-key');
    expect($svc->requestWithdrawal($u, Money::minor(200000), 'wd-key')->id)->toBe($w->id);
    expect(fn () => $svc->requestWithdrawal($u, Money::minor(300000), 'wd-key'))->toThrow(IdempotencyConflictException::class, CONFLICT);
    $b = $svc->balances($svc->walletFor($u));
    expect(Withdrawal::count())->toBe(1)->and($b['pending']->minor)->toBe(200000);   // funds were held exactly once
});

it('investments: same key + same request is safe; different amount or project is rejected', function () {
    $inv = makeInvestor(10000000);
    $p1 = makeProject();
    $p2 = makeProject();
    $svc = app(InvestmentService::class);
    $a = $svc->invest($inv, $p1, Money::minor(1000000), 'inv-key');
    expect($svc->invest($inv, $p1, Money::minor(1000000), 'inv-key')->id)->toBe($a->id);
    expect(fn () => $svc->invest($inv, $p1, Money::minor(2000000), 'inv-key'))->toThrow(IdempotencyConflictException::class, CONFLICT);
    expect(fn () => $svc->invest($inv, $p2, Money::minor(1000000), 'inv-key'))->toThrow(IdempotencyConflictException::class);
    expect(Investment::count())->toBe(1)->and($p1->fresh()->funded_amount)->toBe(1000000)->and($p2->fresh()->funded_amount)->toBe(0);
});

it('different keys are separate operations', function () {
    $inv = makeInvestor(10000000);
    $p = makeProject();
    $svc = app(InvestmentService::class);
    $svc->invest($inv, $p, Money::minor(1000000), 'sep-1');
    $svc->invest($inv, $p, Money::minor(1000000), 'sep-2');
    expect(Investment::count())->toBe(2)->and($p->fresh()->funded_amount)->toBe(2000000);
});

it('ledger: same key + same legs returns the original; different legs are rejected', function () {
    $ledger = app(LedgerService::class);
    $cash = $ledger->systemAccount(LedgerAccountType::PlatformCash);
    $fees = $ledger->systemAccount(LedgerAccountType::PlatformFees);
    $legs = fn (int $n) => [
        ['account' => $cash, 'direction' => EntryDirection::Debit, 'amount' => Money::minor($n)],
        ['account' => $fees, 'direction' => EntryDirection::Credit, 'amount' => Money::minor($n)],
    ];
    $a = $ledger->post(TransactionType::Adjustment, $legs(500), 'led-key');
    expect($ledger->post(TransactionType::Adjustment, $legs(500), 'led-key')->id)->toBe($a->id);
    expect(fn () => $ledger->post(TransactionType::Adjustment, $legs(700), 'led-key'))->toThrow(IdempotencyConflictException::class, CONFLICT);
    expect(Transaction::count())->toBe(1)->and($cash->fresh()->balance)->toBe(500);
});

it('murabaha payments: same key + same request is safe; different amount is rejected', function () {
    [$contract, $m, $admin] = murabahaFixture();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV', now(), $admin);
    $svc->recordOwnership($m->fresh(), now(), $admin);
    $svc->recordPossession($m->fresh(), now(), 'held', $admin);
    $r = $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin);

    $p = $svc->recordPayment($r, Money::minor(2750000), 'pay-key', now());
    expect($svc->recordPayment($r->fresh(), Money::minor(2750000), 'pay-key', now())->id)->toBe($p->id);
    expect(fn () => $svc->recordPayment($r->fresh(), Money::minor(1000000), 'pay-key', now()))->toThrow(IdempotencyConflictException::class, CONFLICT);
    expect(Payment::count())->toBe(1)->and($r->fresh()->paid_amount)->toBe(2750000);
});

it('settlements: same key + same request returns the original; a different result is rejected', function () {
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    app(InvestmentService::class)->invest(makeInvestor(20000000), $project, Money::minor(10000000), 'set-inv');
    $svc = app(SettlementService::class);
    $admin = User::factory()->create();

    closeOut($contract->fresh(), 2000000);
    $s = $svc->settle($contract->fresh(), Money::minor(2000000), $admin, false, 'audited', 'settle-key');
    expect($svc->settle($contract->fresh(), Money::minor(2000000), $admin, false, 'audited', 'settle-key')->id)->toBe($s->id);
    expect(fn () => $svc->settle($contract->fresh(), Money::minor(5000000), $admin, false, 'audited', 'settle-key'))->toThrow(IdempotencyConflictException::class, CONFLICT);
    expect(Settlement::count())->toBe(1);
    expect(fn () => $svc->settle($contract->fresh(), Money::minor(2000000), $admin, false, 'again'))->toThrow(\App\Exceptions\FinancialException::class);   // keyless replay is still blocked by status
});

it('the database itself refuses a second settlement for a contract', function () {
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    app(InvestmentService::class)->invest(makeInvestor(20000000), $project, Money::minor(10000000), 'db-inv');
    closeOut($contract->fresh(), 100000);
    $s = app(SettlementService::class)->settle($contract->fresh(), Money::minor(100000), User::factory()->create());
    expect(fn () => \Illuminate\Support\Facades\DB::table('settlements')->insert(['reference' => 'DUP', 'contract_id' => $contract->id, 'project_id' => $project->id, 'status' => 'POSTED', 'currency' => 'BDT', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
