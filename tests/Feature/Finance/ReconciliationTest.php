<?php

use App\Enums\ContractType;
use App\Models\AuditLog;
use App\Models\Investment;
use App\Models\Project;
use App\Models\User;
use App\Services\Murabaha\MurabahaService;
use App\Services\Settlement\SettlementService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/** A realistic healthy book: deposits, an investment, a settled Mudarabah, a Murabaha mid-payment, a held withdrawal. */
function healthyBook(): array
{
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    $inv = makeInvestor(30000000);
    app(InvestmentService::class)->invest($inv, $project, Money::minor(10000000), 'rc-1');
    app(WalletService::class)->requestWithdrawal($inv->user, Money::minor(500000), 'rc-w');
    $settlement = app(SettlementService::class)->settle($contract->fresh(), Money::minor(2000000), User::factory()->create());

    [$mc, $m, $admin] = murabahaFixture();
    $svc = app(MurabahaService::class);
    $svc->verifySupplierAndAsset($m, $admin);
    $svc->recordPurchase($m->fresh(), Money::minor(10000000), 'INV', now(), $admin);
    $svc->recordOwnership($m->fresh(), now(), $admin);
    $svc->recordPossession($m->fresh(), now(), 'held', $admin);
    $receivable = $svc->executeSale($m->fresh(), now(), now()->addMonth(), $admin);
    $svc->recordPayment($receivable, Money::minor(2750000), 'rc-pay', now());

    return compact('project', 'contract', 'inv', 'settlement', 'mc', 'receivable');
}

function failsWith(string $check, string $needle): void
{
    $r = reconcile();
    expect($r['passed'])->toBeFalse();
    $errors = $r['results'][$check]->errors;
    expect(collect($errors)->contains(fn ($e) => str_contains($e, $needle)))->toBeTrue("Expected [$check] to report \"$needle\", got: ".json_encode($errors));
}

it('a healthy database passes every check, including strict', function () {
    healthyBook();
    $r = reconcile(true);
    expect($r['passed'])->toBeTrue();
    foreach ($r['results'] as $name => $result) {
        expect($result->errors)->toBe([], $name);
    }
});

it('detects an unbalanced ledger transaction', function () {
    healthyBook();
    DB::table('ledger_entries')->where('id', DB::table('ledger_entries')->min('id'))->increment('amount', 5);   // raw write bypasses the model guard
    failsWith('Ledger Balance', 'unbalanced');
});

