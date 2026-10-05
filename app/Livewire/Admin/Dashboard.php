<?php

namespace App\Livewire\Admin;

use App\Enums\ContractStatus;
use App\Enums\DepositStatus;
use App\Enums\InvestmentStatus;
use App\Enums\LedgerAccountType;
use App\Enums\ProjectStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\LedgerAccount;
use App\Models\Project;
use App\Models\Withdrawal;
use App\Support\Money\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.admin-layout')]
#[Title('Command Center')]
class Dashboard extends Component
{
    public function render()
    {
        $received = (int) LedgerAccount::where('type', LedgerAccountType::InvestorAvailable)->sum('balance')
            + (int) LedgerAccount::where('type', LedgerAccountType::InvestorInvested)->sum('balance')
            + (int) LedgerAccount::where('type', LedgerAccountType::InvestorPending)->sum('balance');

        $cards = [
            ['Total Investors', number_format(Investor::count()), null, route('admin.investors')],
            ['Active Investors', number_format(Investor::whereHas('investments', fn ($q) => $q->whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active]))->count()), null, route('admin.investors')],
            ['Businesses', number_format(Business::count()), null, route('admin.businesses')],
            ['Active Projects', number_format(Project::whereIn('status', [ProjectStatus::Funding, ProjectStatus::Active])->count()), null, route('admin.projects')],
            ['Capital Held for Investors', Money::minor($received)->format(), 'Available + invested + pending, from the ledger.', route('admin.ledger')],
            ['Capital Deployed', Money::minor((int) Investment::whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active])->sum('amount'))->format(), null, route('admin.investments')],
            ['Pending Withdrawals', number_format(Withdrawal::whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview])->count()), null, route('admin.withdrawals')],
            ['Pending Deposits', number_format(\App\Models\Deposit::where('status', DepositStatus::Pending)->count()), null, route('admin.deposits')],
            ['Projects Awaiting Review', number_format(Project::where('status', ProjectStatus::Review)->count()), null, route('admin.projects.pending')],
            ['Overdue / Defaulted Contracts', number_format(Contract::where('status', ContractStatus::Defaulted)->count()), null, route('admin.contracts.murabaha')],
        ];

        return view('livewire.admin.dashboard', ['cards' => $cards, 'pendingWithdrawals' => Withdrawal::with('user.investor')->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview])->latest()->limit(5)->get()]);
    }
}
