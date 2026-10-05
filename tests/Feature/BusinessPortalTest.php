<?php

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Livewire\Business\Aqd\MudarabahWizard;
use App\Livewire\Business\Aqd\MurabahaWizard;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('renders every business page', function (string $uri) {
    $b = makeBusiness();
    $this->actingAs($b->user)->get($uri)->assertOk();
})->with(['/business', '/business/projects', '/business/projects/create', '/business/funding', '/business/contracts', '/business/payments', '/business/settlements', '/business/documents', '/business/notifications', '/business/profile']);

it('walks the dedicated Mudarabah form and submits it for review', function () {
    Storage::fake('private');
    $b = makeBusiness();
    $this->actingAs($b->user);
    $c = Livewire::test(MudarabahWizard::class)->set('form', aqdForm(ContractType::Mudarabah));
    foreach (range(1, 4) as $n) {
        $c->call('next')->assertSet('step', $n + 1)->assertSet('error', null)->assertHasNoErrors();
    }
    expect(Project::count())->toBe(1);
    $p = Project::first();
    expect($p->status)->toBe(ProjectStatus::Draft)->and($p->contract->mudarabah->investor_profit_bps)->toBe(7000)->and($p->contract->aqd_form_version)->toBe('MUDARABAH-FORM-1')
        ->and($p->contract->aqd_terms['permitted_activities'])->not->toBeEmpty();

    $c->call('next')->call('next')->assertSet('step', 7);
    $c->set('docTitle', 'Plan')->set('docFile', UploadedFile::fake()->createWithContent('plan.pdf', "%PDF-1.4\n%%EOF"))->call('uploadDocument')->assertHasNoErrors();
    expect($p->documents()->count())->toBe(1);
    $c->call('next')->assertSet('step', 8)->assertSee('Contract preview')->call('submit');
    expect($p->fresh()->status)->toBe(ProjectStatus::Review);
});

it('validates each step and blocks progress, keeping entered state', function () {
    $this->actingAs(makeBusiness()->user);
    $c = Livewire::test(MudarabahWizard::class)->call('next')->assertHasErrors(['form.title', 'form.description'])->assertSet('step', 1);
    $c->set('form', aqdForm(ContractType::Mudarabah))->call('next')->assertSet('step', 2)->call('back')->assertSet('step', 1)->assertSet('form.title', 'Dairy Expansion');
    $c->call('next')->set('form.permitted_activities', '')->call('next')->assertHasErrors('form.permitted_activities')->assertSet('step', 2);
});

it('blocks mudarabah ratios that do not total 100% on the server', function () {
    $this->actingAs(makeBusiness()->user);
    $c = Livewire::test(MudarabahWizard::class)->set('form', aqdForm(ContractType::Mudarabah, ['business_profit' => '20']));
    $c->call('next')->assertSet('step', 1)->assertSet('error', fn ($e) => str_contains($e, 'total 100%'));   // refused on the server before anything is saved
    expect(Project::count())->toBe(0);
});

it('shows the Murabaha cost + profit = price calculation and saves the sale structure', function () {
    $this->actingAs(makeBusiness()->user);
    $c = Livewire::test(MurabahaWizard::class)->set('form', aqdForm(ContractType::Murabaha));
    foreach (range(1, 5) as $n) {
        $c->call('next')->assertSet('error', null)->assertHasNoErrors();
    }
    $c->assertSet('step', 6)->assertSee('BDT 100,000.00')->assertSee('BDT 110,000.00')->assertSee('not interest')->call('next')->assertSet('step', 7);
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
