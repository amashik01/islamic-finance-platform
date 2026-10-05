<?php

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Document;
use App\Models\User;
use App\Services\Wallet\InvestmentService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Gate;

function staffUser(UserRole $role): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole($role->value);

    return $u;
}

it('redirects guests to login for every portal', function (string $uri) {
    $this->get($uri)->assertRedirect('/login');
})->with(['/investor', '/investor/wallet', '/business', '/business/projects', '/admin', '/admin/dashboard', '/admin/ledger']);

it('keeps each role inside its own portal', function () {
    $investor = makeInvestor()->user;
    $business = makeBusiness()->user;

    $this->actingAs($investor)->get('/admin/dashboard')->assertForbidden();
    $this->actingAs($investor)->get('/business')->assertForbidden();
    $this->actingAs($business)->get('/investor')->assertForbidden();
    $this->actingAs($business)->get('/admin/dashboard')->assertForbidden();
});

it('limits staff to their permissions', function () {
    $staff = staffUser(UserRole::Staff);
    $this->actingAs($staff)->get('/admin/dashboard')->assertOk();
    $this->actingAs($staff)->get('/admin/investors')->assertOk();
    $this->actingAs($staff)->get('/admin/ledger')->assertForbidden();
    $this->actingAs($staff)->get('/admin/settings')->assertForbidden();
    $this->actingAs($staff)->get('/admin/audit-logs')->assertForbidden();
    expect($staff->can('ledger.adjust'))->toBeFalse()->and($staff->can('withdrawals.approve'))->toBeFalse();
});

it('only admins can adjust the ledger or manage roles', function () {
    expect(staffUser(UserRole::Admin)->can('ledger.adjust'))->toBeTrue()
        ->and(staffUser(UserRole::Manager)->can('ledger.adjust'))->toBeFalse()
        ->and(staffUser(UserRole::Manager)->can('roles.manage'))->toBeFalse();
});

it('prevents role escalation through mass assignment', function () {
    seedRoles();
    $u = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'role' => 'ADMIN', 'status' => 'X', 'email_verified_at' => now()]);
    expect($u->hasAnyRole(['ADMIN', 'MANAGER', 'STAFF']))->toBeFalse()->and($u->email_verified_at)->toBeNull();
});

it('stops an investor from viewing another investor\'s investment (IDOR)', function () {
    $a = makeInvestor(10000000);
    $b = makeInvestor();
    $inv = app(InvestmentService::class)->invest($a, makeProject(), Money::minor(1000000), 'idor-1');

    expect(Gate::forUser($a->user)->allows('view', $inv))->toBeTrue()
        ->and(Gate::forUser($b->user)->allows('view', $inv))->toBeFalse()
        ->and(Gate::forUser(staffUser(UserRole::Staff))->allows('view', $inv))->toBeTrue();
});

it('stops a business from viewing or editing another business\'s project', function () {
    $mine = makeProject();
    $other = makeProject();
    $owner = $mine->business->user;

    expect(Gate::forUser($owner)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $other))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $other))->toBeFalse();
});

it('freezes projects for the business once approved', function () {
    $draft = makeProject(['status' => ProjectStatus::Draft]);
    $approved = makeProject(['status' => ProjectStatus::Approved]);
    expect(Gate::forUser($draft->business->user)->allows('update', $draft))->toBeTrue()
        ->and(Gate::forUser($approved->business->user)->allows('update', $approved))->toBeFalse();
});

it('keeps contracts private to participants', function () {
    $investor = makeInvestor(10000000);
    $project = makeProject();
    $contract = new Contract(['contract_number' => 'T-1', 'contract_type' => $project->contract_type, 'project_id' => $project->id]);
    $contract->save();
    app(InvestmentService::class)->invest($investor, $project->fresh(), Money::minor(1000000), 'c-1');

    expect(Gate::forUser($investor->user)->allows('view', $contract))->toBeTrue()
        ->and(Gate::forUser(makeInvestor()->user)->allows('view', $contract))->toBeFalse()
        ->and(Gate::forUser(makeBusiness()->user)->allows('view', $contract))->toBeFalse();
});

it('protects private documents from other users (cross-investor access)', function () {
    $owner = makeInvestor();
    $doc = new Document(['category' => 'KYC', 'title' => 'NID', 'original_name' => 'nid.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'uploaded_by' => $owner->user_id]);
    $doc->forceFill(['path' => 'kyc/nid.pdf', 'disk' => 'private']);
    $doc->documentable()->associate($owner);
    $doc->save();

    expect(Gate::forUser($owner->user)->allows('view', $doc))->toBeTrue()
        ->and(Gate::forUser(makeInvestor()->user)->allows('view', $doc))->toBeFalse()
        ->and(Gate::forUser(makeBusiness()->user)->allows('view', $doc))->toBeFalse()
        ->and(Gate::forUser(staffUser(UserRole::Staff))->allows('view', $doc))->toBeTrue();
});

it('rejects forged posts without a CSRF token', function () {
    $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false; // enforce CSRF as in production
        }
    });

    $this->actingAs(makeInvestor()->user)->post('/logout')->assertStatus(419);
});
