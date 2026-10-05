<?php

namespace App\Services\Wakalah;

use App\Enums\ProjectStatus as S;
use App\Enums\ShariahReviewStatus;
use App\Enums\WakalahPrincipal;
use App\Enums\WakalahRole;
use App\Enums\WakalahStatus as W;
use App\Exceptions\FinancialException;
use App\Models\Project;
use App\Models\User;
use App\Models\WakalahAppointment;
use App\Models\WakilProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Wakalah (agency) appointments.
 *
 * A Wakalah is an agency relationship: a Muwakkil (principal) delegates a defined scope to a Wakil. It is separate from the
 * Mudarabah / Musharakah / Murabaha aqd itself and it moves no money. Selecting a Wakil is a PROPOSAL only:
 *
 *   PENDING_WAKIL_ACCEPTANCE -> (Wakil accepts) -> PENDING_SHARIAH_REVIEW -> (reviewer approves) -> CONFIRMED
 *   either step can end in REJECTED; CONFIRMED can end in REVOKED. Each step is audited with who and when.
 *
 * Rules WAK-DEFINITION, WAK-RESTRICTED, WAK-ACCEPTANCE, WAK-PRINCIPAL-ROLE, MUR-AGENT-NO-DISPOSAL. The Muwakkil is never
 * assumed. Mudarabah and Musharakah define no Wakalah structure, so a Wakil cannot be attached to them.
 */
class WakalahService
{
    /** Who may change the appointment, and until when. After funding starts, replacement is a separate revocation workflow. */
    private const BUSINESS_MAY_CHANGE = [S::Draft, S::NeedsRevision];

    private const STAFF_MAY_CHANGE = [S::Draft, S::NeedsRevision, S::Review, S::Approved];

    public function __construct(private AuditLogger $audit, private SettingsService $settings) {}

    /** @return Collection<int, WakilProfile> only Wakils that may receive an appointment */
    public function eligibleWakils(): Collection
    {
        return WakilProfile::eligible()->with('user:id,name')->orderBy('display_name')->get();
    }

    /** Server-side eligibility: the submitted id is never trusted. Messages are deliberately uniform (no user enumeration). */
    public function assertEligible(int $userId): User
    {
        $profile = WakilProfile::eligible()->where('user_id', $userId)->with('user')->first();
        if (! $profile) {
            throw new FinancialException('The selected Wakil is not eligible for appointment.');
        }

        return $profile->user;
    }

    /**
     * Sets the Project's appointed Wakil, Wakalah role(s), principal, scope and authority; a null Wakil removes it.
     *
     * @param  list<string>  $roles  WakalahRole values (required: a Wakalah without an explicit role does not exist)
     * @param  array{muwakkil?: string, scope?: string, authority?: list<string>, evidence?: ?string}  $terms
     */
    public function assign(Project $project, ?int $wakilId, array $roles, User $actor, ?string $reason = null, array $terms = []): Project
    {
        return DB::transaction(function () use ($project, $wakilId, $roles, $actor, $reason, $terms) {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->assertMayChange($p, $actor);

            $current = $p->currentWakalahAppointments()->lockForUpdate()->get();
            if ($wakilId === null) {
                return $this->remove($p, $current, $actor, $reason);
            }
            if ($wakilId === $actor->id) {
                throw new AuthorizationException('You cannot appoint yourself as Wakil.');
            }
            $wakil = $this->assertEligible($wakilId);
            $plan = $this->plan($p, $roles, $terms);

            $oldWakilId = $p->wakil_id;
            $sig = fn (WakalahAppointment $a) => $a->slot.'|'.$a->muwakkil.'|'.md5((string) $a->scope).'|'.implode(',', collect($a->authority ?? [])->sort()->values()->all());
            $newSigs = collect($plan)->map(fn ($x, $slot) => $slot.'|'.$x['muwakkil'].'|'.md5($x['scope']).'|'.implode(',', collect($x['authority'])->sort()->values()->all()))->sort()->values()->all();
            if ($oldWakilId === $wakil->id && $current->every(fn ($a) => $a->status !== W::Proposed) && $current->map($sig)->sort()->values()->all() === $newSigs) {
                return $p;   // nothing to change
            }

            $oldRoles = $current->pluck('slot')->sort()->values()->all();
            $version = 1 + (int) $current->max('version');
            foreach ($current as $old) {
                $this->revoke0($old, $actor, $reason ?? ($oldWakilId === $wakil->id ? 'Wakalah terms changed' : 'Wakil changed'));
            }
            foreach ($plan as $slot => $x) {
                $this->open($p, $wakil, $slot, $x, $actor, $version);
            }
            $p->forceFill(['wakil_id' => $wakil->id])->save();

            $new = ['wakil_id' => $wakil->id, 'wakil' => $wakil->name, 'roles' => array_keys($plan), 'muwakkil' => collect($plan)->first()['muwakkil'], 'status' => $p->currentWakalahAppointments()->first()->status->value];
            if ($oldWakilId === $wakil->id) {
                $this->audit->record('wakalah.role_changed', $p, ['wakil_id' => $wakil->id, 'roles' => $oldRoles], $new, $reason);
            } else {
                $this->audit->record($oldWakilId ? 'project.wakil_changed' : 'project.wakil_assigned', $p, $oldWakilId ? ['wakil_id' => $oldWakilId, 'roles' => $oldRoles] : null, $new, $reason);
            }
            $this->audit->record('wakalah.proposed', $p, null, $new, $reason);

            return $p->fresh();
        }, 3);
    }

