<?php

namespace App\Services\Wakalah;

use App\Enums\ProjectStatus as S;
use App\Enums\WakalahRole;
use App\Enums\WakalahStatus;
use App\Exceptions\FinancialException;
use App\Models\Project;
use App\Models\User;
use App\Models\WakalahAppointment;
use App\Models\WakilProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Wakalah (agency) appointments for Projects.
 *
 * A Wakalah appointment is an agency relationship. It is separate from the Mudarabah / Musharakah / Murabaha contract
 * itself and it moves no money: nothing here touches the ledger. Selecting a Wakil only PROPOSES an appointment; it is
 * CONFIRMED when the project's Shariah review is approved (see ProjectWorkflow). The software never self-certifies.
 */
class WakalahService
{
    /** Who may change the appointment, and until when. After funding starts, replacement needs its own controlled workflow. */
    private const BUSINESS_MAY_CHANGE = [S::Draft, S::NeedsRevision];

    private const STAFF_MAY_CHANGE = [S::Draft, S::NeedsRevision, S::Review, S::Approved];

    public function __construct(private AuditLogger $audit) {}

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
     * Sets the Project's appointed Wakil and Wakalah role(s); null removes the appointment.
     *
     * @param  list<string>  $roles  WakalahRole values; required where the contract defines roles, forbidden where it does not
     */
    public function assign(Project $project, ?int $wakilId, array $roles, User $actor, ?string $reason = null): Project
    {
        return DB::transaction(function () use ($project, $wakilId, $roles, $actor, $reason) {
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
            $slots = $this->slots($p, $roles);

            $oldWakilId = $p->wakil_id;
            $oldRoles = $this->roleValues($current);
            $newRoles = array_keys($slots);
            sort($newRoles);

            if ($oldWakilId === $wakil->id && $oldRoles === $newRoles) {
                return $p;   // nothing to change
            }

            if ($oldWakilId === $wakil->id) {
                // Same Wakil, different Wakalah role(s): revoke only the removed roles, add only the new ones.
                foreach ($current->whereNotIn('slot', $newRoles) as $gone) {
                    $this->revoke($gone, $actor, $reason ?? 'Wakalah role removed');
                }
                foreach (array_diff($newRoles, $oldRoles) as $slot) {
                    $this->open($p, $wakil, $slots[$slot], $slot, $actor);
                }
                $this->audit->record('wakalah.role_changed', $p, ['wakil_id' => $wakil->id, 'roles' => $oldRoles], ['wakil_id' => $wakil->id, 'roles' => $newRoles], $reason);

                return $p->fresh();
            }

            foreach ($current as $old) {
                $this->revoke($old, $actor, $reason ?? 'Wakil changed');
            }
            foreach ($slots as $slot => $role) {
                $this->open($p, $wakil, $role, $slot, $actor);
            }
            $p->forceFill(['wakil_id' => $wakil->id])->save();
            $this->audit->record($oldWakilId ? 'project.wakil_changed' : 'project.wakil_assigned', $p,
                $oldWakilId ? ['wakil_id' => $oldWakilId, 'roles' => $oldRoles] : null,
                ['wakil_id' => $wakil->id, 'wakil' => $wakil->name, 'roles' => $newRoles, 'status' => WakalahStatus::Proposed->value], $reason);

            return $p->fresh();
        }, 3);
    }

    /** Called when the project's Shariah review is approved: proposed appointments become confirmed. */
    public function confirmForProject(Project $project, User $by): int
    {
        return DB::transaction(function () use ($project, $by) {
            $n = 0;
            foreach ($project->currentWakalahAppointments()->where('status', WakalahStatus::Proposed->value)->lockForUpdate()->get() as $a) {
                $a->forceFill(['status' => WakalahStatus::Confirmed, 'confirmed_at' => now()])->save();
                $this->audit->record('wakalah.appointment_confirmed', $project, ['status' => 'PROPOSED'], ['wakil_id' => $a->wakil_id, 'role' => $a->slot, 'status' => 'CONFIRMED'], 'Shariah review approved');
                $n++;
            }

            return $n;
        });
    }

    /** True when the project has a live appointment that the Shariah review has not yet confirmed. */
    public function hasUnconfirmed(Project $project): bool
    {
        return $project->currentWakalahAppointments()->where('status', WakalahStatus::Proposed->value)->exists();
    }

    private function remove(Project $p, Collection $current, User $actor, ?string $reason): Project
    {
        if ($p->wakil_id === null && $current->isEmpty()) {
            return $p;
        }
        $old = ['wakil_id' => $p->wakil_id, 'roles' => $this->roleValues($current)];
        foreach ($current as $a) {
            $this->revoke($a, $actor, $reason ?? 'Wakil removed');
        }
        $p->forceFill(['wakil_id' => null])->save();
        $this->audit->record('project.wakil_removed', $p, $old, ['wakil_id' => null], $reason);

        return $p->fresh();
    }

    /** @return array<string, ?WakalahRole> slot => role */
    private function slots(Project $p, array $roles): array
    {
        $valid = WakalahRole::forContract($p->contract_type);
        $roles = array_values(array_unique(array_map('strval', $roles)));
        if ($valid === []) {
            if ($roles !== []) {
                throw new FinancialException('A Wakalah role is not defined for this contract type.');
            }

            return ['GENERAL' => null];
        }
        if ($roles === []) {
            throw new FinancialException('Choose the Wakalah role the Wakil is appointed for.');
        }
        $out = [];
        foreach ($roles as $value) {
            $role = WakalahRole::tryFrom($value);
            if (! $role || ! in_array($role, $valid, true)) {
                throw new FinancialException('That Wakalah role is not valid for this contract type.');
            }
            $out[$role->value] = $role;
        }
        ksort($out);

        return $out;
    }

    private function open(Project $p, User $wakil, ?WakalahRole $role, string $slot, User $actor): WakalahAppointment
    {
        return WakalahAppointment::unguarded(fn () => WakalahAppointment::create([
            'project_id' => $p->id, 'wakil_id' => $wakil->id, 'wakalah_role' => $role?->value, 'slot' => $slot,
            'status' => WakalahStatus::Proposed, 'is_current' => true, 'appointed_by' => $actor->id, 'appointed_at' => now(),
        ]));
    }

    private function revoke(WakalahAppointment $a, User $actor, string $reason): void
    {
        $a->forceFill(['status' => WakalahStatus::Revoked, 'is_current' => null, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => mb_substr($reason, 0, 500)])->save();
    }

    /** @return list<string> sorted slot names of live appointments */
    private function roleValues(Collection $current): array
    {
        return $current->pluck('slot')->sort()->values()->all();
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
