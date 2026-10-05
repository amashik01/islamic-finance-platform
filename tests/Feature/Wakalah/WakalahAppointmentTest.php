<?php

use App\Enums\ContractType;
use App\Enums\ProjectStatus;
use App\Enums\WakalahRole;
use App\Enums\WakalahStatus;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WakalahAppointment;
use App\Services\Project\ProjectBuilder;
use App\Services\Project\ProjectWorkflow;
use App\Services\Wakalah\WakalahService;
use Illuminate\Auth\Access\AuthorizationException;

function wakalahDraft(?array $data = null, $business = null): array
{
    $business ??= makeBusiness();
    $project = app(ProjectBuilder::class)->saveDraft($business, $data ?? wakilFormData());

    return [$project, $business];
}

function wakalahStaff(string $role = 'ADMIN'): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
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
    $userInactive = makeWakil('Inactive User', ['active' => false]);
    $profileSuspended = makeWakil('Suspended Profile', ['profile_status' => 'SUSPENDED']);
    foreach ([$userInactive, $profileSuspended] as $w) {
        expect(fn () => app(WakalahService::class)->assertEligible($w->id))->toThrow(FinancialException::class);
    }
});

it('4. an unverified Wakil (KYC not approved, or email not verified) cannot be selected', function () {
    $kyc = makeWakil('Pending KYC', ['kyc' => \App\Enums\KycStatus::Pending]);
    $mail = makeWakil('Unverified Mail', ['verified' => false]);
    foreach ([$kyc, $mail] as $w) {
        expect(fn () => app(WakalahService::class)->assertEligible($w->id))->toThrow(FinancialException::class);
    }
});

/* ------------------------------ creation / persistence ------------------------------ */

it('5-7. a project is created with an eligible Wakil and wakil_id/relationship persist', function () {
    $w = makeWakil('Karim Trading');
    [$project] = wakalahDraft(wakilFormData(['wakil_id' => (string) $w->id]));

    expect($project->fresh()->wakil_id)->toBe($w->id)->and($project->fresh()->wakil->is($w))->toBeTrue();
    $a = $project->currentWakalahAppointments()->get();
    expect($a)->toHaveCount(1)->and($a->first()->status)->toBe(WakalahStatus::Proposed)->and($a->first()->wakalah_role)->toBeNull()
        ->and($a->first()->appointed_by)->toBe($project->business->user_id);
});

it('a project without a Wakil is still created (the field is optional)', function () {
    [$project] = wakalahDraft();
    expect($project->wakil_id)->toBeNull()->and($project->currentWakalahAppointments()->count())->toBe(0);
});

/* -------------------------------------- editing -------------------------------------- */

it('8. a Draft project can change its Wakil', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project, $biz] = wakalahDraft(wakilFormData(['wakil_id' => (string) $a->id]));
    app(ProjectBuilder::class)->saveDraft($biz, wakilFormData(['title' => $project->title, 'wakil_id' => (string) $b->id]), $project);

    expect($project->fresh()->wakil_id)->toBe($b->id)
        ->and(WakalahAppointment::where('project_id', $project->id)->where('wakil_id', $a->id)->first()->status)->toBe(WakalahStatus::Revoked)
        ->and($project->currentWakalahAppointments()->count())->toBe(1);
});

it('9. a Needs Revision project can change its Wakil', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project] = wakalahDraft(wakilFormData(['wakil_id' => (string) $a->id]));
    $project->forceFill(['status' => ProjectStatus::NeedsRevision])->save();
    app(WakalahService::class)->assign($project, $b->id, [], $project->business->user);
    expect($project->fresh()->wakil_id)->toBe($b->id);
});

it('10. a funding or active project cannot have its Wakil changed', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    foreach ([ProjectStatus::Funding, ProjectStatus::Active] as $status) {
        [$project] = wakalahDraft(wakilFormData(['wakil_id' => (string) $a->id]));
        $project->forceFill(['status' => $status])->save();
        expect(fn () => app(WakalahService::class)->assign($project, $b->id, [], $project->business->user))->toThrow(FinancialException::class, 'Wakalah revocation workflow');
        expect($project->fresh()->wakil_id)->toBe($a->id);
    }
});

/* ------------------------------------ authorization ----------------------------------- */

