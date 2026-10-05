<?php

use App\Domain\Aqd\AqdRegistry;
use App\Domain\Aqd\ShariahTextGuard;
use App\Enums\ContractType;
use App\Exceptions\FinancialException;
use App\Livewire\Business\Aqd\MudarabahWizard;
use App\Livewire\Business\Aqd\MurabahaWizard;
use App\Livewire\Business\Aqd\MusharakahWizard;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\Project\ProjectBuilder;
use App\Services\Project\ProjectWorkflow;
use App\Services\Shariah\ShariahRuleRegistry;
use Livewire\Livewire;

function mudInput(array $over = []): array
{
    return $over + ['title' => 'Mud', 'description' => 'A real trade description that is long enough.', 'industry' => 'Trade', 'purpose' => 'Grow', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'key_risks' => 'Demand',
        'minimum_amount' => '5000', 'contract_type' => 'MUDARABAH', 'capital_required' => '100000', 'investor_profit' => '70', 'business_profit' => '30'];
}

function mskInput(array $over = []): array
{
    return $over + ['title' => 'Msk', 'description' => 'A real trade description that is long enough.', 'industry' => 'Trade', 'purpose' => 'Grow', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'key_risks' => 'Demand',
        'minimum_amount' => '5000', 'contract_type' => 'MUSHARAKAH', 'total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000', 'investor_profit' => '60', 'business_profit' => '40'];
}

function mrbInput(array $over = []): array
{
    return $over + ['title' => 'Mrb', 'description' => 'A real trade description that is long enough.', 'industry' => 'Trade', 'purpose' => 'Grow', 'duration_months' => 12, 'risk_level' => 'MEDIUM', 'key_risks' => 'Demand',
        'contract_type' => 'MURABAHA', 'asset_name' => 'Units', 'supplier' => 'Supplier Ltd', 'quantity' => 4, 'unit_cost' => '25000', 'sale_profit' => '10000', 'installments' => 4];
}

/* ---- three separate forms, each its own contract ---- */

it('the chooser offers the three aqds and each opens its own dedicated form', function () {
    $biz = makeBusiness();
    $this->actingAs($biz->user)->get(route('business.projects.create'))->assertOk()->assertSee('Select Aqd / Contract Structure')
        ->assertSee(route('business.projects.create.mudarabah'), false)->assertSee(route('business.projects.create.musharakah'), false)->assertSee(route('business.projects.create.murabaha'), false);
    $this->actingAs($biz->user)->get(route('business.projects.create.mudarabah'))->assertOk()->assertSee('Create Mudarabah Project')->assertSee('Rabb-ul-Mal')->assertSee('Mudarib')->assertDontSee('Qabd')->assertDontSee('Musharik');
    $this->actingAs($biz->user)->get(route('business.projects.create.musharakah'))->assertOk()->assertSee('Create Musharakah Project')->assertSee('Musharik')->assertDontSee('Qabd')->assertDontSee('Mudarib');
    $this->actingAs($biz->user)->get(route('business.projects.create.murabaha'))->assertOk()->assertSee('Create Murabaha Project')->assertSee('not a loan', false)->assertDontSee('Rabb-ul-Mal')->assertDontSee('Musharik');
});

it('each aqd has its own steps and its own fields: no shared generic form', function () {
    $titles = fn (ContractType $t) => collect(AqdRegistry::for($t)->steps())->pluck('title')->all();
    expect($titles(ContractType::Mudarabah))->toContain('Ras-ul-Mal (capital)')->toContain('Profit-sharing')->toContain('Loss & responsibilities')->toContain('Termination & settlement')->toContain('Shariah review')->toContain('Contract preview')
        ->and($titles(ContractType::Musharakah))->toContain('Partners')->toContain('Capital contributions')->toContain('Loss sharing')->toContain('Governance')
        ->and($titles(ContractType::Murabaha))->toContain('Purchase request')->toContain('Promise (wa\'d)')->toContain('Wakalah (optional)')->toContain('Acquisition plan')->toContain('Ownership & qabd')->toContain('Murabaha sale terms');
    $keys = fn (ContractType $t) => array_keys(AqdRegistry::for($t)->fieldMap());
    expect($keys(ContractType::Mudarabah))->not->toContain('asset_name')->not->toContain('loss_ack')
        ->and($keys(ContractType::Musharakah))->not->toContain('capital_required')->not->toContain('qabd_type')
        ->and($keys(ContractType::Murabaha))->not->toContain('investor_profit')->not->toContain('capital_required');
});

