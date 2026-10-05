<?php

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Livewire\Business\ProjectWizard;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('renders every business page', function (string $uri) {
    $b = makeBusiness();
    $this->actingAs($b->user)->get($uri)->assertOk();
})->with(['/business', '/business/projects', '/business/projects/create', '/business/funding', '/business/contracts', '/business/payments', '/business/settlements', '/business/documents', '/business/notifications', '/business/profile']);

function fillBasics($c)
{
    return $c->set('form.title', 'Dairy Expansion')->set('form.description', 'Expanding our dairy farm with a second shed and cold chain storage for growth.')
        ->set('form.industry', 'Agriculture')->set('form.purpose', 'Build shed')->set('form.key_risks', 'Milk price and disease risk')->set('form.duration_months', '12');
}

it('walks the wizard for a Mudarabah project and submits it for review', function () {
    Storage::fake('private');
    $b = makeBusiness();
    $this->actingAs($b->user);
    $c = fillBasics(Livewire::test(ProjectWizard::class))->call('next')->assertSet('step', 2)->assertHasNoErrors();
    $c->set('form.contract_type', 'MUDARABAH')->call('next')->assertSet('step', 3);
    $c->set('form.investor_profit', '70')->set('form.business_profit', '30')->set('form.business_plan', 'Detailed plan')->call('next')->assertSet('step', 4);
    $c->set('form.capital_required', '100000')->set('form.minimum_amount', '5000')->call('next')->assertSet('step', 5)->assertSet('error', null);

    expect(Project::count())->toBe(1);
    $p = Project::first();
    expect($p->status)->toBe(ProjectStatus::Draft)->and($p->contract->mudarabah->investor_profit_bps)->toBe(7000);

    $c->set('docTitle', 'Plan')->set('docFile', UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n%%EOF"))->call('uploadDocument')->assertHasNoErrors();
    expect($p->documents()->count())->toBe(1);
    $c->call('next')->assertSet('step', 6)->call('next')->assertSet('step', 7)->call('submit');
    expect($p->fresh()->status)->toBe(ProjectStatus::Review);
});

it('validates each step and blocks progress, keeping entered state', function () {
    $this->actingAs(makeBusiness()->user);
    $c = Livewire::test(ProjectWizard::class)->call('next')->assertHasErrors(['form.title', 'form.description'])->assertSet('step', 1);
    fillBasics($c)->call('next')->assertSet('step', 2)->call('back')->assertSet('step', 1)->assertSet('form.title', 'Dairy Expansion');
    $c->call('next')->call('next')->assertHasErrors('form.contract_type')->assertSet('step', 2);
});

it('blocks mudarabah ratios that do not total 100% on the server', function () {
    $this->actingAs(makeBusiness()->user);
    $c = fillBasics(Livewire::test(ProjectWizard::class))->call('next')->set('form.contract_type', 'MUDARABAH')->call('next');
    $c->set('form.investor_profit', '70')->set('form.business_profit', '20')->set('form.business_plan', 'x')->call('next')
        ->set('form.capital_required', '100000')->call('next')->assertSet('step', 4)->assertSet('error', 'Investor and business profit ratios must be positive and total 100%.');
    expect(Project::count())->toBe(0);
});

it('shows the Murabaha cost + profit = price calculation and saves the sale structure', function () {
    $this->actingAs(makeBusiness()->user);
    $c = fillBasics(Livewire::test(ProjectWizard::class))->call('next')->set('form.contract_type', 'MURABAHA')->call('next');
    $c->set('form.delivery_terms', 'Delivered to shop')->set('form.payment_terms', '4 monthly installments')->set('form.installments', '4')
        ->set('form.ownership_info', 'Bought by financier in own name')->set('form.possession_info', 'Held in warehouse before sale')->call('next');
    $c->set('form.asset_name', 'Refrigerators')->set('form.supplier', 'Supplier Ltd')->set('form.quantity', '4')->set('form.unit_cost', '25000')->set('form.sale_profit', '10000')
        ->assertSee('BDT 100,000.00')->assertSee('BDT 110,000.00')->assertSee('not interest')->call('next')->assertSet('step', 5);
    $m = Project::first()->contract->murabaha;
    expect($m->sale_price)->toBe(11000000)->and($m->delivery_terms)->toContain('Possession (qabd)');
});

it('a business cannot open or edit another business\'s project', function () {
    $mine = makeProject();
    $other = makeProject();
    $this->actingAs($mine->business->user)->get(route('business.projects.show', $other))->assertForbidden();
    $this->actingAs($mine->business->user)->get(route('business.projects.edit', $other))->assertForbidden();
    $this->actingAs($mine->business->user)->get(route('business.projects.show', $mine))->assertOk();
});

it('a business cannot edit an approved project', function () {
    $p = makeProject(['status' => ProjectStatus::Approved]);
    $this->actingAs($p->business->user)->get(route('business.projects.edit', $p))->assertForbidden();
});

it('lists only the business\'s own projects and shows revision feedback', function () {
    $mine = makeProject(['title' => 'Mine Only', 'status' => ProjectStatus::NeedsRevision]);
    makeProject(['title' => 'Someone Elses']);
    \App\Models\AuditLog::create(['action' => 'project.requestRevision', 'auditable_type' => $mine->getMorphClass(), 'auditable_id' => $mine->id, 'reason' => 'Add supplier quotes']);
    $this->actingAs($mine->business->user)->get('/business/projects')->assertSee('Mine Only')->assertDontSee('Someone Elses');
    $this->actingAs($mine->business->user)->get(route('business.projects.show', $mine))->assertSee('Revision requested')->assertSee('Add supplier quotes');
});

it('keeps investor identities hidden from the business funding view', function () {
    $inv = makeInvestor(10000000);
    $p = makeProject(['title' => 'Funded One']);
    app(\App\Services\Wallet\InvestmentService::class)->invest($inv, $p, \App\Support\Money\Money::minor(1000000), 'bf');
    $this->actingAs($p->business->user)->get('/business/funding')->assertSee('Funded One')->assertDontSee($inv->user->name)->assertDontSee($inv->user->email);
});
