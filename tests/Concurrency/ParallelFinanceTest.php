<?php

use App\Models\Deposit;
use App\Models\Investment;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Real parallel database connections racing the same wallet. Requires MySQL (row locks do nothing on SQLite).
 * Run:  DB_CONNECTION=mysql DB_DATABASE=islamic_finance_test php artisan test tests/Concurrency
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Concurrency tests need MySQL.');
    }
});

/** @param list<array{0:string, 1:list<string>}> $jobs */
function race(array $jobs): array
{
    $procs = array_map(function ($args) {
        $p = new Process(['php', base_path('artisan'), 'finance:probe', ...$args], base_path(), null, null, 120);
        $p->start();

        return $p;
    }, $jobs);
    foreach ($procs as $p) {
        $p->wait();
    }

    return array_map(fn (Process $p) => trim($p->getOutput()), $procs);
}

it('two investments cannot both spend the same wallet balance', function () {
    $investor = makeInvestor(1000000);               // BDT 10,000
    $project = makeProject();
    DB::commit();                                    // make the fixtures visible to the child processes

    $out = race(array_map(fn ($i) => ['invest', $investor->id, '8000', "race-$i", $project->id], range(1, 6)));

    expect(collect($out)->filter(fn ($o) => $o === 'OK')->count())->toBe(1)
        ->and(collect($out)->filter(fn ($o) => str_starts_with($o, 'ERROR'))->count())->toBe(0);
    $wallet = app(WalletService::class)->balances(app(WalletService::class)->walletFor($investor->user));
    expect($wallet['available']->minor)->toBe(200000)->and($wallet['invested']->minor)->toBe(800000)->and(Investment::count())->toBe(1)
        ->and($project->fresh()->funded_amount)->toBe(800000);
});

it('the same idempotency key sent in parallel creates exactly one investment', function () {
    $investor = makeInvestor(10000000);
    $project = makeProject();
    DB::commit();

    $out = race(array_map(fn () => ['invest', $investor->id, '5000', 'same-key', $project->id], range(1, 6)));

    expect(collect($out)->filter(fn ($o) => str_starts_with($o, 'ERROR'))->count())->toBe(0)->and(Investment::count())->toBe(1);
    expect(app(WalletService::class)->balances(app(WalletService::class)->walletFor($investor->user))['invested']->minor)->toBe(500000);
});

it('parallel withdrawals cannot overdraw the wallet', function () {
    $investor = makeInvestor(1000000);               // BDT 10,000
    DB::commit();

    $out = race(array_map(fn ($i) => ['withdraw', $investor->id, '6000', "wd-$i"], range(1, 5)));

    expect(collect($out)->filter(fn ($o) => $o === 'OK')->count())->toBe(1)->and(Withdrawal::count())->toBeLessThanOrEqual(5);
    $b = app(WalletService::class)->balances(app(WalletService::class)->walletFor($investor->user));
    expect($b['available']->minor)->toBe(400000)->and($b['pending']->minor)->toBe(600000)->and($b['available']->minor)->toBeGreaterThanOrEqual(0);
});

it('an investment and a withdrawal race for the same balance: only one wins and nothing goes negative', function () {
    $investor = makeInvestor(1000000);               // BDT 10,000
    $project = makeProject();
    DB::commit();

    $out = race([
        ['invest', $investor->id, '8000', 'mix-i', $project->id],
        ['withdraw', $investor->id, '8000', 'mix-w'],
        ['invest', $investor->id, '8000', 'mix-i2', $project->id],
        ['withdraw', $investor->id, '8000', 'mix-w2'],
    ]);

    expect(collect($out)->filter(fn ($o) => $o === 'OK')->count())->toBe(1)
        ->and(collect($out)->filter(fn ($o) => str_starts_with($o, 'ERROR'))->count())->toBe(0);
    $b = app(WalletService::class)->balances(app(WalletService::class)->walletFor($investor->user));
    expect($b['available']->minor)->toBe(200000)->and($b['available']->minor)->toBeGreaterThanOrEqual(0)
        ->and($b['invested']->minor + $b['pending']->minor)->toBe(800000);   // the 8,000 sits in exactly one bucket
    $r = app(\App\Services\Finance\Reconciliation\ReconciliationService::class)->run();
    expect(\App\Services\Finance\Reconciliation\ReconciliationService::passed($r, true))->toBeTrue();
});