it('every rule code referenced by a field exists in the Shariah rule registry, and sensitive fields carry guidance', function () {
    $reg = app(ShariahRuleRegistry::class)->all();
    foreach ([ContractType::Mudarabah, ContractType::Musharakah, ContractType::Murabaha] as $t) {
        foreach (AqdRegistry::for($t)->fieldMap() as $f) {
            foreach ($f['rules'] as $code) {
                expect($reg->has($code))->toBeTrue("Unknown rule $code on {$f['key']}");
            }
        }
    }
    foreach (['investor_profit' => ContractType::Mudarabah, 'business_profit' => ContractType::Mudarabah, 'loss_disclosure_ack' => ContractType::Mudarabah, 'capital_required' => ContractType::Mudarabah,
        'loss_ack' => ContractType::Musharakah, 'investor_contribution' => ContractType::Musharakah, 'use_promise' => ContractType::Murabaha, 'promise_type' => ContractType::Murabaha, 'sale_profit' => ContractType::Murabaha, 'qabd_type' => ContractType::Murabaha] as $key => $t) {
        $f = AqdRegistry::for($t)->fieldMap()[$key];
        expect(($f['islamic'] || $f['valid'] || $f['invalid']))->toBeTruthy("$key has no guidance")->and($f['rules'])->not->toBeEmpty("$key has no source rule");
    }
    $p = AqdRegistry::for(ContractType::Mudarabah)->fieldMap()['investor_profit'];
    expect($p['valid'])->toContain('70%')->and($p['invalid'])->toContain('guaranteed');
});

it('the form shows Islamic guidance, valid and invalid examples and the rule source with its verification level', function () {
    $this->actingAs(makeBusiness()->user);
    Livewire::test(MudarabahWizard::class)->set('step', 4)->assertSee('Islamic guidance')->assertSee('guaranteed annual return')->assertSee('MUD-PROFIT-RATIO')->assertSee('TKBB')->assertSee('under review, not a fatwa');
});

/* ---- Shariah rules are enforced on the SERVER, for every caller ---- */

it('Mudarabah: fixed or guaranteed return keys, wording and a Mudarib capital guarantee are rejected', function () {
    $b = makeBusiness();
    $svc = app(ProjectBuilder::class);
    foreach (['fixed_profit' => '10000', 'guaranteed_return' => '10', 'expected_return_percent' => '10', 'profit_on_capital_percent' => '10', 'capital_guarantee' => 'yes', 'mudarib_guarantees_capital' => '1'] as $k => $v) {
        expect(fn () => $svc->saveDraft($b, mudInput([$k => $v])))->toThrow(FinancialException::class, 'not part of a Mudarabah');
    }
    foreach (['We offer a guaranteed return of 10% per year.', 'The investor receives a fixed profit regardless of results.', 'The capital is guaranteed by the Mudarib.', 'The Mudarib guarantees the principal.', 'Returns are assured.', 'The business covers all losses.'] as $text) {
        expect(fn () => $svc->saveDraft($b, mudInput(['business_plan' => $text])))->toThrow(FinancialException::class);
    }
    expect(Project::count())->toBe(0);
});

it('Mudarabah: valid wording, including denials and liability for fault, is accepted', function () {
    $b = makeBusiness();
    foreach (['No return is guaranteed and the capital is at risk.', 'The Mudarib is not liable for ordinary loss. The Mudarib compensates a loss caused by its negligence or breach.', 'Profit is shared by the agreed ratio of actual profit.'] as $i => $text) {
        $p = app(ProjectBuilder::class)->saveDraft($b, mudInput(['title' => "Mud $i", 'business_plan' => $text]));
        expect($p->contract->mudarabah->business_plan)->toBe($text);
    }
});

