<?php

namespace App\Livewire\Business\Aqd\Concerns;

use App\Domain\Aqd\AqdDefinition;
use App\Enums\DocumentCategory;
use App\Exceptions\FinancialException;
use App\Models\Project;
use App\Services\Document\DocumentService;
use App\Services\Project\ProjectBuilder;
use App\Services\Project\ProjectWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\WithFileUploads;

/** Shared mechanics of the three contract wizards. The steps, fields and rules come from the aqd's own definition. */
trait RunsAqdWizard
{
    use WithFileUploads;

    public int $step = 1;

    public ?int $projectId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public $docFile = null;

    public string $docCategory = 'FINANCIAL_STATEMENT';

    public string $docTitle = '';

    public ?string $error = null;

    abstract protected function definition(): AqdDefinition;

    public function mount(?Project $project = null): void
    {
        $def = $this->definition();
        $this->form = collect($def->fieldMap())->mapWithKeys(fn ($f) => [$f['key'] => $f['type'] === 'checkbox' ? false : ($f['default'] ?? '')])->all()
            + ['wakil_id' => '', 'wakalah_roles' => [], 'muwakkil' => '', 'wakalah_scope' => '', 'wakalah_authority' => []];
        if ($project?->exists) {
            $this->authorize('update', $project);   // owner + editable status only
            abort_unless($project->contract_type === $def->type(), 404);
            $this->projectId = $project->id;
            $this->form = array_merge($this->form, $def->fromProject($project));
        }
    }

    public function back(): void
    {
        $this->reset('error');
        $this->step = max(1, $this->step - 1);
    }

    public function next(ProjectBuilder $builder): void
    {
        $this->reset('error');
        $def = $this->definition();
        if ($rules = $def->rules($this->step, $this->form)) {
            $this->validate($rules);
        }
        try {
            if ($this->step >= $def->persistFromStep()) {
                $project = $builder->saveDraft(auth()->user()->business, $def->toBuilderInput($this->form), $this->projectId ? Project::find($this->projectId) : null);
                $this->projectId = $project->id;
            } else {
                $def->assertShariahRules($def->toBuilderInput($this->form));   // fail early on prohibited wording; nothing is saved yet
            }
        } catch (FinancialException|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->step = min(count($def->steps()), $this->step + 1);
    }

    public function uploadDocument(DocumentService $documents): void
    {
        $this->reset('error');
        abort_unless($this->projectId, 422);
        $this->validate(['docFile' => ['required', 'file', 'max:'.config('finance.documents.max_kb')], 'docTitle' => ['required', 'max:120'], 'docCategory' => ['required']]);
        $project = Project::where('business_id', auth()->user()->business->id)->findOrFail($this->projectId);
        $this->authorize('update', $project);
        try {
            $documents->upload($project, $this->docFile, DocumentCategory::from($this->docCategory), $this->docTitle, auth()->user());
            $this->reset('docFile', 'docTitle');
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function submit(ProjectWorkflow $workflow, ProjectBuilder $builder)
    {
        $this->reset('error');
        $def = $this->definition();
        try {
            // Re-save the final answers so the stored terms are exactly what was previewed, then submit.
            $project = $builder->saveDraft(auth()->user()->business, $def->toBuilderInput($this->form), $this->projectId ? Project::find($this->projectId) : null);
            $this->projectId = $project->id;
            $project = Project::where('business_id', auth()->user()->business->id)->findOrFail($this->projectId);
            $this->authorize('update', $project);
            $workflow->submit($project, auth()->user());
        } catch (FinancialException|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return null;
        }
        session()->flash('status', 'Project submitted for review. We will notify you of the outcome. Approval, if given, applies to this exact structure only; this is software, not a religious authority.');

        return $this->redirectRoute('business.projects.show', $project, navigate: true);
    }

    /** @return array<string, mixed> data every wizard view needs */
    protected function viewData(): array
    {
        $def = $this->definition();

        return [
            'def' => $def, 'steps' => $def->steps(), 'current' => $def->steps()[$this->step - 1],
            'documents' => $this->projectId ? Project::find($this->projectId)?->documents()->latest('id')->get() ?? collect() : collect(),
            'project' => $this->projectId ? Project::with('contract')->find($this->projectId) : null,
            'wakils' => app(\App\Services\Wakalah\WakalahService::class)->eligibleWakils(),
        ];
    }
}
