<?php

namespace App\Livewire\Investor;

use App\Models\Investment;
use App\Models\SettlementItem;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.investor-layout')]
class InvestmentDetails extends Component
{
    public Investment $investment;

    public function mount(Investment $investment): void
    {
        $this->authorize('view', $investment);   // IDOR guard: only the owner (or staff with permission)
        $this->investment = $investment;
    }

    public function render()
    {
        $i = $this->investment->load(['project.business', 'project.documents', 'contract.mudarabah', 'contract.musharakah', 'contract.murabaha']);
        $items = SettlementItem::where('investment_id', $i->id)->get();
        $sum = fn (string $type) => (int) $items->filter(fn ($x) => $x->item_type->value === $type)->sum('amount');
        $returned = $sum('PRINCIPAL');
        $profit = $sum('INVESTMENT_PROFIT');

        return view('livewire.investor.investment-details', [
            'i' => $i, 'returned' => $returned, 'profit' => $profit,
            'pending' => $i->status->value === 'COMPLETED' ? 0 : $i->amount,
            // Only documents the investor is entitled to see (the DocumentPolicy decides).
            'documents' => $i->project->documents->filter(fn ($d) => auth()->user()->can('view', $d)),
        ])->layout('components.investor-layout', ['title' => $i->project->title]);
    }
}
