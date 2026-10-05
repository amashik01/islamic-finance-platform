<?php

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Enums\ShariahReviewStatus as R;
use App\Enums\WakalahRole;
use App\Enums\WakalahStatus as W;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WakalahAppointment;
use App\Services\Project\ProjectBuilder;
use App\Services\Project\ProjectWorkflow;
use App\Services\Wakalah\WakalahService;
use Illuminate\Auth\Access\AuthorizationException;

/** A Murabaha draft (the only aqd with Wakalah roles) with a Wakil proposed under explicit terms. */
function wakalahDraft(array $over = [], $business = null): array
{
    $business ??= makeBusiness();
    $project = app(ProjectBuilder::class)->saveDraft($business, murabahaFormData($over));

    return [$project, $business];
}

function wakalahStaff(string $role = 'ADMIN'): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function shariahUser(): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->givePermissionTo('shariah.review');

    return $u;
}

function terms(array $roles = ['PURCHASE'], array $over = []): array
{
    $t = wakalahTerms($roles, $over);

    return ['muwakkil' => $t['muwakkil'], 'scope' => $t['wakalah_scope'], 'authority' => $t['wakalah_authority']];
}

/* ---------------------------------- eligibility ---------------------------------- */

it('1. lists and accepts an eligible Wakil', function () {
    $w = makeWakil('Rahim Enterprise');
    expect(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->toContain($w->id)
        ->and(app(WakalahService::class)->assertEligible($w->id)->id)->toBe($w->id);
});

it('2. a user without the WAKIL role cannot be selected', function () {
    $w = makeWakil('No Role Ltd', ['role' => false]);
    expect(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->not->toContain($w->id)
        ->and(fn () => app(WakalahService::class)->assertEligible($w->id))->toThrow(FinancialException::class, 'not eligible');
});

it('3. an inactive or suspended Wakil cannot be selected', function () {
    foreach ([makeWakil('Inactive User', ['active' => false]), makeWakil('Suspended Profile', ['profile_status' => 'SUSPENDED'])] as $w) {
        expect(fn () => app(WakalahService::class)->assertEligible($w->id))->toThrow(FinancialException::class);
    }
});

it('4. an unverified Wakil (KYC not approved, or email not verified) cannot be selected', function () {
    foreach ([makeWakil('Pending KYC', ['kyc' => \App\Enums\KycStatus::Pending]), makeWakil('Unverified Mail', ['verified' => false])] as $w) {
        expect(fn () => app(WakalahService::class)->assertEligible($w->id))->toThrow(FinancialException::class);
    }
});

/* ------------------------------ creation / persistence ------------------------------ */

it('5-7. a Murabaha project is created with an eligible Wakil: wakil_id, relationship, principal, scope and authority persist', function () {
    $w = makeWakil('Karim Trading');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms(['PURCHASE', 'DELIVERY_QABD']));

    expect($project->fresh()->wakil_id)->toBe($w->id)->and($project->fresh()->wakil->is($w))->toBeTrue();
    $apps = $project->currentWakalahAppointments()->orderBy('slot')->get();
    expect($apps->pluck('slot')->all())->toBe(['DELIVERY_QABD', 'PURCHASE'])
        ->and($apps->every(fn ($a) => $a->status === W::PendingWakilAcceptance && $a->muwakkil === 'BUSINESS' && $a->muwakkil_user_id === $project->business->user_id && $a->version === 1))->toBeTrue()
        ->and($apps->firstWhere('slot', 'PURCHASE')->authority)->toBe(['place_purchase_order', 'pay_supplier'])
        ->and($apps->firstWhere('slot', 'PURCHASE')->scope)->toContain('cold room')
        ->and($apps->first()->appointed_by)->toBe($project->business->user_id);
});

it('a project without a Wakil is still created (the field is optional)', function () {
    [$project] = wakalahDraft();
    expect($project->wakil_id)->toBeNull()->and($project->currentWakalahAppointments()->count())->toBe(0);
});

it('the Muwakkil, scope and authority are mandatory and validated: nothing is assumed', function () {
    $w = makeWakil('A');
    [$project, $biz] = wakalahDraft();
    $svc = app(WakalahService::class);
    $ok = terms();
    expect(fn () => $svc->assign($project, $w->id, ['PURCHASE'], $biz->user, null, ['muwakkil' => ''] + $ok))->toThrow(FinancialException::class, 'Muwakkil')
        ->and(fn () => $svc->assign($project, $w->id, ['PURCHASE'], $biz->user, null, ['muwakkil' => 'INVESTOR'] + $ok))->toThrow(FinancialException::class, 'Muwakkil')
        ->and(fn () => $svc->assign($project, $w->id, ['PURCHASE'], $biz->user, null, ['scope' => 'too short'] + $ok))->toThrow(FinancialException::class, 'scope')
        ->and(fn () => $svc->assign($project, $w->id, ['PURCHASE'], $biz->user, null, ['authority' => []] + $ok))->toThrow(FinancialException::class, 'at least one specific act')
        ->and(fn () => $svc->assign($project, $w->id, ['PURCHASE'], $biz->user, null, ['authority' => ['place_purchase_order', 'sell_goods']] + $ok))->toThrow(FinancialException::class, 'does not belong')
        ->and(fn () => $svc->assign($project, $w->id, [], $biz->user, null, $ok))->toThrow(FinancialException::class, 'Choose the Wakalah role')
        ->and(fn () => $svc->assign($project, $w->id, ['SALE'], $biz->user, null, $ok))->toThrow(FinancialException::class, 'not valid');
    expect($project->fresh()->wakil_id)->toBeNull();
});

it('selling or consuming the goods is not a delegable act for any role', function () {
    foreach (WakalahRole::cases() as $role) {
        expect(array_keys($role->acts()))->not->toContain('sell_goods')->not->toContain('consume_goods');
    }
});

it('Mudarabah and Musharakah define no Wakalah structure: a Wakil cannot be attached to them', function () {
    $w = makeWakil('A');
    foreach ([wakilFormData(), wakilFormData(['contract_type' => 'MUSHARAKAH', 'total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000'])] as $data) {
        $biz = makeBusiness();
        $project = app(ProjectBuilder::class)->saveDraft($biz, $data);
        expect(WakalahRole::forContract($project->contract_type))->toBe([])
            ->and(fn () => app(WakalahService::class)->assign($project, $w->id, ['PURCHASE'], $biz->user, null, terms()))->toThrow(FinancialException::class, 'No Wakalah structure is defined')
            ->and(fn () => app(ProjectBuilder::class)->saveDraft($biz, $data + ['wakil_id' => (string) $w->id] + wakalahTerms(), $project))->toThrow(FinancialException::class, 'No Wakalah structure');
        expect($project->fresh()->wakil_id)->toBeNull();
    }
});

/* -------------------------------------- editing -------------------------------------- */

it('8. a Draft project can change its Wakil; the old appointment is revoked and a new version opens', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project, $biz] = wakalahDraft(['wakil_id' => (string) $a->id] + wakalahTerms());
    app(ProjectBuilder::class)->saveDraft($biz, murabahaFormData(['title' => $project->title, 'wakil_id' => (string) $b->id] + wakalahTerms()), $project);

    expect($project->fresh()->wakil_id)->toBe($b->id)
        ->and(WakalahAppointment::where('project_id', $project->id)->where('wakil_id', $a->id)->first()->status)->toBe(W::Revoked)
        ->and($project->currentWakalahAppointments()->count())->toBe(1)->and($project->currentWakalahAppointments()->first()->version)->toBe(2);
});

