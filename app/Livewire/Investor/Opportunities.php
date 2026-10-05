<?php

namespace App\Livewire\Investor;

use App\Enums\ContractType;
use App\Exceptions\FinancialException;
use App\Models\Project;
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

    public ?string $error = null;

    public ?string $success = null;

    public function startInvest(int $projectId): void
    {
        $this->reset('amount', 'error', 'success');
        $this->projectId = $projectId;
        $this->idempotencyKey = (string) Str::uuid();
        $this->dispatch('open-modal', 'invest');
    }

    public function confirm(InvestmentService $investments): void
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
            $investments->invest(auth()->user()->investor, $project, $amount, $this->idempotencyKey);
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->success = 'Investment confirmed: '.$amount->format().' in '.$project->title.'.';
        $this->reset('projectId', 'amount');
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
            'balance' => $wallets->balances($wallets->walletFor(auth()->user()))['available'],
        ]);
    }
}