it('10b. staff cannot change the Wakil once the project is funding either', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project] = wakalahDraft(wakilFormData(['wakil_id' => (string) $a->id]));
    $project->forceFill(['status' => ProjectStatus::Funding])->save();
    expect(fn () => app(WakalahService::class)->assign($project, $b->id, [], wakalahStaff()))->toThrow(FinancialException::class, 'Wakalah revocation workflow');
    expect($project->fresh()->wakil_id)->toBe($a->id);
});

it('10c. a business cannot change the Wakil of a project under review', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project] = wakalahDraft(wakilFormData(['wakil_id' => (string) $a->id]));
    $project->forceFill(['status' => ProjectStatus::Review])->save();
    expect(fn () => app(WakalahService::class)->assign($project, $b->id, [], $project->business->user))->toThrow(FinancialException::class);
    // staff may, before publication
    app(WakalahService::class)->assign($project, $b->id, [], wakalahStaff());
    expect($project->fresh()->wakil_id)->toBe($b->id);
});

it('11. a business cannot modify another business\'s project Wakil', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft();
    $other = makeBusiness();
    expect(fn () => app(WakalahService::class)->assign($project, $w->id, [], $other->user))->toThrow(AuthorizationException::class);
    expect($project->fresh()->wakil_id)->toBeNull();
    expect(fn () => app(ProjectBuilder::class)->saveDraft($other, wakilFormData(['wakil_id' => (string) $w->id]), $project))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('12. unauthorised users (investor, the Wakil themself) cannot assign a Wakil', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft();
    $investor = makeInvestor();
    expect(fn () => app(WakalahService::class)->assign($project, $w->id, [], $investor->user))->toThrow(AuthorizationException::class)
        ->and(fn () => app(WakalahService::class)->assign($project, $w->id, [], $w))->toThrow(AuthorizationException::class);
    expect($project->fresh()->wakil_id)->toBeNull();
});

it('13. arbitrary user ids cannot bypass validation', function () {
    [$project, $biz] = wakalahDraft();
    $investor = makeInvestor();
    expect(fn () => app(ProjectBuilder::class)->saveDraft($biz, wakilFormData(['title' => $project->title, 'wakil_id' => (string) $biz->user_id]), $project))->toThrow(AuthorizationException::class);
    foreach ([$investor->user_id, 999999, 0] as $id) {
        expect(fn () => app(ProjectBuilder::class)->saveDraft($biz, wakilFormData(['title' => $project->title, 'wakil_id' => (string) $id]), $project))->toThrow(FinancialException::class, 'not eligible');
    }
    expect($project->fresh()->wakil_id)->toBeNull();
});

/* --------------------------------------- audit ---------------------------------------- */

it('14-16. appointment, change, role change and removal are audited', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project, $biz] = wakalahDraft(wakilFormData(['wakil_id' => (string) $a->id]));
    $assigned = AuditLog::where('action', 'project.wakil_assigned')->where('auditable_id', $project->id)->first();
    expect($assigned)->not->toBeNull()->and($assigned->new_values['wakil_id'])->toBe($a->id)->and($assigned->user_id ?? null)->toBeNull();   // CLI-style call: no auth user

    $this->actingAs($biz->user);
    app(WakalahService::class)->assign($project, $b->id, [], $biz->user, 'Better fit');
    $changed = AuditLog::where('action', 'project.wakil_changed')->where('auditable_id', $project->id)->first();
    expect($changed->old_values['wakil_id'])->toBe($a->id)->and($changed->new_values['wakil_id'])->toBe($b->id)->and($changed->reason)->toBe('Better fit')->and($changed->user_id)->toBe($biz->user_id);

    app(WakalahService::class)->assign($project, null, [], $biz->user, 'No longer needed');
    $removed = AuditLog::where('action', 'project.wakil_removed')->where('auditable_id', $project->id)->first();
    expect($removed->old_values['wakil_id'])->toBe($b->id)->and($project->fresh()->wakil_id)->toBeNull()
        ->and($project->currentWakalahAppointments()->count())->toBe(0)
        ->and(WakalahAppointment::where('project_id', $project->id)->where('status', WakalahStatus::Revoked->value)->count())->toBe(2);
});

/* ---------------------------------- Wakalah role (Murabaha) ---------------------------------- */

