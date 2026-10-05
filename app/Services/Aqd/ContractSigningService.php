<?php

namespace App\Services\Aqd;

use App\Enums\ContractDocumentKind as K;
use App\Enums\ContractDocumentStatus as S;
use App\Enums\ContractType;
use App\Enums\KycStatus;
use App\Enums\SignerRole as R;
use App\Exceptions\FinancialException;
use App\Models\ContractDocument;
use App\Models\ContractSignature;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Wakalah\WakalahService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Review -> disclosure -> consent -> signature -> execution. A signature is bound to the exact document hash; the
 * signer must be a required party of THAT document, verified, re-authenticated and must type their registered name.
 * The method is an electronic typed-name signature with password re-confirmation; its legal weight under Bangladeshi
 * law is a question for counsel (docs/shariah/OPEN_SCHOLAR_QUESTIONS.md).
 */
class ContractSigningService
{
    public const METHOD = 'TYPED_NAME_PASSWORD';

    public const CONSENT_VERSION = 'CONSENT-1';

    public function __construct(private AuditLogger $audit, private WakalahService $wakalah) {}

    /** The disclosure the signer must read and accept; hashed into the signature so the exact wording is evidenced. */
    public function consentText(ContractDocument $d): string
    {
        return 'I have read the full '.$d->kind->label().' ('.$d->reference.', version '.$d->version_no.'). I understand its disclosure of risk: an ordinary commercial loss is borne as the agreement states, no return or capital is guaranteed, and nothing in it is a fatwa or a Shariah certification. I sign this exact document, identified by hash '.$d->document_hash.', electronically and knowingly.';
    }

    /** @return list<array{user: ?int, role: R}> user null = any staff member with contracts.manage */
    public function requiredSigners(ContractDocument $d): array
    {
        $project = $d->project;
        $business = $project->business->user_id;
        $type = $project->contract_type;

        return match ($d->kind) {
            K::MasterAqd => [['user' => $business, 'role' => match ($type) { ContractType::Mudarabah => R::Mudarib, ContractType::Musharakah => R::MusharikBusiness, ContractType::Murabaha => R::Buyer }]],
            K::Participation => [['user' => $d->party_user_id, 'role' => $type === ContractType::Musharakah ? R::MusharikInvestor : R::RabbUlMal]],
            K::Wakalah => array_filter([
                ['user' => $d->party_user_id, 'role' => R::Wakil],
                $d->wakalah_appointment_id && ($a = \App\Models\WakalahAppointment::find($d->wakalah_appointment_id)) && $a->muwakkil === 'BUSINESS' ? ['user' => $business, 'role' => R::Muwakkil] : ['user' => null, 'role' => R::Muwakkil],
            ]),
            K::MurabahaSale => [['user' => $business, 'role' => R::Buyer], ['user' => null, 'role' => R::Seller]],
        };
    }

    /** @return R|null the role this user may sign as on this document, if any and not yet signed */
    public function roleFor(ContractDocument $d, User $u): ?R
    {
        $signed = $d->signatures()->get()->map(fn ($s) => $s->signer_role->value)->all();
        foreach ($this->requiredSigners($d) as $req) {
            if (in_array($req['role']->value, $signed, true)) {
                continue;
            }
            if ($req['user'] === $u->id || ($req['user'] === null && $u->isStaffMember() && $u->can('contracts.manage'))) {
                return $req['role'];
            }
        }

        return null;
    }