it('9. a Needs Revision project can change its Wakil', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project] = wakalahDraft(['wakil_id' => (string) $a->id] + wakalahTerms());
    $project->forceFill(['status' => ProjectStatus::NeedsRevision])->save();
    app(WakalahService::class)->assign($project, $b->id, ['PURCHASE'], $project->business->user, null, terms());
    expect($project->fresh()->wakil_id)->toBe($b->id);
});

it('10. a funding or active project cannot have its Wakil changed, even by staff', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    foreach ([ProjectStatus::Funding, ProjectStatus::Active] as $status) {
        [$project] = wakalahDraft(['wakil_id' => (string) $a->id] + wakalahTerms());
        $project->forceFill(['status' => $status])->save();
        foreach ([$project->business->user, wakalahStaff()] as $actor) {
            expect(fn () => app(WakalahService::class)->assign($project, $b->id, ['PURCHASE'], $actor, null, terms()))->toThrow(FinancialException::class, 'Wakalah revocation workflow');
        }
        expect($project->fresh()->wakil_id)->toBe($a->id);
    }
});

it('10c. a business cannot change the Wakil of a project under review, staff can before publication', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project] = wakalahDraft(['wakil_id' => (string) $a->id] + wakalahTerms());
    $project->forceFill(['status' => ProjectStatus::Review])->save();
    expect(fn () => app(WakalahService::class)->assign($project, $b->id, ['PURCHASE'], $project->business->user, null, terms()))->toThrow(FinancialException::class);
    app(WakalahService::class)->assign($project, $b->id, ['PURCHASE'], wakalahStaff(), null, terms());
    expect($project->fresh()->wakil_id)->toBe($b->id);
});

