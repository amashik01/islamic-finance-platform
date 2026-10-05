<?php

namespace App\Livewire\Admin;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\MurabahaStage as Stage;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Services\Finance\MudarabahProfitCalculator;
use App\Services\Finance\MusharakahProfitCalculator;
use App\Services\Murabaha\MurabahaService;
use App\Services\Settlement\SettlementService;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.admin-layout')]
class ContractDetails extends Component
{
    public Contract $contract;

    // Settlement (Mudarabah / Musharakah)
    public string $netResult = '';

    public bool $managerAtFault = false;

    public string $reason = '';

    // Murabaha steps
    public string $invoice = '';

    public string $date = '';

    public string $notes = '';

    public string $firstDue = '';

    public string $payAmount = '';

    public string $payKey = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(Contract $contract): void
    {
        $this->authorize('view', $contract);
        $this->contract = $contract;
        $this->date = now()->format('Y-m-d');
        $this->firstDue = now()->addMonth()->format('Y-m-d');
        $this->payKey = (string) Str::uuid();
    }

    /** Read-only preview computed by the same calculators the settlement uses. */
    public function preview(): ?array
    {
        if ($this->netResult === '' || ! in_array($this->contract->contract_type, [ContractType::Mudarabah, ContractType::Musharakah], true)) {
            return null;
        }
        try {
            $net = Money::parse($this->netResult);
            $capital = Money::minor((int) $this->contract->investments()->whereIn('status', ['CONFIRMED', 'ACTIVE'])->sum('amount'));
            if ($this->contract->contract_type === ContractType::Mudarabah) {
                $t = $this->contract->mudarabah;
                $r = app(MudarabahProfitCalculator::class)->settle($capital, $net, $t->investor_profit_bps, $t->business_profit_bps, $this->managerAtFault);

                return ['Principal returned to investors' => $r['principal_returned'], 'Investment profit to investors' => $r['investor_profit'], 'Business share of profit' => $r['business_profit'], 'Loss borne by investors' => $r['investor_loss'], 'Recoverable from manager' => $r['manager_liability']];
            }
            $t = $this->contract->musharakah;
            $r = app(MusharakahProfitCalculator::class)->settle($capital, Money::minor($t->business_contribution), $net, $t->investor_profit_bps, $t->business_profit_bps, $t->loss_allocation_basis);

            return ['Investor capital returned' => $capital->subtract($r['investor_loss']), 'Investment profit to investors' => $r['investor_profit'], 'Business share of profit' => $r['business_profit'], 'Loss borne by investors' => $r['investor_loss']];
        } catch (\Throwable) {
            return null;
        }
    }

    public function settle(SettlementService $settlements): void
    {
        $this->reset('error', 'notice');
        $this->authorize('manage', $this->contract);
        abort_unless(auth()->user()->can('settlements.manage'), 403);
        try {
            $net = Money::parse($this->netResult);
            if (trim($this->reason) === '') {
                throw new FinancialException('Record a reason or reference for this settlement.');
            }
            $s = $settlements->settle($this->contract, $net, auth()->user(), $this->managerAtFault, $this->reason);
            $this->notice = "Settlement {$s->reference} posted.";
            $this->contract->refresh();
            $this->dispatch('close-modal', 'settle');
        } catch (\InvalidArgumentException) {
            $this->error = 'Enter the actual net result as a number, for example 20000 or -5000.';
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Murabaha workflow step. */
    public function step(string $name, MurabahaService $svc): void
    {
        $this->reset('error', 'notice');
        $this->authorize('manage', $this->contract);
        $m = $this->contract->murabaha;
        $by = auth()->user();
        try {
            $on = Carbon::parse($this->date);
            match ($name) {
                'verify' => $svc->verifySupplierAndAsset($m, $by),
                'purchase' => $svc->recordPurchase($m, Money::minor($m->purchase_cost), $this->invoice ?: throw new FinancialException('Enter the supplier invoice reference.'), $on, $by),
                'ownership' => $svc->recordOwnership($m, $on, $by),
                'possession' => $svc->recordPossession($m, $on, $this->notes ?: throw new FinancialException('Describe how possession was taken.'), $by),
                'sale' => $svc->executeSale($m, $on, Carbon::parse($this->firstDue), $by),
            };
            $this->notice = 'Step recorded.';
            $this->reset('invoice', 'notes');
            $this->contract->refresh();
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            $this->error = 'Enter a valid date.';
        }
    }

    public function recordPayment(MurabahaService $svc): void
    {
        $this->reset('error', 'notice');
        $this->authorize('manage', $this->contract);
        try {
            $r = $this->contract->murabaha->sale->receivable;
            $svc->recordPayment($r, Money::parse($this->payAmount), $this->payKey, Carbon::parse($this->date), auth()->user());
            $this->notice = 'Payment recorded.';
            $this->reset('payAmount');
            $this->payKey = (string) Str::uuid();
            $this->contract->refresh();
        } catch (\InvalidArgumentException) {
            $this->error = 'Enter a valid payment amount.';
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $c = $this->contract->load(['project.business', 'mudarabah', 'musharakah', 'murabaha.assets', 'murabaha.purchase', 'murabaha.sale.receivable.schedules', 'settlements.items']);

        return view('livewire.admin.contract-details', ['c' => $c, 'preview' => $this->preview(), 'stages' => Stage::cases()])->layout('components.admin-layout', ['title' => $c->contract_number]);
    }
}