it('the books reconcile after a burst of parallel investments and withdrawals', function () {
    $investor = makeInvestor(5000000);
    $project = makeProject();
    DB::commit();
    $jobs = [];
    foreach (range(1, 5) as $i) {
        $jobs[] = ['invest', $investor->id, '3000', "burst-i$i", $project->id];
        $jobs[] = ['withdraw', $investor->id, '2000', "burst-w$i"];
    }
    race($jobs);
    $b = app(WalletService::class)->balances(app(WalletService::class)->walletFor($investor->user));
    expect($b['available']->minor)->toBeGreaterThanOrEqual(0);
    $r = app(\App\Services\Finance\Reconciliation\ReconciliationService::class)->run();
    expect(\App\Services\Finance\Reconciliation\ReconciliationService::passed($r, true))->toBeTrue(json_encode(collect($r)->flatMap(fn ($x) => $x->errors)->all()));
});

/* ---- P0: activation, project funding and settlement under real parallel connections ---- */

function okCount(array $out): int { return collect($out)->filter(fn ($o) => $o === 'OK')->count(); }
function errorCount(array $out): int { return collect($out)->filter(fn ($o) => str_starts_with($o, 'ERROR'))->count(); }
function booksReconcile(): void
{
    $r = app(\App\Services\Finance\Reconciliation\ReconciliationService::class)->run();
    expect(\App\Services\Finance\Reconciliation\ReconciliationService::passed($r, true))->toBeTrue(json_encode(collect($r)->flatMap(fn ($x) => $x->errors)->all()));
}

it('25. concurrent final funding: only the investments that fit succeed, and the contract activates exactly once', function () {
    $project = realProject(\App\Enums\ContractType::Mudarabah);          // target BDT 100,000
    $first = makeInvestor(20000000);
    fund($first, $project, 5000000);                                      // 50,000 already in
    $others = array_map(fn () => makeInvestor(20000000), range(1, 4));
    DB::commit();

    $out = race(array_map(fn ($inv, $i) => ['invest', $inv->id, '50000', "final-$i", $project->id], $others, array_keys($others)));

    expect(okCount($out))->toBe(1)->and(errorCount($out))->toBe(0)
        ->and($project->fresh()->funded_amount)->toBe(10000000)->and($project->fresh()->status)->toBe(\App\Enums\ProjectStatus::Active)
        ->and(\App\Models\AuditLog::where('action', 'contract.activated')->count())->toBe(1)
        ->and(\App\Models\Transaction::where('type', \App\Enums\TransactionType::Investment)->count())->toBe(2)
        ->and(\App\Models\Transaction::whereIn('type', ['PROJECT_FUNDING', 'CAPITAL_DEPLOYMENT'])->count())->toBe(0);
    booksReconcile();
});

it('26. the same idempotency key in parallel: one investment, one funding transaction, one activation', function () {
    $project = realProject(\App\Enums\ContractType::Mudarabah);
    $investor = makeInvestor(20000000);
    DB::commit();

    $out = race(array_map(fn () => ['invest', $investor->id, '100000', 'one-key', $project->id], range(1, 6)));

    expect(errorCount($out))->toBe(0)->and(Investment::count())->toBe(1)
        ->and(\App\Models\Transaction::where('type', \App\Enums\TransactionType::Investment)->count())->toBe(1)
        ->and(\App\Models\AuditLog::where('action', 'contract.activated')->count())->toBe(1)
        ->and($project->fresh()->status)->toBe(\App\Enums\ProjectStatus::Active);
    booksReconcile();
});

