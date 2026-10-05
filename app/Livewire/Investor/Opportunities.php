<?php

namespace App\Livewire\Investor;

use App\Enums\ContractType;
use App\Exceptions\FinancialException;
use App\Models\ContractDocument;
use App\Models\Project;
use App\Services\Aqd\ContractGenerator;
use App\Services\Aqd\ContractSigningService;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.investor-layout')]
#[Title('Opportunities')]
class Opportunities extends Component
{
    #[Url(except: '')]
    public string $type = '';

    public ?int $projectId = null;

    public string $amount = '';

    /** One key per confirmation attempt: a double-click or retry cannot create a second investment. */
    public string $idempotencyKey = '';

    /** The participation agreement generated for this attempt; the investor reads and signs exactly this document. */
    public ?int $documentId = null;

    public string $typedName = '';

    public string $password = '';

    public bool $consent = false;

    public ?string $error = null;

    public ?string $success = null;

    public function startInvest(int $projectId): void
    {
        $this->reset('amount', 'error', 'success', 'documentId', 'typedName', 'password', 'consent');
        $this->projectId = $projectId;
        $this->idempotencyKey = (string) Str::uuid();
        $this->dispatch('open-modal', 'invest');
    }

    /** Step 1: the amount is validated and the participation agreement for exactly that amount is generated for the investor to read. */
    public function review(ContractGenerator $generator): void
    {
        $this->error = null;
        $project = Project::findOrFail($this->projectId);
        try {
            $amount = Money::parse($this->amount, $project->currency);
        } catch (\InvalidArgumentException) {
            $this->addError('amount', 'Enter a valid amount, for example 5000 or 5000.50.');

            return;
        }
        try {
            $doc = $generator->participation($project, auth()->user()->investor, $amount, auth()->user());
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();

            return;
        }
        app(ContractSigningService::class)->recordViewed($doc, auth()->user());
        $this->documentId = $doc->id;
    }

    /** Step 2: the investor signs that exact document, then the investment is made against it. */
    public function confirm(InvestmentService $investments, ContractSigningService $signing): void
    {
        $this->error = null;
        $project = Project::findOrFail($this->projectId);
        $doc = $this->documentId ? ContractDocument::where('party_user_id', auth()->id())->find($this->documentId) : null;
        if (! $doc) {
            $this->error = 'Review the investment agreement before confirming.';

            return;
        }
        try {
            $doc->status->value === 'PENDING_SIGNATURE' && $signing->sign($doc, auth()->user(), $this->typedName, $this->password, $this->consent);
            $investments->invest(auth()->user()->investor, $project, Money::minor((int) $doc->amount, $project->currency), $this->idempotencyKey, $doc->fresh());
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->success = 'Investment confirmed: '.Money::minor((int) $doc->amount, $project->currency)->format().' in '.$project->title.'.';
        $this->reset('projectId', 'amount', 'documentId', 'typedName', 'password', 'consent');
        $this->dispatch('close-modal', 'invest');
    }

    public function render(WalletService $wallets)
    {
        $type = ContractType::tryFrom($this->type);
        $projects = Project::with(['business', 'contract.mudarabah', 'contract.musharakah', 'contract.murabaha.assets'])
            ->openForFunding()->when($type, fn ($q) => $q->where('contract_type', $type))->latest('published_at')->get();

        return view('livewire.investor.opportunities', [
            'projects' => $projects,
            'selected' => $this->projectId ? Project::find($this->projectId) : null,
            'document' => $this->documentId ? ContractDocument::where('party_user_id', auth()->id())->find($this->documentId) : null,
            'balance' => $wallets->balances($wallets->walletFor(auth()->user()))['available'],
        ]);
    }
}