it('Mudarabah: ratios must both be positive and total 100%, and security may cover fault only', function () {
    $b = makeBusiness();
    expect(fn () => app(ProjectBuilder::class)->saveDraft($b, mudInput(['investor_profit' => '100', 'business_profit' => '0'])))->toThrow(FinancialException::class, 'positive and total 100%')
        ->and(fn () => app(ProjectBuilder::class)->saveDraft($b, mudInput(['security_for_fault' => 'A bank guarantee covering all trading shortfalls.'])))->toThrow(FinancialException::class, 'fault or breach');
    app(ProjectBuilder::class)->saveDraft($b, mudInput(['security_for_fault' => 'A third-party guarantee limited to losses caused by negligence or breach.']));
    expect(Project::count())->toBe(1);
});

it('Musharakah: an arbitrary loss ratio or loss basis, and capital or profit guarantees, are rejected', function () {
    $b = makeBusiness();
    $svc = app(ProjectBuilder::class);
    foreach (['loss_ratio' => '50', 'investor_loss_percent' => '10', 'business_loss_percent' => '90', 'investor_loss_bps' => '1000', 'loss_allocation_basis' => 'AGREED_RATIO', 'guaranteed_return' => '5', 'capital_guarantee' => 'yes', 'buyback_price' => '100'] as $k => $v) {
        expect(fn () => $svc->saveDraft($b, mskInput([$k => $v])))->toThrow(FinancialException::class);
    }
    expect(fn () => $svc->saveDraft($b, mskInput(['loss_basis' => 'AGREED_RATIO'])))->toThrow(FinancialException::class, 'MUS-LOSS-CAPITAL')
        ->and(fn () => $svc->saveDraft($b, mskInput(['project_activity' => 'The business partner guarantees the investors\' capital.'])))->toThrow(FinancialException::class)
        ->and(fn () => $svc->saveDraft($b, mskInput(['project_activity' => 'We will repurchase partnership assets at face value.'])))->toThrow(FinancialException::class, 'buy-back')
        ->and(fn () => $svc->saveDraft($b, mskInput(['investor_profit' => '100', 'business_profit' => '0'])))->toThrow(FinancialException::class, 'positive profit share');
    expect(Project::count())->toBe(0);
    $p = $svc->saveDraft($b, mskInput(['loss_basis' => 'CAPITAL_RATIO']));
    expect($p->contract->musharakah->investor_profit_bps)->toBe(6000)->and($p->contract->musharakah->investor_ownership_bps)->toBe(7000);   // profit ratio independent of the capital ratio
});

it('Murabaha: cash-loan keys, interest wording and a mutual promise without an option are rejected', function () {
    $b = makeBusiness();
    $svc = app(ProjectBuilder::class);
    foreach (['cash_amount' => '100000', 'loan_amount' => '1', 'interest_rate' => '9', 'late_fee_rate' => '2', 'receivable_amount' => '5'] as $k => $v) {
        expect(fn () => $svc->saveDraft($b, mrbInput([$k => $v])))->toThrow(FinancialException::class, 'not part of a Murabaha');
    }
    expect(fn () => $svc->saveDraft($b, mrbInput(['payment_terms' => 'Late payment fee of 3% per month.'])))->toThrow(FinancialException::class)
        ->and(fn () => $svc->saveDraft($b, mrbInput(['use_promise' => true, 'promise_type' => 'BILATERAL_WITH_OPTION'])))->toThrow(FinancialException::class, 'needs an option')
        ->and(fn () => $svc->saveDraft($b, mrbInput(['use_promise' => true, 'promise_type' => 'BILATERAL'])))->toThrow(FinancialException::class, 'not supported')
        ->and(fn () => $svc->saveDraft($b, mrbInput(['wakil_id' => '1', 'wakalah_roles' => ['PURCHASE']])))->toThrow(FinancialException::class, 'Muwakkil');
    expect(Project::count())->toBe(0);
    $svc->saveDraft($b, mrbInput(['use_promise' => true, 'promise_type' => 'BILATERAL_WITH_OPTION', 'option_holder' => 'BUYER']));
    expect(Project::count())->toBe(1);
});

it('the text guard allows denials and fault-based liability but flags guarantees', function () {
    foreach (['No guaranteed return.', 'Nothing here is a guaranteed profit.', 'The capital is not guaranteed.', 'The Mudarib is liable for loss caused by negligence.'] as $ok) {
        expect(ShariahTextGuard::violations($ok))->toBe([], $ok);
    }
    foreach (['Guaranteed profit of 12%.', 'Your principal is protected.', 'The partner covers any loss.', 'Fixed returns every month.'] as $bad) {
        expect(ShariahTextGuard::violations($bad))->not->toBe([], $bad);
    }
});