it('Murabaha needs an explicit Wakalah role per appointment and rejects roles that do not exist', function () {
    $w = makeWakil('A');
    [$project, $biz] = wakalahDraft(murabahaFormData());
    $svc = app(WakalahService::class);
    expect(fn () => $svc->assign($project, $w->id, [], $biz->user))->toThrow(FinancialException::class, 'Choose the Wakalah role')
        ->and(fn () => $svc->assign($project, $w->id, ['SALE'], $biz->user))->toThrow(FinancialException::class, 'not valid');

    $svc->assign($project, $w->id, [WakalahRole::Purchase->value, WakalahRole::DeliveryQabd->value], $biz->user);
    expect($project->currentWakalahAppointments()->pluck('wakalah_role')->map->value->sort()->values()->all())->toBe(['DELIVERY_QABD', 'PURCHASE']);

    // Changing only the role set for the same Wakil is a role change, audited separately.
    $svc->assign($project, $w->id, [WakalahRole::AssetAcquisition->value], $biz->user);
    expect(AuditLog::where('action', 'wakalah.role_changed')->count())->toBe(1)
        ->and($project->currentWakalahAppointments()->pluck('slot')->all())->toBe(['ASSET_ACQUISITION']);
});

it('Mudarabah and Musharakah define no Wakalah roles, so a role cannot be attached', function () {
    $w = makeWakil('A');
    foreach ([wakilFormData(), wakilFormData(['contract_type' => 'MUSHARAKAH', 'total_capital' => '1000000', 'investor_contribution' => '700000', 'business_contribution' => '300000'])] as $data) {
        [$project, $biz] = wakalahDraft($data);
        expect(WakalahRole::forContract($project->contract_type))->toBe([])
            ->and(fn () => app(WakalahService::class)->assign($project, $w->id, ['PURCHASE'], $biz->user))->toThrow(FinancialException::class, 'not defined');
    }
});

it('selecting a Wakil does not move money, create a sale, or advance the Murabaha stage', function () {
    $w = makeWakil('A');
    $before = Transaction::count();
    [$project, $biz] = wakalahDraft(murabahaFormData(['wakil_id' => (string) $w->id, 'wakalah_roles' => ['PURCHASE', 'ASSET_ACQUISITION', 'DELIVERY_QABD']]));
    $m = $project->contract->murabaha;

    expect(Transaction::count())->toBe($before)->and($m->sale()->count())->toBe(0)->and($m->stage)->toBe(\App\Enums\MurabahaStage::Requested)
        ->and($project->currentWakalahAppointments()->count())->toBe(3);
    expect(reconcile(true)['passed'])->toBeTrue();
});

/* -------------------------- Wakil selected != Wakalah approved -------------------------- */

it('a proposed Wakalah is confirmed only by the Shariah review, and publishing waits for it', function () {
    $w = makeWakil('A');
    $b = makeWakil('B');
    $project = realProject(ContractType::Mudarabah, ['wakil_id' => (string) $w->id]);
    expect($project->currentWakalahAppointments()->first()->status)->toBe(WakalahStatus::Confirmed)
        ->and(AuditLog::where('action', 'wakalah.appointment_confirmed')->count())->toBe(1);

    // A staff change before publication re-proposes; publishing is refused until the review is recorded again.
    $p2 = realProject(ContractType::Mudarabah, ['wakil_id' => (string) $w->id]);
    $p2->forceFill(['status' => ProjectStatus::Approved])->save();
    app(WakalahService::class)->assign($p2, $b->id, [], wakalahStaff());
    expect($p2->currentWakalahAppointments()->first()->status)->toBe(WakalahStatus::Proposed)
        ->and(fn () => app(ProjectWorkflow::class)->publish($p2->fresh(), wakalahStaff()))->toThrow(FinancialException::class, 'Wakalah appointment has not been confirmed');
});

/* ------------------------------------ database integrity ------------------------------------ */

it('a Wakil with a live appointment cannot be deleted; the project keeps its Wakil', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft(wakilFormData(['wakil_id' => (string) $w->id]));
    expect(fn () => $w->delete())->toThrow(\Illuminate\Database\QueryException::class);
    expect($project->fresh()->wakil_id)->toBe($w->id);
});

