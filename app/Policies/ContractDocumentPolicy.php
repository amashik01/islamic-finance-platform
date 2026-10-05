<?php

namespace App\Policies;

use App\Enums\ContractDocumentKind as K;
use App\Enums\ContractDocumentStatus as S;
use App\Models\ContractDocument;
use App\Models\User;

/**
 * Who may read a generated agreement. Agreements carry personal and commercial details, so access is limited to the
 * parties and to staff with a reason to see them. An investor's participation agreement is never visible to the business.
 */
class ContractDocumentPolicy
{
    public function view(User $user, ContractDocument $d): bool
    {
        if ($user->isStaffMember() && ($user->can('contracts.view') || $user->can('contracts.manage'))) {
            return true;
        }
        if ($user->can('shariah.review')) {
            return true;
        }
        $project = $d->project;
        if ($d->kind === K::Participation) {
            return $d->party_user_id === $user->id;
        }
        if ($d->kind === K::Wakalah) {
            return $d->party_user_id === $user->id || $user->business?->id === $project->business_id;
        }
        if ($user->business?->id === $project->business_id) {
            return true;
        }
        // Investors of the project may read its executed agreement (the terms they are bound by), never an unreviewed draft.
        if ($d->kind === K::MasterAqd && $d->status !== S::Draft && $user->investor) {
            return $project->contract?->investments()->where('investor_id', $user->investor->id)->exists() ?? false;
        }

        return false;
    }
}
