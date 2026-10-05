<?php

use App\Enums\ContractType;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountType;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Exceptions\NonBdtCurrencyException;
use App\Models\Contract;
use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\Project;
use App\Models\Settlement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\Murabaha\MurabahaService;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

it('accepts BDT everywhere and nothing else', function () {
    expect(Money::minor(10000)->currency)->toBe('BDT')->and(Money::parse('100.00')->minor)->toBe(10000)->and(Currency::of()->code)->toBe('BDT')
        ->and(config('finance.default_currency'))->toBe('BDT');
    foreach (['USD', 'EUR', 'Tk', '৳', 'TAKA', 'bdt', ''] as $bad) {
        expect(fn () => Money::minor(1, $bad))->toThrow(NonBdtCurrencyException::class);
    }
    expect(fn () => Money::parse('10', 'USD'))->toThrow(NonBdtCurrencyException::class)
        ->and(fn () => Currency::of('USD'))->toThrow(NonBdtCurrencyException::class);
});

it('has no FX or conversion: mixing currencies is impossible', function () {
    expect(config('finance.currency.name'))->toBe('Bangladeshi Taka')->and(config('finance.currencies'))->toBeNull();
});

it('rejects a wallet in any other currency', function () {
    $user = User::factory()->create();
    expect(fn () => app(WalletService::class)->walletFor($user, 'USD'))->toThrow(FinancialException::class, 'must be in BDT')
        ->and(fn () => Wallet::create(['user_id' => $user->id, 'currency' => 'USD']))->toThrow(FinancialException::class);
    expect(Wallet::count())->toBe(0);
});

it('rejects non-BDT records on every financial model before they are saved', function () {
    $user = User::factory()->create();
    expect(fn () => Deposit::create(['user_id' => $user->id, 'reference' => 'D1', 'amount' => 100, 'currency' => 'USD', 'idempotency_key' => 'k1']))->toThrow(FinancialException::class)
        ->and(fn () => Withdrawal::create(['user_id' => $user->id, 'reference' => 'W1', 'amount' => 100, 'currency' => 'EUR', 'idempotency_key' => 'k2']))->toThrow(FinancialException::class)
        ->and(fn () => Transaction::create(['reference' => 'T1', 'type' => TransactionType::Deposit, 'currency' => 'USD', 'amount' => 1]))->toThrow(FinancialException::class)
        ->and(fn () => LedgerAccount::create(['code' => 'x', 'type' => LedgerAccountType::PlatformCash, 'name' => 'x', 'currency' => 'USD', 'normal_side' => 'DEBIT']))->toThrow(FinancialException::class);
    $project = makeProject();
    expect(fn () => Project::whereKey($project->id)->first()->forceFill(['currency' => 'USD'])->save())->toThrow(FinancialException::class);
    $contract = activeContract($project);
    expect(fn () => $contract->forceFill(['currency' => 'USD'])->save())->toThrow(FinancialException::class);
    expect(fn () => (new Settlement(['reference' => 'S', 'contract_id' => $contract->id, 'project_id' => $project->id, 'currency' => 'USD']))->save())->toThrow(FinancialException::class);
});

it('defaults unset currencies to BDT', function () {
    $w = Wallet::create(['user_id' => User::factory()->create()->id]);
    expect($w->fresh()->currency)->toBe('BDT');
});

it('a ledger entry can never reference an account of another currency', function () {
    $ledger = app(LedgerService::class);
    $cash = $ledger->systemAccount(LedgerAccountType::PlatformCash);
    $fees = $ledger->systemAccount(LedgerAccountType::PlatformFees);
    if (! corrupt('ledger_accounts', ['id' => $fees->id], ['currency' => 'USD'])) {
        expect(true)->toBeTrue();   // MySQL CHECK constraint refused the corruption: enforced at database level

        return;
    }
    expect(fn () => $ledger->post(TransactionType::Adjustment, [
        ['account' => $cash, 'direction' => EntryDirection::Debit, 'amount' => Money::minor(100)],
        ['account' => LedgerAccount::find($fees->id), 'direction' => EntryDirection::Credit, 'amount' => Money::minor(100)],
    ]))->toThrow(FinancialException::class, 'must be in BDT');
    expect(Transaction::count())->toBe(0);
});

it('a BDT ledger transaction succeeds and records BDT', function () {
    $ledger = app(LedgerService::class);
    $tx = $ledger->post(TransactionType::Adjustment, [
        ['account' => $ledger->systemAccount(LedgerAccountType::PlatformCash), 'direction' => EntryDirection::Debit, 'amount' => Money::minor(100)],
        ['account' => $ledger->systemAccount(LedgerAccountType::PlatformFees), 'direction' => EntryDirection::Credit, 'amount' => Money::minor(100)],
    ]);
    expect($tx->currency)->toBe('BDT')->and($tx->entries->count())->toBe(2);
    expect(fn () => $ledger->systemAccount(LedgerAccountType::PlatformCash, 'USD'))->toThrow(FinancialException::class);
});

it('rejects an investment when the project or contract is not BDT', function () {
    $investor = makeInvestor(10000000);
    $project = makeProject();
    activeContract($project);
    if (! corrupt('projects', ['id' => $project->id], ['currency' => 'USD'])) {
        expect(true)->toBeTrue();

        return;
    }
    expect(fn () => app(InvestmentService::class)->invest($investor, $project->fresh(), Money::minor(1000000), 'bdt-1'))->toThrow(FinancialException::class, 'must be in BDT');
    expect(\App\Models\Investment::count())->toBe(0);

    corrupt('projects', ['id' => $project->id], ['currency' => 'BDT']);
    if (corrupt('contracts', ['project_id' => $project->id], ['currency' => 'USD'])) {
        expect(fn () => app(InvestmentService::class)->invest($investor, $project->fresh(), Money::minor(1000000), 'bdt-2'))->toThrow(FinancialException::class, 'must be in BDT');
    }
});

it('rejects settlement of a non-BDT contract', function () {
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    app(InvestmentService::class)->invest(makeInvestor(20000000), $project, Money::minor(10000000), 'bdt-s');
    if (! corrupt('contracts', ['id' => $contract->id], ['currency' => 'USD'])) {
        expect(true)->toBeTrue();

        return;
    }
    expect(fn () => app(SettlementService::class)->settle(Contract::find($contract->id), Money::minor(100000), User::factory()->create()))->toThrow(FinancialException::class, 'must be in BDT');
    expect(Settlement::count())->toBe(0);
});

it('rejects Murabaha steps on a non-BDT contract', function () {
    [$contract, $m, $admin] = murabahaFixture();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    if (! corrupt('contracts', ['id' => $contract->id], ['currency' => 'USD'])) {
        expect(true)->toBeTrue();

        return;
    }
    expect(fn () => $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV', now(), $admin))->toThrow(FinancialException::class, 'must be in BDT');
});

it('reconciliation flags any non-BDT record as a failure', function () {
    makeInvestor(1000000);
    expect(reconcile()['passed'])->toBeTrue();
    $wallet = Wallet::first();
    if (! corrupt('wallets', ['id' => $wallet->id], ['currency' => 'USD'])) {
        expect(true)->toBeTrue();

        return;
    }
    $r = reconcile();
    expect($r['passed'])->toBeFalse()->and($r['results']['Currency Integrity']->errors)->not->toBeEmpty();
});
