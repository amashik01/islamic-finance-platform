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