    /** The Wakil accepts the appointment. Only that Wakil, and only while they remain eligible. */
    public function accept(WakalahAppointment $appointment, User $by): WakalahAppointment
    {
        return DB::transaction(function () use ($appointment, $by) {
            $a = WakalahAppointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($a->wakil_id !== $by->id) {
                throw new AuthorizationException('Only the appointed Wakil can accept this appointment.');
            }
            $this->assertEligible($by->id);
            if (! $a->is_current || $a->status !== W::PendingWakilAcceptance) {
                throw new FinancialException('This appointment is not awaiting acceptance.');
            }
            $a->forceFill(['accepted_by' => $by->id, 'accepted_at' => now()]);
            $this->advance($a);
            $this->audit->record('wakalah.accepted', $a->project, ['status' => 'PENDING_WAKIL_ACCEPTANCE'], ['status' => $a->status->value, 'slot' => $a->slot, 'accepted_by' => $by->id], null);

            return $a;
        });
    }

    public function reject(WakalahAppointment $appointment, User $by, string $reason): WakalahAppointment
    {
        return DB::transaction(function () use ($appointment, $by, $reason) {
            $a = WakalahAppointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($a->wakil_id !== $by->id) {
                throw new AuthorizationException('Only the appointed Wakil can reject this appointment.');
            }
            if (! $a->is_current || $a->status !== W::PendingWakilAcceptance) {
                throw new FinancialException('This appointment is not awaiting acceptance.');
            }
            if (blank($reason)) {
                throw new FinancialException('Give a reason for declining the appointment.');
            }
            $a->forceFill(['status' => W::Rejected, 'is_current' => null, 'rejected_at' => now(), 'rejection_reason' => mb_substr($reason, 0, 500)])->save();
            $this->dropProjectWakilIfNone($a->project);
            $this->audit->record('wakalah.rejected', $a->project, ['status' => 'PENDING_WAKIL_ACCEPTANCE'], ['status' => 'REJECTED', 'slot' => $a->slot], $reason);

            return $a;
        });
    }

    /** Appointment-level Shariah review. A project-level approval does not confirm a Wakalah. */
    public function review(WakalahAppointment $appointment, User $reviewer, ShariahReviewStatus $decision, ?string $notes = null): WakalahAppointment
    {
        if (! $reviewer->can('shariah.review')) {
            throw new FinancialException('Only a Shariah reviewer can review a Wakalah appointment.');
        }
        if ($decision === ShariahReviewStatus::Pending) {
            throw new FinancialException('Choose approve, reject or request revision.');
        }
        if ($decision !== ShariahReviewStatus::Approved && blank($notes)) {
            throw new FinancialException('Record the reason for the decision.');
        }

        return DB::transaction(function () use ($appointment, $reviewer, $decision, $notes) {
            $a = WakalahAppointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if (! $a->is_current || $a->status !== W::PendingShariahReview) {
                throw new FinancialException('This appointment is not awaiting Shariah review.');
            }
            $a->forceFill(['shariah_reviewer_id' => $reviewer->id, 'shariah_reviewed_at' => now(), 'shariah_decision' => $decision->value, 'shariah_notes' => $notes]);
            match ($decision) {
                ShariahReviewStatus::Approved => $a->forceFill(['status' => W::Confirmed, 'confirmed_at' => now()])->save(),
                ShariahReviewStatus::Rejected => $a->forceFill(['status' => W::Rejected, 'is_current' => null, 'rejected_at' => now(), 'rejection_reason' => mb_substr((string) $notes, 0, 500)])->save(),
                default => $a->forceFill(['status' => W::Proposed])->save(),   // revision requested: the principal must resubmit
            };
            $decision === ShariahReviewStatus::Rejected && $this->dropProjectWakilIfNone($a->project);
            $this->audit->record('wakalah.shariah_reviewed', $a->project, ['status' => 'PENDING_SHARIAH_REVIEW'], ['status' => $a->status->value, 'slot' => $a->slot, 'decision' => $decision->value], $notes);
            $a->status === W::Confirmed && $this->audit->record('wakalah.confirmed', $a->project, null, ['slot' => $a->slot, 'wakil_id' => $a->wakil_id, 'reviewer_id' => $reviewer->id], $notes);
            // The confirmed appointment is also issued as a Wakalah document for the Wakil and the Muwakkil to sign (LEGACY projects have none).
            if ($a->status === W::Confirmed && $a->project->contract?->aqd_form_version !== null) {
                app(\App\Services\Aqd\ContractGenerator::class)->wakalah($a->fresh(), $reviewer);
            }

            return $a;
        });
    }

