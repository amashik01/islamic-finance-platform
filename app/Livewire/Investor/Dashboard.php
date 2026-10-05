<?php

namespace App\Livewire\Investor;

use App\Enums\InvestmentStatus;
use App\Services\Wallet\WalletService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.investor-layout')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(WalletService $wallets)
    {
        $user = auth()->user();
        $balances = $wallets->balances($wallets->walletFor($user));
        $investments = $user->investor->investments()->with('project.business')->latest()->limit(5)->get();

        return view('livewire.investor.dashboard', [
            'balances' => $balances,
            'investments' => $investments,
            'investor' => $user->investor,
            'activeCount' => $user->investor->investments()->whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active])->count(),
        ]);
    }
}