/* ------------------------------------ authorization ----------------------------------- */

it('11. a business cannot modify another business\'s project Wakil', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft();
    $other = makeBusiness();
    expect(fn () => app(WakalahService::class)->assign($project, $w->id, ['PURCHASE'], $other->user, null, terms()))->toThrow(AuthorizationException::class);
    expect(fn () => app(ProjectBuilder::class)->saveDraft($other, murabahaFormData(['wakil_id' => (string) $w->id] + wakalahTerms()), $project))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($project->fresh()->wakil_id)->toBeNull();
});

it('12. unauthorised users (investor, the Wakil themself) cannot assign a Wakil', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft();
    expect(fn () => app(WakalahService::class)->assign($project, $w->id, ['PURCHASE'], makeInvestor()->user, null, terms()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(WakalahService::class)->assign($project, $w->id, ['PURCHASE'], $w, null, terms()))->toThrow(AuthorizationException::class);
    expect($project->fresh()->wakil_id)->toBeNull();
});

it('13. arbitrary user ids cannot bypass validation', function () {
    [$project, $biz] = wakalahDraft();
    expect(fn () => app(ProjectBuilder::class)->saveDraft($biz, murabahaFormData(['title' => $project->title, 'wakil_id' => (string) $biz->user_id] + wakalahTerms()), $project))->toThrow(AuthorizationException::class);
    foreach ([makeInvestor()->user_id, 999999, 0] as $id) {
        expect(fn () => app(ProjectBuilder::class)->saveDraft($biz, murabahaFormData(['title' => $project->title, 'wakil_id' => (string) $id] + wakalahTerms()), $project))->toThrow(FinancialException::class, 'not eligible');
    }
    expect($project->fresh()->wakil_id)->toBeNull();
});

/* --------------------------------------- lifecycle ---------------------------------------- */

it('a Wakil selection is a proposal: acceptance and an appointment-level Shariah review are both required', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    $a = $project->currentWakalahAppointments()->first();
    $svc = app(WakalahService::class);

    expect($a->status)->toBe(W::PendingWakilAcceptance)
        ->and(fn () => $svc->review($a, shariahUser(), R::Approved, 'x'))->toThrow(FinancialException::class, 'not awaiting Shariah review')   // cannot skip acceptance
        ->and(fn () => $svc->assertMayAct($project, $w, WakalahRole::Purchase, 'place_purchase_order'))->toThrow(FinancialException::class, 'no confirmed Wakalah');

    $svc->accept($a, $w);
    expect($a->fresh()->status)->toBe(W::PendingShariahReview)->and($a->fresh()->accepted_by)->toBe($w->id)
        ->and(fn () => $svc->assertMayAct($project, $w, WakalahRole::Purchase, 'place_purchase_order'))->toThrow(FinancialException::class, 'no confirmed Wakalah');

    $svc->review($a->fresh(), shariahUser(), R::Approved, 'Principal and scope are clear.');
    expect($a->fresh()->status)->toBe(W::Confirmed)->and($a->fresh()->shariah_decision)->toBe('APPROVED')->and($a->fresh()->shariah_reviewer_id)->not->toBeNull();
    expect(AuditLog::whereIn('action', ['wakalah.proposed', 'wakalah.accepted', 'wakalah.shariah_reviewed', 'wakalah.confirmed'])->count())->toBe(4);
});

