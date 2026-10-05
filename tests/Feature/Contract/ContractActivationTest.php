<?php

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\LedgerAccountType as A;
use App\Enums\ProjectStatus;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Settlement\SettlementService;
use App\Support\Money\Money;

/* ---- the REAL workflow: builder -> submit -> approve -> Shariah -> publish -> fund -> activate ---- */

it('A. Mudarabah: create -> approve -> publish -> fund fully -> project ACTIVE and contract ACTIVE, once', function () {
    $project = realProject(ContractType::Mudarabah);
    expect($project->status)->toBe(ProjectStatus::Funding)->and($project->contract->status)->toBe(ContractStatus::Approved);

    $a = makeInvestor(20000000);
    $b = makeInvestor(20000000);
    fund($a, $project, 4000000);
    expect($project->fresh()->status)->toBe(ProjectStatus::Funding)->and($project->contract->fresh()->status)->toBe(ContractStatus::Approved);   // C. partial funding

    fund($b, $project, 6000000);
    $project->refresh();
    $contract = $project->contract->fresh();
    expect($project->status)->toBe(ProjectStatus::Active)->and($contract->status)->toBe(ContractStatus::Active)->and($contract->start_date)->not->toBeNull()
        ->and(AuditLog::where('action', 'contract.activated')->where('auditable_id', $contract->id)->count())->toBe(1);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('B. Musharakah: fully funded investors alone do not start the partnership; the business capital completes activation', function () {
    $project = realProject(ContractType::Musharakah);
    $investor = makeInvestor(80000000);
    fund($investor, $project, 70000000);

    expect($project->fresh()->funded_amount)->toBe(70000000)->and($project->fresh()->status)->toBe(ProjectStatus::Funding)
        ->and($project->contract->fresh()->status)->toBe(ContractStatus::Approved);   // awaiting the business partner's capital

    recordBusinessCapital($project->contract);
    expect($project->fresh()->status)->toBe(ProjectStatus::Active)->and($project->contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and(AuditLog::where('action', 'contract.activated')->count())->toBe(1);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('B2. Musharakah: business capital first, then the final investment activates it', function () {
    $project = realProject(ContractType::Musharakah);
    recordBusinessCapital($project->contract);
    expect($project->contract->fresh()->status)->toBe(ContractStatus::Approved);   // capital alone never activates
    $investor = makeInvestor(80000000);
    fund($investor, $project, 30000000);
    expect($project->contract->fresh()->status)->toBe(ContractStatus::Approved);
    fund($investor, $project, 40000000);
    expect($project->contract->fresh()->status)->toBe(ContractStatus::Active)->and($project->fresh()->status)->toBe(ProjectStatus::Active);
});

it('D. repeating or over-funding the final amount never activates twice or duplicates ledger entries', function () {
    $project = realProject(ContractType::Mudarabah);
    $inv = makeInvestor(30000000);
    fund($inv, $project, 4000000);
    $last = fund($inv, $project, 6000000, 'final-key');
    $txCount = Transaction::count();
    $funding = Transaction::where('type', 'PROJECT_FUNDING')->count();

    // The same request again returns the same investment and posts nothing.
    expect(fund($inv, $project, 6000000, 'final-key')->id)->toBe($last->id)->and(Transaction::count())->toBe($txCount);
    // Funding beyond the target is refused: the project is no longer accepting funds.
    expect(fn () => fund($inv, $project, 5000000))->toThrow(FinancialException::class, 'no longer accepting');

    expect(Investment::count())->toBe(2)->and(Transaction::where('type', 'PROJECT_FUNDING')->count())->toBe($funding)
        ->and(AuditLog::where('action', 'contract.activated')->count())->toBe(1)->and(pool(A::ProjectFunds, $project->id))->toBe(10000000);
});

it('E. a contract produced by the normal workflow can be settled (Mudarabah)', function () {
    $project = realProject(ContractType::Mudarabah);
    $a = makeInvestor(20000000);
    fund($a, $project, 10000000);
    $contract = $project->contract->fresh();
    remit($contract, 2000000);
    $s = app(SettlementService::class)->settle($contract, Money::minor(2000000), User::factory()->create());
    expect($s->status->value)->toBe('POSTED')->and($contract->fresh()->status)->toBe(ContractStatus::Completed)->and($project->fresh()->status)->toBe(ProjectStatus::Completed);
    expect(reconcile(true)['passed'])->toBeTrue();
});

it('E2. a contract produced by the normal workflow can be settled (Musharakah)', function () {
    $project = realProject(ContractType::Musharakah);
    fund(makeInvestor(80000000), $project, 70000000);
    recordBusinessCapital($project->contract);
    $contract = $project->contract->fresh();
    remit($contract, 20000000);
    app(SettlementService::class)->settle($contract, Money::minor(20000000), User::factory()->create());
    expect($contract->fresh()->status)->toBe(ContractStatus::Completed);
    expect(reconcile(true)['passed'])->toBeTrue();
});

/* ---- F. invalid activation attempts ---- */

it('F. underfunded projects never activate, whatever the contract state', function () {
    $project = realProject(ContractType::Mudarabah);
    fund(makeInvestor(20000000), $project, 9999999 + 1 - 1000000);
    expect($project->contract->fresh()->status)->toBe(ContractStatus::Approved)->and($project->fresh()->status)->toBe(ProjectStatus::Funding);
});

it('F. a final investment is refused (and rolled back) when the contract is not approved', function () {
    foreach ([ContractStatus::Cancelled, ContractStatus::Draft, ContractStatus::PendingApproval] as $status) {
        $project = realProject(ContractType::Mudarabah);
        corrupt('contracts', ['project_id' => $project->id], ['status' => $status->value]);
        $inv = makeInvestor(20000000);
        $before = [Investment::count(), Transaction::count()];
        expect(fn () => fund($inv, $project, 10000000))->toThrow(FinancialException::class, 'cannot be activated');
        expect([Investment::count(), Transaction::count()])->toBe($before)->and($project->fresh()->funded_amount)->toBe(0)->and($project->fresh()->status)->toBe(ProjectStatus::Funding);
    }
});

it('F. a contract cannot activate without an approved Shariah review', function () {
    $project = realProject(ContractType::Mudarabah);
    corrupt('shariah_reviews', ['project_id' => $project->id], ['status' => ShariahReviewStatus::Rejected->value]);
    $before = Investment::count();
    expect(fn () => fund(makeInvestor(20000000), $project, 10000000))->toThrow(FinancialException::class, 'Shariah review approval is required');
    expect(Investment::count())->toBe($before)->and($project->contract->fresh()->status)->toBe(ContractStatus::Approved);
});

it('F. a rejected project cannot be funded at all', function () {
    $project = makeProject(['status' => ProjectStatus::Rejected]);
    expect(fn () => fund(makeInvestor(20000000), $project, 10000000))->toThrow(FinancialException::class, 'no longer accepting');
});

it('F. the contract state machine refuses illegal transitions', function () {
    $project = makeProject();
    $c = activeContract($project);   // ACTIVE
    expect(fn () => $c->transitionTo(ContractStatus::Approved))->toThrow(FinancialException::class, 'cannot move');
    $c->transitionTo(ContractStatus::Completed);
    expect(fn () => $c->transitionTo(ContractStatus::Active))->toThrow(FinancialException::class)
        ->and(fn () => $c->transitionTo(ContractStatus::Cancelled))->toThrow(FinancialException::class);
    $cancelled = new Contract(['contract_number' => 'X', 'contract_type' => ContractType::Mudarabah, 'project_id' => $project->id]);
    $cancelled->forceFill(['status' => ContractStatus::Cancelled])->save();
    expect(fn () => $cancelled->transitionTo(ContractStatus::Active))->toThrow(FinancialException::class);
    $draft = new Contract(['contract_number' => 'Y', 'contract_type' => ContractType::Mudarabah, 'project_id' => $project->id]);
    $draft->forceFill(['status' => ContractStatus::Draft])->save();
    expect(fn () => $draft->transitionTo(ContractStatus::Active))->toThrow(FinancialException::class);   // must be approved first
});

it('a fixture-style contract that is already ACTIVE is left alone when funding completes', function () {
    $project = makeProject(['funding_target' => 10000000]);
    $contract = activeContract($project);
    $start = $contract->start_date;
    fund(makeInvestor(20000000), $project, 10000000);
    expect($project->fresh()->status)->toBe(ProjectStatus::Active)->and($contract->fresh()->status)->toBe(ContractStatus::Active)
        ->and(AuditLog::where('action', 'contract.activated')->count())->toBe(0);   // no second activation
});
