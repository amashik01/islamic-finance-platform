<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    private const BUSINESS_EDITABLE = [ProjectStatus::Draft, ProjectStatus::NeedsRevision];

    public function viewAny(User $user): bool
    {
        return $user->can('projects.view') || $user->isBusiness();
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.view') || $this->owns($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->isBusiness() && $user->can('projects.create');
    }

    /** Businesses cannot modify a project once it is approved or in flight. */
    public function update(User $user, Project $project): bool
    {
        if ($user->can('projects.edit') && $user->isStaffMember()) {
            return true;
        }

        return $this->owns($user, $project) && in_array($project->status, self::BUSINESS_EDITABLE, true);
    }

    public function review(User $user, Project $project): bool
    {
        return $user->can('projects.review');
    }

    public function approve(User $user, Project $project): bool
    {
        return $user->can('projects.approve');
    }

    public function reject(User $user, Project $project): bool
    {
        return $user->can('projects.reject');
    }

    private function owns(User $user, Project $project): bool
    {
        return $user->business?->id === $project->business_id;
    }
}