it('only the appointed Wakil can accept or decline, and only a Shariah reviewer can review', function () {
    $w = makeWakil('A');
    $other = makeWakil('B');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    $a = $project->currentWakalahAppointments()->first();
    $svc = app(WakalahService::class);
    expect(fn () => $svc->accept($a, $other))->toThrow(AuthorizationException::class)
        ->and(fn () => $svc->accept($a, $project->business->user))->toThrow(AuthorizationException::class)
        ->and(fn () => $svc->reject($a, $other, 'no'))->toThrow(AuthorizationException::class);
    $svc->accept($a, $w);
    expect(fn () => $svc->review($a->fresh(), wakalahStaff('STAFF'), R::Approved, 'x'))->toThrow(FinancialException::class, 'Shariah reviewer');
    expect(fn () => $svc->review($a->fresh(), shariahUser(), R::Rejected, ''))->toThrow(FinancialException::class, 'reason');
});

it('a declined or rejected appointment ends the Wakalah and clears the project\'s Wakil', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    $a = $project->currentWakalahAppointments()->first();
    expect(fn () => app(WakalahService::class)->reject($a, $w, ''))->toThrow(FinancialException::class, 'reason');
    app(WakalahService::class)->reject($a, $w, 'Outside my business activity');
    expect($a->fresh()->status)->toBe(W::Rejected)->and($a->fresh()->is_current)->not->toBeTrue()->and($project->fresh()->wakil_id)->toBeNull()
        ->and(AuditLog::where('action', 'wakalah.rejected')->count())->toBe(1);
});

it('a Shariah rejection ends it; a revision request leaves it open for the principal to resubmit', function () {
    $w = makeWakil('A');
    [$project, $biz] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    $a = $project->currentWakalahAppointments()->first();
    app(WakalahService::class)->accept($a, $w);
    app(WakalahService::class)->review($a->fresh(), shariahUser(), R::NeedsRevision, 'State which supplier.');
    expect($a->fresh()->status)->toBe(W::Proposed)->and($a->fresh()->is_current)->toBeTrue();
    app(WakalahService::class)->assign($project, $w->id, ['PURCHASE'], $biz->user, 'Clarified supplier', ['scope' => 'Purchase the cold room units from Supplier Ltd only, against the approved invoice.'] + terms(['PURCHASE']));
    expect($project->currentWakalahAppointments()->first()->status)->toBe(W::PendingWakilAcceptance)->and($project->currentWakalahAppointments()->first()->version)->toBe(2);
});

it('a revoked Wakalah can no longer be used, and a business cannot revoke a confirmed one', function () {
    $w = makeWakil('A');
    [$project, $biz] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    $a = confirmWakalah($project->currentWakalahAppointments()->first(), $w);
    $svc = app(WakalahService::class);
    expect($svc->assertMayAct($project, $w, WakalahRole::Purchase, 'pay_supplier')->id)->toBe($a->id);

    expect(fn () => $svc->revoke($a, $biz->user, 'changed mind'))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->revoke($a, wakalahStaff(), ''))->toThrow(FinancialException::class, 'reason');
    $svc->revoke($a, wakalahStaff(), 'Principal withdrew the appointment');
    expect($a->fresh()->status)->toBe(W::Revoked)->and($project->fresh()->wakil_id)->toBeNull()
        ->and(fn () => $svc->assertMayAct($project->fresh(), $w, WakalahRole::Purchase, 'pay_supplier'))->toThrow(FinancialException::class, 'no confirmed Wakalah')
        ->and(AuditLog::where('action', 'wakalah.revoked')->count())->toBe(1);
});

it('the Wakil cannot exceed the granted scope: another role or an ungranted act is refused', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms(['PURCHASE'], ['wakalah_authority' => ['place_purchase_order']]));
    confirmWakalah($project->currentWakalahAppointments()->first(), $w);
    $svc = app(WakalahService::class);
    expect($svc->assertMayAct($project, $w, WakalahRole::Purchase, 'place_purchase_order'))->toBeInstanceOf(WakalahAppointment::class)
        ->and(fn () => $svc->assertMayAct($project, $w, WakalahRole::Purchase, 'pay_supplier'))->toThrow(FinancialException::class, 'outside the authority')
        ->and(fn () => $svc->assertMayAct($project, $w, WakalahRole::DeliveryQabd, 'take_delivery'))->toThrow(FinancialException::class, 'no confirmed Wakalah')
        ->and(fn () => $svc->assertMayAct($project, makeWakil('B'), WakalahRole::Purchase, 'place_purchase_order'))->toThrow(FinancialException::class);
});

