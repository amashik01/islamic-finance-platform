<?php

namespace App\Livewire\Business\Aqd;

use App\Domain\Aqd\AqdDefinition;
use App\Domain\Aqd\MusharakahAqd;
use App\Livewire\Business\Aqd\Concerns\RunsAqdWizard;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.business-layout')]
#[Title('Create Musharakah Project')]
class MusharakahWizard extends Component
{
    use RunsAqdWizard;

    protected function definition(): AqdDefinition
    {
        return new MusharakahAqd;
    }

    public function render()
    {
        return view('livewire.business.aqd.musharakah', $this->viewData() + ['preview' => $this->preview()]);
    }

    /** Read-only numbers computed by the same calculators the server uses. */
    public function preview(): array
    {
        $f = $this->form;
        try {
            $inv = \App\Support\Money\Money::parse((string) ($f['investor_contribution'] ?: '0'));
            $biz = \App\Support\Money\Money::parse((string) ($f['business_contribution'] ?: '0'));
            $total = \App\Support\Money\Money::parse((string) ($f['total_capital'] ?: '0'));
            $own = $inv->isPositive() && $biz->isPositive() ? app(\App\Services\Finance\MusharakahProfitCalculator::class)->ownership($inv, $biz) : null;

            return ['sum_ok' => $inv->add($biz)->equals($total), 'capital_ratio' => $own ? \App\Support\Percent::format($own['investor_ownership_bps']).' / '.\App\Support\Percent::format($own['business_ownership_bps']) : null];
        } catch (\Throwable) {
            return [];
        }
    }
}