    /** Ends a live appointment (any stage). Staff or the Wakil may revoke; a business cannot revoke a confirmed Wakalah. */
    public function revoke(WakalahAppointment $appointment, User $by, string $reason): WakalahAppointment
    {
        if (blank($reason)) {
            throw new FinancialException('Give a reason for revoking the Wakalah.');
        }

        return DB::transaction(function () use ($appointment, $by, $reason) {
            $a = WakalahAppointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $isStaff = $by->isStaffMember() && $by->can('projects.edit');
            if (! $a->is_current) {
                throw new FinancialException('This appointment is no longer live.');
            }
            if (! $isStaff && $by->id !== $a->wakil_id) {
                throw new AuthorizationException('You are not allowed to revoke this Wakalah.');
            }
            $old = $a->status->value;
            $this->revoke0($a, $by, $reason);
            $this->dropProjectWakilIfNone($a->project);
            $this->audit->record('wakalah.revoked', $a->project, ['status' => $old, 'slot' => $a->slot], ['status' => 'REVOKED', 'wakil_id' => $a->wakil_id], $reason);

            return $a;
        });
    }

    /**
     * Authority check for an act performed under a Wakalah. A pending, rejected or revoked appointment, another role's
     * act, or an act outside the recorded authority is refused: the Wakil cannot exceed the scope the principal set.
     */
    public function assertMayAct(Project $project, User $wakil, WakalahRole $role, string $act): WakalahAppointment
    {
        $a = $project->currentWakalahAppointments()->where('wakil_id', $wakil->id)->where('slot', $role->value)->first();
        if (! $a || $a->status !== W::Confirmed) {
            throw new FinancialException('There is no confirmed Wakalah for this role.');
        }
        if (! in_array($act, $a->authority ?? [], true)) {
            throw new FinancialException('That act is outside the authority granted in this Wakalah.');
        }

        return $a;
    }

    /** True when the project has a live appointment that has not completed acceptance and review. */
    public function hasUnconfirmed(Project $project): bool
    {
        return $project->currentWakalahAppointments()->where('status', '!=', W::Confirmed->value)->exists();
    }

    /* ------------------------------------------------------------------------------------------------------------- */