it('the database allows only one live appointment per project and role', function () {
    $w = makeWakil('A');
    [$project] = wakalahDraft(wakilFormData(['wakil_id' => (string) $w->id]));
    expect(fn () => WakalahAppointment::unguarded(fn () => WakalahAppointment::create([
        'project_id' => $project->id, 'wakil_id' => $w->id, 'slot' => 'GENERAL', 'status' => 'PROPOSED', 'is_current' => true, 'appointed_at' => now(),
    ])))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

it('projects.wakil_id always matches the live appointments', function () {
    $a = makeWakil('A');
    $b = makeWakil('B');
    [$project, $biz] = wakalahDraft(murabahaFormData(['wakil_id' => (string) $a->id, 'wakalah_roles' => ['PURCHASE']]));
    app(WakalahService::class)->assign($project, $b->id, ['DELIVERY_QABD'], $biz->user);
    app(WakalahService::class)->assign($project, $b->id, ['DELIVERY_QABD', 'PURCHASE'], $biz->user);
    $wakils = $project->currentWakalahAppointments()->pluck('wakil_id')->unique()->all();
    expect($wakils)->toBe([$b->id])->and($project->fresh()->wakil_id)->toBe($b->id);
});

/* ------------------------------- registry & admin management ------------------------------- */

it('only staff with wakils.manage can register or suspend a Wakil; verification reuses the KYC review', function () {
    seedRoles();
    $admin = wakalahStaff('ADMIN');
    $staff = wakalahStaff('STAFF');
    $u = User::factory()->create();

    expect(fn () => app(\App\Services\Wakalah\WakilRegistry::class)->register($u, 'New Wakil Co', $staff))->toThrow(FinancialException::class, 'not allowed');
    $profile = app(\App\Services\Wakalah\WakilRegistry::class)->register($u, 'New Wakil Co', $admin);
    expect($u->fresh()->isWakil())->toBeTrue()->and($profile->kyc_status)->toBe(\App\Enums\KycStatus::Pending)
        ->and(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->not->toContain($u->id);

    app(\App\Services\Kyc\KycService::class)->review($profile, $admin, true);
    expect(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->toContain($u->id);

    app(\App\Services\Wakalah\WakilRegistry::class)->suspend($profile, $admin, 'Compliance review');
    expect(app(WakalahService::class)->eligibleWakils()->pluck('user_id')->all())->not->toContain($u->id)
        ->and(AuditLog::whereIn('action', ['wakil.registered', 'wakil.suspended', 'kyc.approved'])->count())->toBe(3);
});

it('a user holding another platform role cannot be registered as a Wakil', function () {
    $admin = wakalahStaff('ADMIN');
    $inv = makeInvestor();
    expect(fn () => app(\App\Services\Wakalah\WakilRegistry::class)->register($inv->user, 'X', $admin))->toThrow(FinancialException::class);
});

it('the admin project page shows the Wakil and the staff appointment form; the business page shows only its own', function () {
    $w = makeWakil('Noor Commerce');
    [$project, $biz] = wakalahDraft(wakilFormData(['wakil_id' => (string) $w->id]));
    $project->forceFill(['status' => ProjectStatus::Review])->save();
    $this->actingAs(wakalahStaff('ADMIN'))->get(route('admin.projects.show', $project))->assertOk()->assertSee('Noor Commerce')->assertSee('Appointment of Wakil')->assertDontSee('Agent');
    $this->actingAs($biz->user)->get(route('business.projects.show', $project))->assertOk()->assertSee('Noor Commerce')->assertSee('not an approved Wakalah', false);
    $this->actingAs(makeBusiness()->user)->get(route('business.projects.show', $project))->assertForbidden();
});

it('the Wakalah selector lists only eligible Wakils in the wizard', function () {
    $ok = makeWakil('Rahim Enterprise');
    makeWakil('Hidden Suspended', ['active' => false]);
    makeWakil('Hidden Unverified', ['kyc' => \App\Enums\KycStatus::Pending]);
    $biz = makeBusiness();
    \Livewire\Livewire::actingAs($biz->user)->test(\App\Livewire\Business\ProjectWizard::class)
        ->set('form.contract_type', 'MURABAHA')->set('step', 3)
        ->assertSee('Rahim Enterprise — Wakil')->assertSee('Wakil for Purchase')->assertSee('Wakil for Delivery / Qabd')
        ->assertDontSee('Hidden Suspended')->assertDontSee('Hidden Unverified');
});