/* --------------------------------------- audit ---------------------------------------- */

it('14-16. appointment, change and removal are audited with actor, old and new values and reason', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project, $biz] = wakalahDraft(['wakil_id' => (string) $a->id] + wakalahTerms());
    $assigned = AuditLog::where('action', 'project.wakil_assigned')->where('auditable_id', $project->id)->first();
    expect($assigned)->not->toBeNull()->and($assigned->new_values['wakil_id'])->toBe($a->id)->and($assigned->new_values['muwakkil'])->toBe('BUSINESS');

    $this->actingAs($biz->user);
    app(WakalahService::class)->assign($project, $b->id, ['PURCHASE'], $biz->user, 'Better fit', terms());
    $changed = AuditLog::where('action', 'project.wakil_changed')->where('auditable_id', $project->id)->first();
    expect($changed->old_values['wakil_id'])->toBe($a->id)->and($changed->new_values['wakil_id'])->toBe($b->id)->and($changed->reason)->toBe('Better fit')->and($changed->user_id)->toBe($biz->user_id);

    app(WakalahService::class)->assign($project, null, [], $biz->user, 'No longer needed');
    $removed = AuditLog::where('action', 'project.wakil_removed')->where('auditable_id', $project->id)->first();
    expect($removed->old_values['wakil_id'])->toBe($b->id)->and($project->fresh()->wakil_id)->toBeNull()
        ->and(WakalahAppointment::where('project_id', $project->id)->where('status', W::Revoked->value)->count())->toBe(2);
});

it('Murabaha role changes for the same Wakil are audited as a role change', function () {
    $w = makeWakil('A');
    [$project, $biz] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms(['PURCHASE', 'DELIVERY_QABD']));
    app(WakalahService::class)->assign($project, $w->id, ['ASSET_ACQUISITION'], $biz->user, null, terms(['ASSET_ACQUISITION']));
    expect(AuditLog::where('action', 'wakalah.role_changed')->count())->toBe(1)->and($project->currentWakalahAppointments()->pluck('slot')->all())->toBe(['ASSET_ACQUISITION']);
});

/* ---------------------------- no financial side effect; Murabaha order ---------------------------- */

it('selecting a Wakil does not move money, create a sale, or advance the Murabaha stage', function () {
    $w = makeWakil('A');
    $before = Transaction::count();
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms(['PURCHASE', 'ASSET_ACQUISITION', 'DELIVERY_QABD']));
    $m = $project->contract->murabaha;
    expect(Transaction::count())->toBe($before)->and($m->sale()->count())->toBe(0)->and($m->stage)->toBe(\App\Enums\MurabahaStage::Requested)
        ->and($project->currentWakalahAppointments()->count())->toBe(3);
    expect(reconcile(true)['passed'])->toBeTrue();
});

/* -------------------- project approval does not confirm; publishing waits -------------------- */

it('a project-level Shariah approval does not confirm a Wakalah, and publishing waits for the appointment', function () {
    $w = makeWakil('A');
    $project = realProject(ContractType::Murabaha, ['wakil_id' => (string) $w->id, '_publish' => false] + wakalahTerms());
    expect($project->currentWakalahAppointments()->first()->status)->toBe(W::PendingWakilAcceptance)->and($project->status)->toBe(ProjectStatus::Approved);   // the project-level approval is already recorded

    $wf = app(ProjectWorkflow::class);
    $p = \App\Models\Project::find($project->id);
    expect(fn () => $wf->publish($p, wakalahStaff()))->toThrow(FinancialException::class, 'not yet confirmed');
    confirmWakalah($p->currentWakalahAppointments()->first(), $w);
    expect($wf->publish($p->fresh(), wakalahStaff())->status)->toBe(ProjectStatus::Funding);
});

/* ------------------------------------ database integrity ------------------------------------ */

it('a Wakil with a live appointment cannot be deleted; the project keeps its Wakil', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    expect(fn () => $w->delete())->toThrow(\Illuminate\Database\QueryException::class);
    expect($project->fresh()->wakil_id)->toBe($w->id);
});

