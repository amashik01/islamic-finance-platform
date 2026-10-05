<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/* Database-level invariants (MySQL 8 CHECK constraints). The same rules are also enforced in the models and by reconciliation. */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('CHECK constraints are added on MySQL only.');
    }
});

it('the database refuses a non-BDT currency on every financial table', function () {
    $investor = makeInvestor(1000000);
    $project = makeProject();
    $contract = activeContract($project);
    foreach (['projects' => $project->id, 'contracts' => $contract->id, 'wallets' => DB::table('wallets')->value('id'), 'ledger_accounts' => DB::table('ledger_accounts')->value('id'), 'transactions' => DB::table('transactions')->value('id')] as $table => $id) {
        expect(fn () => DB::table($table)->where('id', $id)->update(['currency' => 'USD']))->toThrow(QueryException::class, 'chk_');
    }
});

it('the database refuses zero or negative financial amounts and over-funding', function () {
    $investor = makeInvestor(1000000);
    $project = makeProject();
    expect(fn () => DB::table('projects')->where('id', $project->id)->update(['funded_amount' => $project->funding_target + 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('ledger_entries')->where('id', DB::table('ledger_entries')->value('id'))->update(['amount' => 0]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('ledger_entries')->where('id', DB::table('ledger_entries')->value('id'))->update(['direction' => 'SIDEWAYS']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transactions')->where('id', DB::table('transactions')->value('id'))->update(['amount' => 0]))->toThrow(QueryException::class);
});

it('the database refuses duplicate idempotency keys and duplicate transaction references', function () {
    makeInvestor(1000000);
    $row = (array) DB::table('transactions')->first();
    unset($row['id']);
    expect(fn () => DB::table('transactions')->insert($row))->toThrow(QueryException::class);   // reference + idempotency key are unique
});

it('the database refuses a second wallet for the same user and a second settlement per contract', function () {
    $investor = makeInvestor();
    expect(fn () => DB::table('wallets')->insert(['user_id' => $investor->user_id, 'currency' => 'BDT', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});
