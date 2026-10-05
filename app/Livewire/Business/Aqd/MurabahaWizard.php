<?php

namespace App\Livewire\Business\Aqd;

use App\Domain\Aqd\AqdDefinition;
use App\Domain\Aqd\MurabahaAqd;
use App\Livewire\Business\Aqd\Concerns\RunsAqdWizard;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.business-layout')]
#[Title('Create Murabaha Project')]
class MurabahaWizard extends Component
{
    use RunsAqdWizard;

    protected function definition(): AqdDefinition
    {
        return new MurabahaAqd;
    }

    public function render()
    {
        return view('livewire.business.aqd.murabaha', $this->viewData() + ['preview' => $this->preview()]);
    }

    /** Read-only numbers computed by the same calculators the server uses. */
    public function preview(): array
    {
        $f = $this->form;
        try {
            $calc = app(\App\Services\Finance\MurabahaSaleCalculator::class);
            $cost = $calc->purchaseCost(\App\Support\Money\Money::parse((string) ($f['unit_cost'] ?: '0')), max(1, (int) $f['quantity']));
            $profit = \App\Support\Money\Money::parse((string) ($f['sale_profit'] ?: '0'));

            return ['cost' => $cost->format(), 'profit' => $profit->format(), 'price' => $cost->add($profit)->format()];
        } catch (\Throwable) {
            return [];
        }
    }
}