it('27. concurrent settlements of one contract produce exactly one settlement and no negative pool', function () {
    $project = realProject(\App\Enums\ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $contract = $project->contract->fresh();
    closeOut($contract, 2000000);
    DB::commit();

    $out = race(array_map(fn ($i) => ['settle', $contract->id, '20000', "settle-$i"], range(1, 5)));

    expect(errorCount($out))->toBe(0)->and(\App\Models\Settlement::where('contract_id', $contract->id)->count())->toBe(1)
        ->and(pool(\App\Enums\LedgerAccountType::ProjectFunds, $project->id))->toBe(0)
        ->and(pool(\App\Enums\LedgerAccountType::VentureCapital, $project->id))->toBe(0);
    booksReconcile();
});

it('28. funding racing settlement: nothing goes negative, no late capital is accepted, and the books reconcile', function () {
    $project = realProject(\App\Enums\ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);                    // fully funded and active
    $contract = $project->contract->fresh();
    closeOut($contract, 0);
    $late = array_map(fn () => makeInvestor(20000000), range(1, 3));
    DB::commit();

    $jobs = [['settle', $contract->id, '0', 'race-settle']];
    foreach ($late as $i => $inv) {
        $jobs[] = ['invest', $inv->id, '10000', "late-$i", $project->id];
    }
    $out = race($jobs);

    expect(errorCount($out))->toBe(0)->and(Investment::count())->toBe(1)    // a closed project accepts no further capital
        ->and(\App\Models\Settlement::where('contract_id', $contract->id)->count())->toBe(1)
        ->and(pool(\App\Enums\LedgerAccountType::VentureCapital, $project->id))->toBe(0);
    booksReconcile();
});

it('29. concurrent contributions (same and different keys) record the Musharakah business capital exactly once; activation happens once', function () {
    $project = realProject(\App\Enums\ContractType::Musharakah);
    fund(makeInvestor(80000000), $project, 70000000);
    $contract = $project->contract->fresh();
    DB::commit();

    $jobs = [];
    foreach (range(1, 3) as $i) {
        $jobs[] = ['contribute', $contract->id, '300000', 'cap-same'];
        $jobs[] = ['contribute', $contract->id, '300000', "cap-diff-$i"];
    }
    $out = race($jobs);

    expect(errorCount($out))->toBe(0)->and(\App\Models\MusharakahCapitalContribution::where('contract_id', $contract->id)->count())->toBe(1)
        ->and(\App\Models\Transaction::where('type', \App\Enums\TransactionType::MusharakahCapital)->count())->toBe(1)
        ->and(\App\Models\AuditLog::where('action', 'contract.activated')->where('auditable_id', $contract->id)->count())->toBe(1)
        ->and($contract->fresh()->status)->toBe(\App\Enums\ContractStatus::Active)
        ->and(pool(\App\Enums\LedgerAccountType::BusinessCapital, $project->id))->toBe(30000000);
    booksReconcile();
});

it('30. concurrent deployment and concurrent remittances record the venture events exactly once', function () {
    $project = realProject(\App\Enums\ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 10000000);
    $contract = $project->contract->fresh();
    DB::commit();

    $out = race(array_merge(
        array_map(fn () => ['deploy', $contract->id, '100000', 'dep-same'], range(1, 3)),
        array_map(fn ($i) => ['deploy', $contract->id, '100000', "dep-$i"], range(1, 3)),
    ));
    expect(errorCount($out))->toBe(0)->and(\App\Models\CapitalDeployment::where('contract_id', $contract->id)->count())->toBe(1)
        ->and(\App\Models\Transaction::where('type', 'CAPITAL_DEPLOYMENT')->count())->toBe(1);

    $out = race(array_map(fn () => ['return', $contract->id, '40000', 'ret-same'], range(1, 5)));
    expect(errorCount($out))->toBe(0)->and(\App\Models\VentureRemittance::where('contract_id', $contract->id)->count())->toBe(1)
        ->and(pool(\App\Enums\LedgerAccountType::VentureCapital, $project->id))->toBe(6000000);

    // Capital returns racing past the deployed amount can never overdraw the venture asset.
    $out = race(array_map(fn ($i) => ['return', $contract->id, '40000', "ret-$i"], range(1, 6)));
    expect(errorCount($out))->toBe(0)->and(pool(\App\Enums\LedgerAccountType::VentureCapital, $project->id))->toBeGreaterThanOrEqual(0);
    booksReconcile();
});
