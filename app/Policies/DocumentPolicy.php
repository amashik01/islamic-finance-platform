<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\Document;
use App\Models\Investor;
use App\Models\Project;
use App\Models\User;

/** Private documents: reviewers, the owning party, or investors in the related project. */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        // Identity documents need KYC permission; other categories follow project/contract access.
        $reviewer = $document->category === \App\Enums\DocumentCategory::Kyc
            ? $user->can('kyc.view')
            : ($user->can('projects.view') || $user->can('kyc.view'));
        if ($reviewer) {
            return true;
        }

        $owner = $document->documentable;

        return match (true) {
            $owner instanceof Investor => $owner->user_id === $user->id,
            $owner instanceof Business => $owner->user_id === $user->id,
            $owner instanceof Project => $user->business?->id === $owner->business_id
                || ($user->investor && $owner->investments()->where('investor_id', $user->investor->id)->exists()),
            default => $document->uploaded_by === $user->id,
        };
    }

    public function verify(User $user, Document $document): bool
    {
        return $user->can('kyc.review');
    }
}
