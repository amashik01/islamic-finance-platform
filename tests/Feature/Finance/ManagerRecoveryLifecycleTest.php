<?php

use App\Enums\ContractType;
use App\Enums\LedgerAccountType as A;
use App\Enums\ManagerRecoveryStatus as S;
use App\Exceptions\FinancialException;
use App\Models\ManagerRecovery;
use App\Models\User;
use App\Services\Settlement\ManagerRecoveryService;
use App\Support\LedgerSemantics;
use App\Support\Money\Money;

/** Mudarabah, investor 100,000, loss 20,000 with documented fault. Returns [recovery, investor, project, staff]. */
function faultCase(): array
{
    seedRoles();
    $project = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(10000000);
    fund($inv, $project, 10000000);
    settleNow($project->contract->fresh(), -2000000, true, 'Funds used outside the agreed activity (audit ref 7)');
    $staff = User::factory()->create();
    $staff->assignRole('ADMIN');

    return [ManagerRecovery::firstOrFail(), $inv, $project, $staff];
}

it('a recovery starts as an allegation: no ledger asset, no receivable, no profit', function () {
    [$r, $inv, $project] = faultCase();
    expect($r->status)->toBe(S::Suspected)->and($r->amount)->toBe(2000000)
        ->and(\App\Models\Transaction::whereIn('type', ['RECOVERY_RECEIPT', 'RECOVERY_DISTRIBUTION'])->count())->toBe(0)
        ->and(pool(A::ProjectFunds, $project->id))->toBe(0);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('money cannot be received before the liability is recognised, and the stages cannot be skipped', function () {
    [$r, , , $staff] = faultCase();
    $svc = app(ManagerRecoveryService::class);
    expect(fn () => $svc->receive($r, Money::minor(100), 'rk0', $staff))->toThrow(FinancialException::class, 'liability has been recognised')
        ->and(fn () => $svc->recognizeLiability($r, $staff))->toThrow(FinancialException::class, 'cannot move')
        ->and(fn () => $svc->establishFault($r->fresh(), $staff, 'Documented misuse of funds, see audit ref 7 in full.'))->toThrow(FinancialException::class, 'cannot move');
    $svc->startReview($r, $staff);
    expect(fn () => $svc->establishFault($r->fresh(), $staff, 'too short'))->toThrow(FinancialException::class, 'evidence');
    expect(fn () => $svc->startReview($r->fresh(), User::factory()->create()))->toThrow(FinancialException::class, 'not allowed');
});

it('the full lifecycle: 15,000 of a 20,000 recognised liability is received and goes to the investor, not through the profit ratio', function () {
    [$r, $inv, $project, $staff] = faultCase();
    $svc = app(ManagerRecoveryService::class);
    $svc->startReview($r, $staff);
    $svc->establishFault($r->fresh(), $staff, 'Documented misuse of funds, see audit ref 7 in full.');
    expect(fn () => $svc->recognizeLiability($r->fresh(), $staff, Money::minor(3000000)))->toThrow(FinancialException::class, 'cannot exceed the claimed loss');
    $svc->recognizeLiability($r->fresh(), $staff);

    $w = app(\App\Services\Wallet\WalletService::class);
    $before = $w->balances($w->walletFor($inv->user))['available']->minor;
    $svc->receive($r->fresh(), Money::minor(1500000), 'rk1', $staff);
    $r = $r->fresh();
    expect($r->status)->toBe(S::Partial)->and($r->recovered_amount)->toBe(1500000)->and($r->outstanding())->toBe(500000)
        ->and($w->balances($w->walletFor($inv->user))['available']->minor)->toBe($before + 1500000)
        ->and(pool(A::ProjectFunds, $project->id))->toBe(0);
    // Replay posts nothing; a different amount under the same key is a conflict; over-recovery is refused.
    $n = \App\Models\Transaction::count();
    $svc->receive($r, Money::minor(1500000), 'rk1', $staff);
    expect(\App\Models\Transaction::count())->toBe($n);
    expect(fn () => $svc->receive($r, Money::minor(1600000), 'rk1', $staff))->toThrow(\App\Exceptions\IdempotencyConflictException::class)
        ->and(fn () => $svc->receive($r, Money::minor(600000), 'rk2', $staff))->toThrow(FinancialException::class, 'exceeds the outstanding');
    $svc->receive($r, Money::minor(500000), 'rk3', $staff);
    expect($r->fresh()->status)->toBe(S::Recovered);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('a claim can be closed without recovery only with a reason, and then accepts no money', function () {
    [$r, , , $staff] = faultCase();
    $svc = app(ManagerRecoveryService::class);
    expect(fn () => $svc->writeOff($r, $staff, ''))->toThrow(FinancialException::class, 'reason');
    $svc->writeOff($r, $staff, 'Finding not established after review');
    expect($r->fresh()->status)->toBe(S::WrittenOff)->and(fn () => $svc->receive($r->fresh(), Money::minor(100), 'rk9', $staff))->toThrow(FinancialException::class);
});

it('every ledger account type and transaction type has documented semantics', function () {
    expect(LedgerSemantics::undocumented())->toBe([]);
    foreach (LedgerSemantics::transactions() as $code => $t) {
        foreach (['event', 'shariah', 'debit', 'credit', 'owner', 'evidence', 'lifecycle'] as $k) {
            expect($t[$k])->not->toBe('', "$code missing $k");
        }
    }
});
