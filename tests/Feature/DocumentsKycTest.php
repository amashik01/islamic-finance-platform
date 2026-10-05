<?php

use App\Enums\DocumentCategory;
use App\Enums\DocumentVerificationStatus;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Document\DocumentService;
use App\Services\Kyc\KycService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('private'));

function pdf(string $name = 'nid.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

function reviewer(UserRole $role = UserRole::Manager): User
{
    seedRoles();
    $u = User::factory()->create();
    $u->assignRole($role->value);

    return $u;
}

it('stores uploads privately under a random name and records the document', function () {
    $investor = makeInvestor(0, false);
    $doc = app(DocumentService::class)->upload($investor, pdf('../../evil.pdf'), DocumentCategory::Kyc, 'National ID', $investor->user);

    expect($doc->disk)->toBe('private')->and($doc->path)->toStartWith('KYC/')->and($doc->path)->not->toContain('evil')
        ->and($doc->mime_type)->toBe('application/pdf')->and($doc->verification_status)->toBe(DocumentVerificationStatus::Pending);
    Storage::disk('private')->assertExists($doc->path);
    expect(AuditLog::where('action', 'document.uploaded')->count())->toBe(1);
});

it('rejects executables, scripts and oversize files', function () {
    $investor = makeInvestor(0, false);
    $svc = app(DocumentService::class);
    expect(fn () => $svc->upload($investor, UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET[1]);'), DocumentCategory::Kyc, 'x', $investor->user))
        ->toThrow(FinancialException::class, 'Only PDF');
    expect(fn () => $svc->upload($investor, UploadedFile::fake()->createWithContent('photo.pdf', '<?php echo 1;'), DocumentCategory::Kyc, 'x', $investor->user))
        ->toThrow(FinancialException::class, 'Only PDF'); // wrong content disguised with a .pdf name
    expect(fn () => $svc->upload($investor, UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'), DocumentCategory::Kyc, 'x', $investor->user))
        ->toThrow(FinancialException::class);
    expect(\App\Models\Document::count())->toBe(0);
});

it('versions a re-upload and keeps the previous file', function () {
    $investor = makeInvestor(0, false);
    $svc = app(DocumentService::class);
    $v1 = $svc->upload($investor, pdf(), DocumentCategory::Kyc, 'National ID', $investor->user);
    $v2 = $svc->upload($investor, pdf(), DocumentCategory::Kyc, 'National ID', $investor->user);

    expect($v2->version)->toBe(2)->and($v2->previous_version_id)->toBe($v1->id);
    Storage::disk('private')->assertExists([$v1->path, $v2->path]);
});

it('serves documents only to authorised users, with no guessable public URL', function () {
    $owner = makeInvestor(0, false);
    $doc = app(DocumentService::class)->upload($owner, pdf(), DocumentCategory::Kyc, 'NID', $owner->user);

    $this->get(route('documents.show', $doc))->assertRedirect('/login');
    $this->actingAs($owner->user)->get(route('documents.show', $doc))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(makeInvestor()->user)->get(route('documents.show', $doc))->assertForbidden();   // another investor
    $this->actingAs(makeBusiness()->user)->get(route('documents.show', $doc))->assertForbidden();   // cross-role
    $this->actingAs(reviewer(UserRole::Staff))->get(route('documents.show', $doc))->assertOk();     // KYC reviewer
    expect(Storage::disk('private')->path($doc->path))->not->toContain('/public/');
});

it('lets non-KYC reviewers see business documents but not identity documents', function () {
    $owner = makeInvestor(0, false);
    $doc = app(DocumentService::class)->upload($owner, pdf(), DocumentCategory::Kyc, 'NID', $owner->user);
    $noKyc = reviewer(UserRole::Staff);
    $noKyc->revokePermissionTo('kyc.view');
    $noKyc->forgetCachedPermissions();
    // Staff role has kyc.view via role; remove via a role without it:
    $limited = User::factory()->create();
    $limited->givePermissionTo('projects.view');
    $this->actingAs($limited)->get(route('documents.show', $doc))->assertForbidden();
});

it('kyc submission requires the mandatory documents', function () {
    $investor = makeInvestor(0, false);
    $investor->forceFill(['kyc_status' => KycStatus::NotSubmitted])->save();
    expect(fn () => app(KycService::class)->submit($investor))->toThrow(FinancialException::class, 'Upload the required documents');

    app(DocumentService::class)->upload($investor, pdf(), DocumentCategory::Kyc, 'NID', $investor->user);
    app(KycService::class)->submit($investor->fresh());
    expect($investor->fresh()->kyc_status)->toBe(KycStatus::Pending);
});

it('business kyc needs registration documents too', function () {
    $business = makeBusiness();
    $business->forceFill(['kyc_status' => KycStatus::NotSubmitted])->save();
    app(DocumentService::class)->upload($business, pdf(), DocumentCategory::Kyc, 'Owner ID', $business->user);
    expect(fn () => app(KycService::class)->submit($business->fresh()))->toThrow(FinancialException::class, 'Business registration');
});

it('only authorised reviewers can approve or reject, rejection needs a reason, and it is audited', function () {
    $investor = makeInvestor(0, false);
    $investor->forceFill(['kyc_status' => KycStatus::Pending])->save();
    $svc = app(KycService::class);

    expect(fn () => $svc->review($investor, reviewer(UserRole::Staff), true))->toThrow(FinancialException::class, 'not allowed');   // staff can review, not approve
    expect(fn () => $svc->review($investor, reviewer(), false))->toThrow(FinancialException::class, 'reason');

    $svc->review($investor, $mgr = reviewer(), true);
    expect($investor->fresh()->kyc_status)->toBe(KycStatus::Approved)->and(AuditLog::where('action', 'kyc.approved')->count())->toBe(1);
    expect(fn () => $svc->review($investor->fresh(), $mgr, false, 'late'))->toThrow(FinancialException::class, 'pending');
});