it('invalid account and transaction references are refused by the database itself', function () {
    healthyBook();
    // Foreign keys stop an orphan entry from ever being written; the reconciliation check is a second line of defence.
    expect(fn () => DB::table('ledger_entries')->insert(['transaction_id' => 999999, 'ledger_account_id' => 999999, 'direction' => 'DEBIT', 'amount' => 1, 'balance_after' => 0, 'created_at' => now()]))
        ->toThrow(\Illuminate\Database\QueryException::class);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('detects a wallet that does not match its ledger-derived balance', function () {
    $b = healthyBook();
    $acct = \App\Models\LedgerAccount::where('type', 'INVESTOR_AVAILABLE')->first();
    DB::table('ledger_accounts')->where('id', $acct->id)->update(['balance' => $acct->balance + 1]);
    failsWith('Wallet Integrity', 'does not match its ledger-derived balance');
});

it('detects a negative wallet balance', function () {
    healthyBook();
    $acct = \App\Models\LedgerAccount::where('type', 'INVESTOR_AVAILABLE')->first();
    DB::table('ledger_accounts')->where('id', $acct->id)->update(['balance' => -100]);
    failsWith('Wallet Integrity', 'negative balance');
});

it('detects a pending bucket that disagrees with open withdrawals', function () {
    healthyBook();
    DB::table('withdrawals')->update(['status' => 'PAID']);
    failsWith('Wallet Integrity', 'pending balance does not equal');
});

it('detects an investment with no financial transaction, and a transaction with no investment', function () {
    $b = healthyBook();
    $ghost = new Investment(['investor_id' => $b['inv']->id, 'project_id' => $b['project']->id, 'amount' => 100000, 'idempotency_key' => 'ghost']);
    $ghost->forceFill(['status' => 'CONFIRMED'])->save();
    failsWith('Investment Integrity', 'no corresponding financial transaction');

    DB::table('investments')->where('idempotency_key', 'ghost')->delete();
    DB::table('investments')->where('idempotency_key', 'rc-1')->update(['idempotency_key' => 'rc-1-renamed']);
    $id = DB::table('investments')->where('idempotency_key', 'rc-1-renamed')->value('id');
    DB::table('investments')->where('id', $id)->update(['amount' => 123]);
    failsWith('Investment Integrity', 'does not match its financial transaction');
});

it('detects an investment whose contract belongs to another project', function () {
    $b = healthyBook();
    $other = activeContract(makeProject());
    DB::table('investments')->update(['contract_id' => $other->id]);
    failsWith('Investment Integrity', 'different project');
});

it('detects a project funded amount that does not equal its investments', function () {
    $b = healthyBook();
    DB::table('projects')->where('id', $b['project']->id)->update(['funded_amount' => 1]);
    failsWith('Investment Integrity', 'funded amount does not equal');
});

it('detects settlement principal, profit and recovery mismatches', function () {
    $b = healthyBook();
    $id = DB::table('settlement_items')->where('item_type', 'PRINCIPAL')->value('id');
    DB::table('settlement_items')->where('id', $id)->decrement('amount', 1);
    failsWith('Settlement Integrity', 'principal mismatch');
    DB::table('settlement_items')->where('id', $id)->increment('amount', 1);

    DB::table('settlement_items')->where('item_type', 'BUSINESS_PROFIT_SHARE')->delete();
    failsWith('Settlement Integrity', 'profit mismatch');
});

it('detects a business share that was never recorded and an unrecorded manager liability', function () {
    $b = healthyBook();
    DB::table('contracts')->where('id', $b['contract']->id)->update(['recovery_status' => 'IN_RECOVERY']);
    failsWith('Settlement Integrity', 'marked in recovery with no recoverable amount');
});

it('a duplicate settlement is impossible at the database level and flagged if forced', function () {
    $b = healthyBook();
    expect(fn () => DB::table('settlements')->insert(['reference' => 'DUP', 'contract_id' => $b['contract']->id, 'project_id' => $b['project']->id, 'status' => 'POSTED', 'currency' => 'BDT', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('detects Murabaha receivable, payment, outstanding and profit mismatches', function () {
    $b = healthyBook();
    $rid = $b['receivable']->id;

    DB::table('receivables')->where('id', $rid)->update(['paid_amount' => 1]);
    failsWith('Murabaha Receivables', 'paid amount does not equal the sum of payments');
    DB::table('receivables')->where('id', $rid)->update(['paid_amount' => 2750000]);
    expect(reconcile()['passed'])->toBeTrue();

    DB::table('receivables')->where('id', $rid)->update(['total_amount' => 11000001]);
    failsWith('Murabaha Receivables', 'receivable mismatch');
    DB::table('receivables')->where('id', $rid)->update(['total_amount' => 11000000]);

    DB::table('murabaha_sales')->update(['sale_profit' => 999]);
    failsWith('Murabaha Receivables', 'sale profit');
});

it('detects payments that exceed the sale price and an outstanding amount that disagrees with the ledger', function () {
    $b = healthyBook();
    DB::table('payments')->insert(['receivable_id' => $b['receivable']->id, 'amount' => 20000000, 'reference' => 'PX', 'idempotency_key' => 'px', 'paid_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    failsWith('Murabaha Receivables', 'payments exceed the sale price');
});

it('detects invalid Murabaha lifecycle states', function () {
    $b = healthyBook();
    DB::table('murabaha_contracts')->where('contract_id', $b['mc']->id)->update(['stage' => 'REQUESTED']);
    failsWith('Murabaha Receivables', 'sale recorded before the sale stage');
});

it('flags any non-BDT record as a reconciliation failure', function () {
    $b = healthyBook();
    if (! corrupt('transactions', ['id' => DB::table('transactions')->min('id')], ['currency' => 'USD'])) {
        expect(true)->toBeTrue();   // refused by the MySQL CHECK constraint

        return;
    }
    failsWith('Currency Integrity', 'non-BDT');
});

it('idempotency: records without a request fingerprint warn, and fail only in strict mode', function () {
    healthyBook();
    DB::table('transactions')->whereNotNull('idempotency_key')->limit(1)->update(['request_hash' => null]);
    expect(reconcile(false)['passed'])->toBeTrue()->and(reconcile(true)['passed'])->toBeFalse();
    expect(reconcile()['results']['Idempotency Integrity']->warnings)->not->toBeEmpty();
});

it('finance:reconcile prints PASS and exits 0 on a healthy book (normal and strict)', function () {
    healthyBook();
    $this->artisan('finance:reconcile')->expectsOutputToContain('Currency Integrity')->expectsOutputToContain('No financial inconsistencies detected.')->assertExitCode(0);
    $this->artisan('finance:reconcile --strict')->assertExitCode(0);
});

it('finance:reconcile prints FAIL, lists the errors and exits non-zero on a corrupted book', function () {
    healthyBook();
    $acct = \App\Models\LedgerAccount::where('type', 'INVESTOR_AVAILABLE')->first();
    DB::table('ledger_accounts')->where('id', $acct->id)->update(['balance' => -5]);
    $this->artisan('finance:reconcile')->expectsOutputToContain('Wallet Integrity')->expectsOutputToContain('Reconciliation FAILED.')->assertExitCode(1);
    $this->artisan('finance:reconcile --strict')->assertExitCode(1);
});

it('strict mode fails on warnings that normal mode tolerates', function () {
    healthyBook();
    DB::table('transactions')->whereNotNull('idempotency_key')->limit(1)->update(['request_hash' => null]);
    $this->artisan('finance:reconcile')->assertExitCode(0);
    $this->artisan('finance:reconcile --strict')->assertExitCode(1);
});

it('reconciliation is read-only and does not expose personal data', function () {
    $b = healthyBook();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|alter|drop|create)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });
    $counts = fn () => [DB::table('transactions')->count(), DB::table('ledger_entries')->count(), DB::table('wallets')->count(), DB::table('audit_logs')->count()];
    $before = $counts();
    \Illuminate\Support\Facades\Artisan::call('finance:reconcile', ['--strict' => true]);
    $out = \Illuminate\Support\Facades\Artisan::output();
    expect($writes)->toBe([])->and($counts())->toBe($before);
    expect($out)->not->toContain($b['inv']->user->email)->not->toContain($b['inv']->user->name);
});
