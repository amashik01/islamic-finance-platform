<?php

namespace App\Livewire\Admin;

use App\Enums\ProjectStatus as S;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Services\Project\ProjectWorkflow;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.admin-layout')]
class ProjectReview extends Component
{
    public Project $project;

    public ?string $pending = null;

    public string $reason = '';

    public ?string $error = null;

    public ?string $notice = null;

    public string $wakilId = '';

    public array $wakalahRoles = [];

    public string $wakalahReason = '';

    public string $muwakkil = '';

    public string $wakalahScope = '';

    public array $wakalahAuthority = [];

    public string $wakalahNotes = '';

    private const NEEDS_REASON = ['revision', 'reject', 'pause', 'cancel'];

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->wakilId = (string) ($project->wakil_id ?? '');
        $this->wakalahRoles = $project->currentWakalahAppointments()->whereNotNull('wakalah_role')->pluck('wakalah_role')->map(fn ($r) => $r->value)->values()->all();
        $first = $project->currentWakalahAppointments()->first();
        $this->muwakkil = (string) ($first?->muwakkil ?? '');
        $this->wakalahScope = (string) ($first?->scope ?? '');
        $this->wakalahAuthority = $project->currentWakalahAppointments()->get()->flatMap(fn ($a) => $a->authority ?? [])->unique()->values()->all();
    }

    /** Appointment-level Shariah review of one Wakalah appointment (approve / reject / request revision). */
    public function reviewWakalah(int $id, string $decision): void
    {
        $this->reset('error');
        abort_unless(auth()->user()->can('shariah.review'), 403);
        try {
            $a = \App\Models\WakalahAppointment::where('project_id', $this->project->id)->findOrFail($id);
            app(\App\Services\Wakalah\WakalahService::class)->review($a, auth()->user(), \App\Enums\ShariahReviewStatus::from($decision), trim($this->wakalahNotes) ?: null);
            $this->notice = 'Wakalah review recorded.';
            $this->reset('wakalahNotes');
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Staff appoint / change / remove the Wakil while the project has not been published; the service re-checks everything. */
    public function saveWakil(): void
    {
        $this->reset('error');
        abort_unless(auth()->user()->can('projects.edit'), 403);
        try {
            $this->project = app(\App\Services\Wakalah\WakalahService::class)->assign(
                $this->project, filled($this->wakilId) ? (int) $this->wakilId : null, $this->wakalahRoles, auth()->user(), trim($this->wakalahReason) ?: null,
                ['muwakkil' => $this->muwakkil, 'scope' => $this->wakalahScope, 'authority' => $this->wakalahAuthority],
            );
            $this->notice = 'Wakalah appointment updated.';
            $this->reset('wakalahReason');
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function ask(string $action): void
    {
        $this->reset('reason', 'error');
        $this->pending = $action;
        $this->dispatch('open-modal', 'review-action');
    }

    public function confirm(): void
    {
        $action = $this->pending;
        abort_unless($action, 422);
        if (in_array($action, self::NEEDS_REASON, true) && trim($this->reason) === '') {
            $this->addError('reason', 'Please give a reason.');

            return;
        }
        $wf = app(ProjectWorkflow::class);
        $u = auth()->user();
        $reason = trim($this->reason) ?: null;
        try {
            $this->project = match ($action) {
                'approve' => $this->can('approve', fn () => $wf->approve($this->project, $u, $reason)),
                'reject' => $this->can('reject', fn () => $wf->reject($this->project, $u, $reason)),
                'revision' => $this->can('review', fn () => $wf->requestRevision($this->project, $u, $reason)),
                'publish' => $this->can('approve', fn () => $wf->publish($this->project, $u)),
                'pause' => $this->can('approve', fn () => $wf->pause($this->project, $u, $reason)),
                'resume' => $this->can('approve', fn () => $wf->resume($this->project, $u)),
                'cancel' => $this->can('approve', fn () => $wf->cancel($this->project, $u, $reason)),
            };
            $this->notice = 'Project updated.';
            $this->dispatch('close-modal', 'review-action');
            $this->reset('pending', 'reason');
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function can(string $ability, \Closure $do): Project
    {
        $this->authorize($ability, $this->project);

        return $do();
    }

    public function render()
    {
        $p = $this->project->load(['business.user', 'contract.mudarabah', 'contract.musharakah', 'contract.murabaha.assets', 'contract.murabaha.purchase', 'documents', 'shariahReviews.reviewer', 'reviewer']);
        $wakils = app(\App\Services\Wakalah\WakalahService::class)->eligibleWakils();
        $history = AuditLog::with('user')->where('auditable_type', $p->getMorphClass())->where('auditable_id', $p->id)->latest('id')->get();

        return view('livewire.admin.project-review', ['p' => $p, 'history' => $history, 'wakils' => $wakils])->layout('components.admin-layout', ['title' => $p->title]);
    }
}