it('the database allows only one live appointment per project and role', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    expect(fn () => WakalahAppointment::unguarded(fn () => WakalahAppointment::create([
        'project_id' => $project->id, 'wakil_id' => $w->id, 'slot' => 'PURCHASE', 'status' => 'PROPOSED', 'is_current' => true, 'appointed_at' => now(),
    ])))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

/* ------------------------------- registry, pages and the Wakil portal ------------------------------- */

it('only staff with wakils.manage can register or suspend a Wakil; verification reuses the KYC review', function () {
    $admin = wakalahStaff('ADMIN');
    $staff = wakalahStaff('STAFF');
    $u = User::factory()->create();
    expect(fn () => app(\App\Services\Wakalah\WakilRegistry::class)->register($u, 'New Wakil Co', $staff))->toThrow(FinancialException::class, 'not allowed');
    $profile = app(\App\Services\Wakalah\WakilRegistry::class)->register($u, 'New Wakil Co', $admin);
    expect($u->fresh()->isWakil())->toBeTrue()->and(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->not->toContain($u->id);
    app(\App\Services\Kyc\KycService::class)->review($profile, $admin, true);
    expect(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->toContain($u->id);
    app(\App\Services\Wakalah\WakilRegistry::class)->suspend($profile, $admin, 'Compliance review');
    expect(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->not->toContain($u->id);
});

it('the Wakil portal shows only the Wakil\'s own appointments and lets only that Wakil accept', function () {
    $w = makeWakil('A');
    $other = makeWakil('B');
    [$project] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    $a = $project->currentWakalahAppointments()->first();

    $this->actingAs($w)->get(route('wakil.appointments'))->assertOk()->assertSee('Wakil for Purchase')->assertSee('Muwakkil');
    $this->actingAs($other)->get(route('wakil.appointments'))->assertOk()->assertDontSee('Wakil for Purchase');
    $this->actingAs(makeInvestor()->user)->get(route('wakil.appointments'))->assertForbidden();

    expect(fn () => \Livewire\Livewire::actingAs($other)->test(\App\Livewire\Wakil\Appointments::class)->call('accept', $a->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);   // another Wakil's appointment is a 404
    \Livewire\Livewire::actingAs($w)->test(\App\Livewire\Wakil\Appointments::class)->call('accept', $a->id)->assertSet('error', null);
    expect($a->fresh()->status)->toBe(W::PendingShariahReview);
});

it('the admin project page shows the Wakil, principal and the review controls; the business page shows only its own project', function () {
    $w = makeWakil('Noor Commerce');
    [$project, $biz] = wakalahDraft(['wakil_id' => (string) $w->id] + wakalahTerms());
    $project->forceFill(['status' => ProjectStatus::Review])->save();
    app(WakalahService::class)->accept($project->currentWakalahAppointments()->first(), $w);
    $this->actingAs(wakalahStaff('ADMIN'))->get(route('admin.projects.show', $project))->assertOk()->assertSee('Noor Commerce')->assertSee('Appointment of Wakil')->assertSee('Review Wakalah')->assertDontSee('Agent');
    $this->actingAs($biz->user)->get(route('business.projects.show', $project))->assertOk()->assertSee('Noor Commerce')->assertSee('only a proposal', false);
    $this->actingAs(makeBusiness()->user)->get(route('business.projects.show', $project))->assertForbidden();
});

it('the Murabaha form offers only eligible Wakils; Mudarabah and Musharakah forms offer no Wakil at all', function () {
    makeWakil('Rahim Enterprise');
    makeWakil('Hidden Suspended', ['active' => false]);
    $biz = makeBusiness();
    \Livewire\Livewire::actingAs($biz->user)->test(\App\Livewire\Business\Aqd\MurabahaWizard::class)->set('step', 3)
        ->assertSee('Rahim Enterprise — Wakil')->assertSee('Wakil for Purchase')->assertSee('Muwakkil')->assertDontSee('Hidden Suspended');
    foreach ([\App\Livewire\Business\Aqd\MudarabahWizard::class, \App\Livewire\Business\Aqd\MusharakahWizard::class] as $cls) {
        \Livewire\Livewire::actingAs($biz->user)->test($cls)->set('step', 3)->assertDontSee('Appointed Wakil')->assertDontSee('Wakil for Purchase');
    }
});