    private function remove(Project $p, Collection $current, User $actor, ?string $reason): Project
    {
        if ($p->wakil_id === null && $current->isEmpty()) {
            return $p;
        }
        $old = ['wakil_id' => $p->wakil_id, 'roles' => $current->pluck('slot')->sort()->values()->all()];
        foreach ($current as $a) {
            $this->revoke0($a, $actor, $reason ?? 'Wakil removed');
        }
        $p->forceFill(['wakil_id' => null])->save();
        $this->audit->record('project.wakil_removed', $p, $old, ['wakil_id' => null], $reason);

        return $p->fresh();
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, array{role: WakalahRole, muwakkil: string, scope: string, authority: list<string>, evidence: ?string}> slot => terms
     */
    private function plan(Project $p, array $roles, array $terms): array
    {
        $valid = WakalahRole::forContract($p->contract_type);
        if ($valid === []) {
            throw new FinancialException('No Wakalah structure is defined for '.$p->contract_type->label().'. A Wakil cannot be attached to it.');
        }
        $roles = array_values(array_unique(array_map('strval', $roles)));
        if ($roles === []) {
            throw new FinancialException('Choose the Wakalah role the Wakil is appointed for.');
        }
        $muwakkil = WakalahPrincipal::tryFrom((string) ($terms['muwakkil'] ?? ''));
        if (! $muwakkil) {
            throw new FinancialException('Choose the Muwakkil (the principal who appoints the Wakil). It is never assumed.');
        }
        $scope = trim((string) ($terms['scope'] ?? ''));
        if (mb_strlen($scope) < 20) {
            throw new FinancialException('Describe the scope of the Wakalah (at least 20 characters): what the Wakil may do, for which asset.');
        }
        $granted = array_values(array_unique(array_map('strval', (array) ($terms['authority'] ?? []))));

        $out = [];
        foreach ($roles as $value) {
            $role = WakalahRole::tryFrom($value);
            if (! $role || ! in_array($role, $valid, true)) {
                throw new FinancialException('That Wakalah role is not valid for this contract type.');
            }
            if (! in_array($muwakkil, $role->allowedPrincipals(), true)) {
                throw new FinancialException('That principal is not valid for '.$role->label().'.');
            }
            $authority = array_values(array_intersect($granted, array_keys($role->acts())));
            if ($authority === []) {
                throw new FinancialException('Grant at least one specific act for '.$role->label().'.');
            }
            $out[$role->value] = ['role' => $role, 'muwakkil' => $muwakkil->value, 'scope' => $scope, 'authority' => $authority, 'evidence' => $terms['evidence'] ?? null];
        }
        $unknown = array_diff($granted, collect($out)->flatMap(fn ($x) => array_keys($x['role']->acts()))->all());
        if ($unknown) {
            throw new FinancialException('An act was granted that does not belong to the chosen role(s).');
        }
        ksort($out);

        return $out;
    }

    private function open(Project $p, User $wakil, string $slot, array $x, User $actor, int $version): WakalahAppointment
    {
        $business = $x['muwakkil'] === WakalahPrincipal::Business->value ? $p->business?->user_id : null;
        $a = WakalahAppointment::unguarded(fn () => WakalahAppointment::create([
            'project_id' => $p->id, 'contract_id' => $p->contract?->id, 'wakil_id' => $wakil->id, 'wakalah_role' => $x['role']->value, 'slot' => $slot,
            'muwakkil' => $x['muwakkil'], 'muwakkil_user_id' => $business, 'scope' => $x['scope'], 'authority' => $x['authority'], 'version' => $version, 'evidence_reference' => $x['evidence'],
            'status' => W::PendingWakilAcceptance, 'is_current' => true, 'appointed_by' => $actor->id, 'appointed_at' => now(),
        ]));
        if (! $this->settings->bool('shariah.wakalah_requires_acceptance')) {
            $this->advance($a);   // governance-approved exception: no explicit acceptance step (audited via the setting change)
        }

        return $a;
    }

    /** After acceptance: to the appointment-level review, or straight to CONFIRMED when governance turned the review off. */
    private function advance(WakalahAppointment $a): void
    {
        $a->forceFill(['status' => $this->settings->bool('shariah.wakalah_requires_review') ? W::PendingShariahReview : W::Confirmed]);
        $a->status === W::Confirmed && $a->forceFill(['confirmed_at' => now()]);
        $a->save();
    }

    private function revoke0(WakalahAppointment $a, User $actor, string $reason): void
    {
        $a->forceFill(['status' => W::Revoked, 'is_current' => null, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => mb_substr($reason, 0, 500)])->save();
    }

    private function dropProjectWakilIfNone(Project $project): void
    {
        if (! $project->currentWakalahAppointments()->exists()) {
            $project->forceFill(['wakil_id' => null])->save();
        }
    }

    private function assertMayChange(Project $p, User $actor): void
    {
        $isStaff = $actor->isStaffMember() && $actor->can('projects.edit');
        $isOwner = $actor->isBusiness() && $actor->can('projects.edit') && $actor->business?->id === $p->business_id;
        if (! $isStaff && ! $isOwner) {
            throw new AuthorizationException('You are not allowed to change the Wakalah appointment of this project.');
        }
        $allowed = $isStaff ? self::STAFF_MAY_CHANGE : self::BUSINESS_MAY_CHANGE;
        if (! in_array($p->status, $allowed, true)) {
            throw new FinancialException('The appointed Wakil can no longer be edited in this project status. Replacement after contract execution needs a separate Wakalah revocation workflow.');
        }
    }
}
