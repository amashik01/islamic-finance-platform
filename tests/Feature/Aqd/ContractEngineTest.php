<?php

use App\Enums\ContractDocumentKind as K;
use App\Enums\ContractDocumentStatus as S;
use App\Enums\ContractType;
use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\ContractAmendment;
use App\Models\ContractDocument;
use App\Models\User;
use App\Services\Aqd\ContractAmendmentService;
use App\Services\Aqd\ContractGenerator;
use App\Services\Aqd\ContractSigningService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

function executedMaster(\App\Models\Project $p): ContractDocument
{
    return ContractDocument::where('contract_id', $p->contract->id)->where('kind', K::MasterAqd->value)->where('status', S::Executed->value)->latest('id')->firstOrFail();
}

it('an executed agreement is immutable in the model and in the database', function () {
    $doc = executedMaster(realProject());
    expect(fn () => $doc->update(['content' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $doc->delete())->toThrow(LogicException::class);
    expect(fn () => \DB::table('contract_documents')->where('id', $doc->id)->update(['content' => 'changed']))->toThrow(QueryException::class)
        ->and(fn () => \DB::table('contract_documents')->where('id', $doc->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => \DB::table('contract_signatures')->where('contract_document_id', $doc->id)->update(['signature_data' => 'forged']))->toThrow(QueryException::class);
    expect($doc->fresh()->hashIntact())->toBeTrue();
});

it('every signature is bound to the exact document hash and records the consent evidence', function () {
    $project = realProject();
    $doc = executedMaster($project);
    $sig = $doc->signatures()->first();
    expect($sig->document_hash)->toBe($doc->document_hash)->and($sig->signer_user_id)->toBe($project->business->user_id)
        ->and($sig->consent_text_hash)->toBe(hash('sha256', app(ContractSigningService::class)->consentText($doc)))->and($sig->identity_check)->toBeArray()
        ->and(app(ContractSigningService::class)->signatureValid($sig))->toBeTrue();
    expect(AuditLog::whereIn('action', ['aqd.generated', 'aqd.consent_given', 'aqd.signed', 'aqd.executed', 'aqd.shariah_approved'])->pluck('action')->unique()->count())->toBe(5);
});

it('refuses to sign a document whose text no longer matches its hash', function () {
    $project = realProject(ContractType::Mudarabah, ['_publish' => false]);
    $doc = ContractDocument::where('contract_id', $project->contract->id)->where('status', S::PendingSignature->value)->firstOrFail();
    \DB::table('contract_documents')->where('id', $doc->id)->update(['content' => $doc->content.' (tampered)']);
    expect(fn () => signDoc($doc, $project->business->user))->toThrow(FinancialException::class, 'altered');
});

it('only the named signatory can sign, with the exact name, the password and the consent', function () {
    $project = realProject(ContractType::Mudarabah, ['_publish' => false]);
    $doc = ContractDocument::where('contract_id', $project->contract->id)->where('status', S::PendingSignature->value)->firstOrFail();
    $svc = app(ContractSigningService::class);
    $owner = $project->business->user;
    expect(fn () => $svc->sign($doc, User::factory()->create(), 'x', 'password', true))->toThrow(AuthorizationException::class)
        ->and(fn () => $svc->sign($doc, $owner, $owner->name, 'password', false))->toThrow(FinancialException::class, 'read the agreement')
        ->and(fn () => $svc->sign($doc, $owner, 'Someone Else', 'password', true))->toThrow(FinancialException::class, 'full registered name')
        ->and(fn () => $svc->sign($doc, $owner, $owner->name, 'wrong-password', true))->toThrow(FinancialException::class, 'password');
    expect($doc->signatures()->count())->toBe(0);
    $svc->sign($doc, $owner, $owner->name, 'password', true);
    expect($doc->fresh()->status)->toBe(S::Executed)->and(fn () => $svc->sign($doc->fresh(), $owner, $owner->name, 'password', true))->toThrow(FinancialException::class);
});

it('a draft agreement cannot be signed before the Shariah review approves it', function () {
    $project = realProject(ContractType::Mudarabah, ['_publish' => false]);
    $draft = app(ContractGenerator::class)->master($project->fresh(), aqdReviewer());   // a fresh generation after approval is pending signature
    expect($draft->status)->toBe(S::PendingSignature);
    $fresh = realProject(ContractType::Mudarabah, ['_publish' => false]);
    \App\Models\ShariahReview::where('project_id', $fresh->id)->delete();
    $d = app(ContractGenerator::class)->master($fresh->fresh(), aqdReviewer());
    expect($d->status)->toBe(S::Draft)->and(fn () => signDoc($d, $fresh->business->user))->toThrow(FinancialException::class, 'Shariah review');
});

it('keeps private agreements private: parties and staff only; the business never sees an investor participation agreement', function () {
    $project = realProject();
    $inv = makeInvestor(20000000);
    $other = makeInvestor(20000000);
    $part = signedParticipation($inv, $project, 1000000);
    $master = executedMaster($project);
    $staff = User::factory()->create();
    $staff->assignRole(\App\Enums\UserRole::Admin->value);

    expect(Gate::forUser($inv->user)->allows('view', $part))->toBeTrue()
        ->and(Gate::forUser($other->user)->allows('view', $part))->toBeFalse()
        ->and(Gate::forUser($project->business->user)->allows('view', $part))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('view', $part))->toBeTrue()
        ->and(Gate::forUser($project->business->user)->allows('view', $master))->toBeTrue()
        ->and(Gate::forUser(makeBusiness()->user)->allows('view', $master))->toBeFalse()
        ->and(Gate::forUser($other->user)->allows('view', $master))->toBeFalse();

    $this->actingAs($other->user)->get(route('agreements.show', $part->reference))->assertForbidden();
    $this->actingAs($other->user)->get(route('agreements.pdf', $part->reference))->assertForbidden();
    $this->actingAs($inv->user)->get(route('agreements.show', $part->reference))->assertOk()->assertSee($part->document_hash);
    $this->actingAs($inv->user)->get(route('agreements.pdf', $part->reference))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(AuditLog::where('action', 'aqd.viewed')->where('user_id', $inv->user_id)->exists())->toBeTrue();
});

it('an amendment creates a new version, keeps the old one intact, and supersedes it only when the new one is signed', function () {
    $project = realProject();
    $v1 = executedMaster($project);
    $oldHash = $v1->document_hash;
    $svc = app(ContractAmendmentService::class);
    $owner = $project->business->user;

    $a = $svc->request($v1, $owner, 'Clarify the business plan.', ['permitted_activities' => 'Revised plan: sell packaged goods wholesale.']);
    expect(fn () => $svc->review($a, User::factory()->create(), ShariahReviewStatus::Approved, 'x'))->toThrow(FinancialException::class, 'Only a Shariah reviewer');
    $a = $svc->review($a, aqdReviewer(), ShariahReviewStatus::Approved, 'Acceptable.');
    $v2 = $a->toDocument;
    expect($v2->version_no)->toBe(2)->and($v2->status)->toBe(S::PendingSignature)->and($v2->supersedes_id)->toBe($v1->id)->and($v2->content)->toContain('Revised plan')
        ->and($v1->fresh()->status)->toBe(S::Executed)->and($v1->fresh()->document_hash)->toBe($oldHash)->and($v1->fresh()->content)->not->toContain('Revised plan');

    signDoc($v2, $owner);
    expect($v2->fresh()->status)->toBe(S::Executed)->and($v1->fresh()->status)->toBe(S::Superseded)->and($v1->fresh()->hashIntact())->toBeTrue()->and($a->fresh()->status)->toBe('EXECUTED');
    expect(AuditLog::whereIn('action', ['aqd.amended', 'aqd.superseded'])->count())->toBe(2);
});

it('an amendment of an agreement that investors are bound by needs a recorded legal review, and a rejected one changes nothing', function () {
    $project = realProject();
    fund(makeInvestor(20000000), $project, 1000000, 'amend-i');
    $v1 = executedMaster($project);
    $svc = app(ContractAmendmentService::class);
    $a = $svc->request($v1, $project->business->user, 'Change permitted activities.', ['permitted_activities' => 'New plan text.']);
    expect($a->legal_review_required)->toBeTrue();
    expect(fn () => $svc->review($a, aqdReviewer(), ShariahReviewStatus::Approved, 'ok'))->toThrow(FinancialException::class, 'legal review');
    $rejected = $svc->review($a->fresh(), aqdReviewer(), ShariahReviewStatus::Rejected, 'Not acceptable.');
    expect($rejected->status)->toBe('REJECTED')->and(ContractDocument::where('contract_id', $v1->contract_id)->where('kind', K::MasterAqd->value)->where('status', '!=', S::Cancelled->value)->count())->toBe(1)
        ->and($project->contract->fresh()->aqd_terms['permitted_activities'])->not->toBe('New plan text.');
});

it('an amendment cannot introduce a prohibited term', function () {
    $v1 = executedMaster(realProject());
    expect(fn () => app(ContractAmendmentService::class)->request($v1, $v1->project->business->user, 'Add a guarantee.', ['permitted_activities' => 'The capital is guaranteed by the Mudarib.']))->toThrow(FinancialException::class);
});

it('a template version without a Shariah review approval cannot generate agreements', function () {
    $project = realProject(ContractType::Mudarabah, ['_publish' => false]);
    \App\Models\ContractTemplateVersion::query()->update(['shariah_review_status' => 'PENDING']);
    expect(fn () => app(ContractGenerator::class)->master($project->fresh(), aqdReviewer()))->toThrow(FinancialException::class);
});

it('outside the sandbox no investment is accepted until the platform role is approved; each investment consumes its own agreement', function () {
    $project = realProject();
    $inv = makeInvestor(20000000);
    $doc = signedParticipation($inv, $project, 1000000);
    $svc = app(\App\Services\Wallet\InvestmentService::class);
    config(['finance.sandbox' => false]);
    expect(fn () => $svc->invest($inv, $project->fresh(), \App\Support\Money\Money::minor(1000000), 'role-1', $doc))->toThrow(FinancialException::class, 'contractual role');
    app(\App\Services\Settings\SettingsService::class)->save(['shariah.platform_role' => 'DIRECT_ARRANGER'], 'test');
    $investment = $svc->invest($inv, $project->fresh(), \App\Support\Money\Money::minor(1000000), 'role-1', $doc);
    expect($investment->participation_document_id)->toBe($doc->id)->and($doc->fresh()->consumed_by_investment_id)->toBe($investment->id);
    // The same agreement cannot back a second investment, nor one of a different amount.
    expect(fn () => $svc->invest($inv, $project->fresh(), \App\Support\Money\Money::minor(1000000), 'role-2', $doc->fresh()))->toThrow(FinancialException::class)
        ->and(fn () => $svc->invest($inv, $project->fresh(), \App\Support\Money\Money::minor(2000000), 'role-3'))->toThrow(FinancialException::class);
});