    public function sign(ContractDocument $document, User $signer, string $typedName, string $password, bool $consent): ContractSignature
    {
        return DB::transaction(function () use ($document, $signer, $typedName, $password, $consent) {
            $d = ContractDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($d->status !== S::PendingSignature) {
                throw new FinancialException($d->status === S::Draft ? 'This agreement has not completed Shariah review and cannot be signed yet.' : 'This agreement is not open for signature ('.$d->status->label().').');
            }
            if (! $d->hashIntact()) {
                throw new FinancialException('The agreement does not match its recorded hash. It was altered and cannot be signed.');
            }
            $role = $this->roleFor($d, $signer);
            if (! $role) {
                throw new AuthorizationException('You are not a signatory of this agreement, or you have already signed it.');
            }
            if (! $consent) {
                throw new FinancialException('Confirm that you have read the agreement and accept the disclosure.');
            }
            if ($this->norm($typedName) !== $this->norm($signer->name)) {
                throw new FinancialException('Type your full registered name exactly as shown to sign.');
            }
            if (! Hash::check($password, $signer->password)) {
                throw new FinancialException('Your password is incorrect.');
            }
            $identity = $this->identity($d, $signer, $role);
            $this->assertProjectState($d);

            $sig = ContractSignature::unguarded(fn () => ContractSignature::create([
                'contract_document_id' => $d->id, 'signer_user_id' => $signer->id, 'signer_role' => $role->value, 'signature_method' => self::METHOD, 'signature_data' => $signer->name,
                'document_hash' => $d->document_hash, 'consent_version' => self::CONSENT_VERSION, 'consent_text_hash' => hash('sha256', $this->consentText($d)),
                'identity_check' => $identity, 'ip_address' => request()?->ip(), 'user_agent' => mb_substr((string) request()?->userAgent(), 0, 255), 'signed_at' => now(),
            ]));
            $this->audit->record('aqd.consent_given', $d->project, null, ['reference' => $d->reference, 'consent_version' => self::CONSENT_VERSION, 'role' => $role->value]);
            $this->audit->record('aqd.signed', $d->project, null, ['reference' => $d->reference, 'role' => $role->value, 'document_hash' => $d->document_hash, 'signature_id' => $sig->id]);

            $signedRoles = $d->signatures()->get()->map(fn ($s) => $s->signer_role->value)->all();
            if (collect($this->requiredSigners($d))->every(fn ($r) => in_array($r['role']->value, $signedRoles, true))) {
                $d->forceFill(['status' => S::Executed, 'executed_at' => now()])->save();
                $d->supersedes?->forceFill(['status' => S::Superseded])->save();
                $this->audit->record('aqd.executed', $d->project, null, ['reference' => $d->reference, 'kind' => $d->kind->value, 'document_hash' => $d->document_hash]);
                $d->supersedes && $this->audit->record('aqd.superseded', $d->project, ['reference' => $d->supersedes->reference], ['by' => $d->reference]);
            }

            return $sig;
        }, 3);
    }

    /** First view of a document by a user is audited (disclosure evidence). */
    public function recordViewed(ContractDocument $d, User $u): void
    {
        $seen = \App\Models\AuditLog::where('action', 'aqd.viewed')->where('user_id', $u->id)->where('auditable_type', $d->project->getMorphClass())->where('auditable_id', $d->project_id)->where('new_values->reference', $d->reference)->exists();
        $seen || $this->audit->record('aqd.viewed', $d->project, null, ['reference' => $d->reference, 'document_hash' => $d->document_hash]);
    }

    /** Re-checks a stored signature against the stored document (used by reconciliation and the document page). */
    public function signatureValid(ContractSignature $s): bool
    {
        return $s->document_hash === $s->document->document_hash && $s->document->hashIntact();
    }

    private function norm(string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $v)));
    }

    /** @return array<string, mixed> */
    private function identity(ContractDocument $d, User $u, R $role): array
    {
        $check = match (true) {
            in_array($role, [R::RabbUlMal, R::MusharikInvestor], true) => ['kyc' => $u->investor?->kyc_status?->value],
            in_array($role, [R::Mudarib, R::MusharikBusiness, R::Buyer, R::Muwakkil], true) && $u->business => ['kyc' => $u->business->kyc_status->value],
            $role === R::Wakil => ['kyc' => $u->wakilProfile?->kyc_status?->value, 'eligible' => $u->wakilProfile?->status === 'ACTIVE'],
            default => ['staff' => true],
        };
        $required = ($check['kyc'] ?? KycStatus::Approved->value) !== KycStatus::Approved->value;
        if ($required) {
            throw new FinancialException('Your identity verification must be approved before you can sign.');
        }
        if ($role === R::Wakil) {
            $this->wakalah->assertEligible($u->id);
        }

        return $check + ['role' => $role->value];
    }

    private function assertProjectState(ContractDocument $d): void
    {
        $status = $d->project->status->value;
        $ok = match ($d->kind) {
            K::MasterAqd => in_array($status, ['APPROVED', 'FUNDING', 'ACTIVE'], true),
            K::Participation => $status === 'FUNDING',
            default => true,
        };
        if (! $ok) {
            throw new FinancialException('The project is not in a state where this agreement can be signed.');
        }
    }
}