/* ---- completeness gate and legacy ---- */

it('a project cannot be submitted until every required aqd term is complete; a legacy project is told to complete its terms', function () {
    $b = makeBusiness();
    $wf = app(ProjectWorkflow::class);
    $legacy = app(ProjectBuilder::class)->saveDraft($b, mudInput());   // flat input only, no aqd terms
    expect(fn () => $wf->submit($legacy, $b->user))->toThrow(FinancialException::class, 'predates the contract-specific forms');

    $partial = app(ProjectBuilder::class)->saveDraft($b, mudInput(['title' => 'Partial', 'aqd_terms' => ['business_activity' => 'Dairy trade']]));
    expect(fn () => $wf->submit($partial, $b->user))->toThrow(FinancialException::class, 'Complete the contract terms');

    $full = app(ProjectBuilder::class)->saveDraft($b, mudInput(['title' => 'Full', 'aqd_terms' => completeAqdTerms(ContractType::Mudarabah)]));
    expect($wf->submit($full, $b->user)->status->value)->toBe('REVIEW');
});

it('editing opens the project\'s own aqd form and only for its editable owner', function () {
    $b = makeBusiness();
    $p = app(ProjectBuilder::class)->saveDraft($b, mskInput(['aqd_terms' => completeAqdTerms(ContractType::Musharakah)]));
    $this->actingAs($b->user)->get(route('business.projects.edit', $p))->assertRedirect(route('business.projects.edit.musharakah', $p));
    $this->actingAs($b->user)->get(route('business.projects.edit.musharakah', $p))->assertOk()->assertSee('Create Musharakah Project');
    $this->actingAs($b->user)->get(route('business.projects.edit.mudarabah', $p))->assertNotFound();   // the wrong aqd's form never opens
    $this->actingAs(makeBusiness()->user)->get(route('business.projects.edit', $p))->assertForbidden();
    $this->actingAs(makeBusiness()->user)->get(route('business.projects.edit.musharakah', $p))->assertForbidden();
});

it('walking a Musharakah form saves the business capital terms and the loss basis is always the capital ratio', function () {
    $this->actingAs(makeBusiness()->user);
    $c = Livewire::test(MusharakahWizard::class)->set('form', aqdForm(ContractType::Musharakah));
    foreach (range(1, 4) as $n) {
        $c->call('next')->assertSet('error', null)->assertHasNoErrors();
    }
    $m = Project::first()->contract->musharakah;
    expect($m->loss_allocation_basis->value)->toBe('CAPITAL_RATIO')->and($m->investor_profit_bps)->toBe(6000)->and($m->business_contribution)->toBe(30000000);
    $c->assertSet('step', 5)->assertSee('Loss-sharing ratio (system enforced)')->assertSee('70% / 30%');
});

it('a Murabaha form with a Wakil saves the appointment as a proposal and posts nothing to the ledger', function () {
    $w = makeWakil('Rahim Enterprise');
    $this->actingAs(makeBusiness()->user);
    $c = Livewire::test(MurabahaWizard::class)->set('form', aqdForm(ContractType::Murabaha, ['wakil_id' => (string) $w->id] + wakalahTerms(['PURCHASE'])));
    foreach (range(1, 6) as $n) {
        $c->call('next')->assertSet('error', null)->assertHasNoErrors();
    }
    $p = Project::first();
    expect($p->wakil_id)->toBe($w->id)->and($p->currentWakalahAppointments()->first()->status->value)->toBe('PENDING_WAKIL_ACCEPTANCE')->and(Transaction::count())->toBe(0);
});

it('selecting an aqd or completing any form posts no ledger entry', function () {
    $this->actingAs(makeBusiness()->user);
    foreach ([[MudarabahWizard::class, ContractType::Mudarabah, 4], [MusharakahWizard::class, ContractType::Musharakah, 4], [MurabahaWizard::class, ContractType::Murabaha, 6]] as [$cls, $t, $n]) {
        $c = Livewire::test($cls)->set('form', aqdForm($t, ['title' => 'Project '.$t->value]));
        foreach (range(1, $n) as $i) {
            $c->call('next');
        }
    }
    expect(Project::count())->toBe(3)->and(Transaction::count())->toBe(0);
});
