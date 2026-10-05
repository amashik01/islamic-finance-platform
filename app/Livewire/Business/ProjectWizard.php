<?php

namespace App\Livewire\Business;

use App\Enums\ContractType;
use App\Enums\DocumentCategory;
use App\Exceptions\FinancialException;
use App\Models\Project;
use App\Services\Document\DocumentService;
use App\Services\Finance\MurabahaSaleCalculator;
use App\Services\Project\ProjectBuilder;
use App\Services\Project\ProjectWorkflow;
use App\Support\Money\Money;
use App\Support\Percent;
use App\Support\ProjectFormRules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.business-layout')]
#[Title('Create Project')]
class ProjectWizard extends Component
{
    use WithFileUploads;

    public const STEPS = [1 => 'Project Basics', 2 => 'Contract Type', 3 => 'Contract Terms', 4 => 'Financial Details', 5 => 'Documents', 6 => 'Review', 7 => 'Submit'];

    public int $step = 1;

    public ?int $projectId = null;

    /** All wizard input lives here, so nothing is lost when moving between steps. */
    public array $form = [
        'title' => '', 'description' => '', 'industry' => '', 'purpose' => '', 'key_risks' => '', 'risk_level' => 'MEDIUM', 'duration_months' => '12', 'closing_at' => '',
        'contract_type' => '',
        'investor_profit' => '70', 'business_profit' => '30', 'loss_terms' => '', 'business_plan' => '', 'loss_basis' => 'CAPITAL_RATIO', 'project_activity' => '',
        'delivery_terms' => '', 'payment_terms' => '', 'installments' => '4', 'ownership_info' => '', 'possession_info' => '',
        'capital_required' => '', 'business_contribution' => '', 'expected_revenue' => '', 'expected_expenses' => '', 'minimum_amount' => '5000',
        'total_capital' => '', 'investor_contribution' => '', 'financial_assumptions' => '',
        'asset_name' => '', 'supplier' => '', 'quantity' => '1', 'unit_cost' => '', 'sale_profit' => '',
        'wakil_id' => '', 'wakalah_roles' => [], 'muwakkil' => '', 'wakalah_scope' => '', 'wakalah_authority' => [],
    ];

    public $docFile = null;

    public string $docCategory = 'FINANCIAL_STATEMENT';

    public string $docTitle = '';

