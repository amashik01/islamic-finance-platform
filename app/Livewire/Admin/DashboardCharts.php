<?php

namespace App\Livewire\Admin;

use App\Enums\TransactionType as T;
use App\Services\Reports\ChartData;
use Livewire\Component;

class DashboardCharts extends Component
{
    public string $range = '6M';

    public function setRange(string $range): void
    {
        $this->range = array_key_exists($range, ChartData::RANGES) ? $range : '6M';
    }

    public function render(ChartData $charts)
    {
        abort_unless(auth()->user()->can('reports.view') || auth()->user()->can('ledger.view') || auth()->user()->isStaffMember(), 403);

        return view('livewire.admin.dashboard-charts', [
            'ranges' => array_keys(ChartData::RANGES),
            'flow' => $charts->transactionsByBucket(['Deposits' => [T::Deposit], 'Investments' => [T::Investment], 'Returns' => [T::PrincipalReturn, T::ProfitDistribution], 'Withdrawals' => [T::Withdrawal]], $this->range),
            'distribution' => $charts->contractDistribution(),
            'lifecycle' => $charts->projectLifecycle(),
            'activity' => $charts->monthlyActivity(),
        ]);
    }
}
