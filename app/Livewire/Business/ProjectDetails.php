<?php

namespace App\Livewire\Business;

use App\Models\AuditLog;
use App\Models\Project;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.business-layout')]
class ProjectDetails extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);   // only the owning business
        $this->project = $project;
    }

    public function render()
    {
        $p = $this->project->load(['contract.mudarabah', 'contract.musharakah', 'contract.murabaha.assets', 'documents', 'shariahReviews']);
        // Feedback the business should see: revision requests / rejections and their reasons.
        $feedback = AuditLog::where('auditable_type', $p->getMorphClass())->where('auditable_id', $p->id)
            ->whereIn('action', ['project.requestRevision', 'project.reject', 'project.approve'])->latest('id')->get();

        return view('livewire.business.project-details', ['p' => $p, 'feedback' => $feedback])->layout('components.business-layout', ['title' => $p->title]);
    }
}