    public ?string $error = null;

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) {
            $this->authorize('update', $project);   // owner + editable status only
            $this->projectId = $project->id;
            $this->form = array_merge($this->form, $this->fromProject($project));
            $this->step = 1;
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
        $rules = ProjectFormRules::forStep($this->step, $this->form['contract_type'] ?: null);
        if ($rules) {
            $this->validate($rules);
        }

        if ($this->step === 4) {
            // Persist (and fully validate financial consistency server-side) before documents can be attached.
            try {
                $project = $builder->saveDraft(auth()->user()->business, $this->form, $this->projectId ? Project::find($this->projectId) : null);
                $this->projectId = $project->id;
            } catch (FinancialException $e) {
                $this->error = $e->getMessage();

                return;
            }
        }
        $this->step = min(7, $this->step + 1);
    }

    public function updatedFormContractType(): void
    {
        $this->resetErrorBag();
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

    public function submit(ProjectWorkflow $workflow)
    {
        $this->reset('error');
        $project = Project::where('business_id', auth()->user()->business->id)->findOrFail($this->projectId);
        $this->authorize('update', $project);
        try {
            $workflow->submit($project, auth()->user());
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();

            return null;
        }
        session()->flash('status', 'Project submitted for review. We will notify you of the outcome.');

        return $this->redirectRoute('business.projects.show', $project, navigate: true);
    }

    /** Read-only numbers shown live in the form, computed by the same calculators the server uses. */
    public function preview(): array
    {
        $f = $this->form;
        try {
            return match ($f['contract_type']) {
                'MURABAHA' => (function () use ($f) {
                    $cost = app(MurabahaSaleCalculator::class)->purchaseCost(Money::parse($f['unit_cost'] ?: '0'), max(1, (int) $f['quantity']));

                    return ['cost' => $cost->format(), 'profit' => Money::parse($f['sale_profit'] ?: '0')->format(), 'price' => $cost->add(Money::parse($f['sale_profit'] ?: '0'))->format()];
                })(),
                'MUDARABAH' => ['ratio_ok' => Percent::toBps($f['investor_profit'] ?: '0') + Percent::toBps($f['business_profit'] ?: '0') === 10000],
                'MUSHARAKAH' => ['sum_ok' => Money::parse($f['investor_contribution'] ?: '0')->add(Money::parse($f['business_contribution'] ?: '0'))->equals(Money::parse($f['total_capital'] ?: '0'))],
                default => [],
            };
        } catch (\Throwable) {
            return [];
        }
    }

    private function fromProject(Project $p): array
    {
        $c = $p->contract;
        $t = $c?->terms;
        $m = fn (?int $v) => $v === null ? '' : Money::minor($v)->toDecimal();
        $base = ['title' => $p->title, 'description' => $p->description, 'industry' => (string) $p->industry, 'purpose' => (string) $p->purpose, 'key_risks' => (string) $p->key_risks,
            'risk_level' => $p->risk_level->value, 'duration_months' => (string) $p->duration_months, 'closing_at' => $p->closing_at?->format('Y-m-d') ?? '', 'contract_type' => $p->contract_type->value,
            'minimum_amount' => $m($p->minimum_amount),
            'wakil_id' => (string) ($p->wakil_id ?? ''),
            'wakalah_roles' => $p->currentWakalahAppointments()->whereNotNull('wakalah_role')->pluck('wakalah_role')->map(fn ($r) => $r->value)->values()->all(),
            'muwakkil' => (string) ($p->currentWakalahAppointments()->first()?->muwakkil ?? ''),
            'wakalah_scope' => (string) ($p->currentWakalahAppointments()->first()?->scope ?? ''),
            'wakalah_authority' => $p->currentWakalahAppointments()->get()->flatMap(fn ($a) => $a->authority ?? [])->unique()->values()->all()];
        if (! $t) {
            return $base;
        }

        return $base + match ($p->contract_type) {
            ContractType::Mudarabah => ['investor_profit' => Percent::format($t->investor_profit_bps), 'business_profit' => Percent::format($t->business_profit_bps), 'capital_required' => $m($t->capital_required), 'business_contribution' => $m($t->business_contribution), 'expected_revenue' => $m($t->expected_revenue), 'expected_expenses' => $m($t->expected_expenses), 'business_plan' => (string) $t->business_plan, 'loss_terms' => (string) $t->loss_terms],
            ContractType::Musharakah => ['investor_profit' => Percent::format($t->investor_profit_bps), 'business_profit' => Percent::format($t->business_profit_bps), 'total_capital' => $m($t->total_capital), 'investor_contribution' => $m($t->investor_contribution), 'business_contribution' => $m($t->business_contribution), 'loss_basis' => $t->loss_allocation_basis->value, 'project_activity' => (string) $t->project_activity, 'financial_assumptions' => (string) $t->financial_assumptions],
            ContractType::Murabaha => ['installments' => (string) $t->installments_count, 'sale_profit' => $m($t->sale_profit), 'delivery_terms' => (string) $t->delivery_terms, 'payment_terms' => (string) $t->payment_terms,
                'asset_name' => (string) $t->assets->first()?->name, 'supplier' => (string) $t->assets->first()?->supplier_name, 'quantity' => (string) ($t->assets->first()?->quantity ?? 1), 'unit_cost' => $m($t->assets->first()?->unit_cost)],
        };
    }

    public function render()
    {
        return view('livewire.business.project-wizard', [
            'steps' => self::STEPS,
            'wakils' => app(\App\Services\Wakalah\WakalahService::class)->eligibleWakils(),
            'wakalahRoles' => $this->form['contract_type'] ? \App\Enums\WakalahRole::optionsFor(ContractType::from($this->form['contract_type'])) : [],
            'preview' => $this->preview(),
            'documents' => $this->projectId ? Project::find($this->projectId)?->documents()->latest('id')->get() ?? collect() : collect(),
            'project' => $this->projectId ? Project::with('contract')->find($this->projectId) : null,
        ]);
    }
}
