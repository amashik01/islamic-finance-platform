<?php

namespace App\Livewire\Investor;

use App\Enums\TransactionType as T;
use App\Services\Reports\ChartData;
use Livewire\Component;

class PortfolioChart extends Component
{
    public string $range = '6M';

    public function setRange(string $range): void
    {
        $this->range = array_key_exists($range, ChartData::RANGES) ? $range : '6M';
    }

    public function render(ChartData $charts)
    {
        $uid = auth()->id();

        return view('livewire.investor.portfolio-chart', [
            'ranges' => array_keys(ChartData::RANGES),
            'totals' => $charts->portfolio($uid, $this->range),
            'chart' => $charts->transactionsByBucket(['Invested capital' => [T::Investment], 'Returned principal' => [T::PrincipalReturn], 'Realised profit' => [T::ProfitDistribution]], $this->range, $uid),
        ]);
    }
}
