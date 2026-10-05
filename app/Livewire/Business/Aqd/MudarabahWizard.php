<?php

namespace App\Livewire\Business\Aqd;

use App\Domain\Aqd\AqdDefinition;
use App\Domain\Aqd\MudarabahAqd;
use App\Livewire\Business\Aqd\Concerns\RunsAqdWizard;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.business-layout')]
#[Title('Create Mudarabah Project')]
class MudarabahWizard extends Component
{
    use RunsAqdWizard;

    protected function definition(): AqdDefinition
    {
        return new MudarabahAqd;
    }

    public function render()
    {
        return view('livewire.business.aqd.mudarabah', $this->viewData() + ['preview' => $this->preview()]);
    }

    /** Read-only numbers computed by the same calculators the server uses. */
    public function preview(): array
    {
        $f = $this->form;
        try {
            $i = \App\Support\Percent::toBps((string) ($f['investor_profit'] ?: '0'));
            $b = \App\Support\Percent::toBps((string) ($f['business_profit'] ?: '0'));
            $capital = \App\Support\Money\Money::parse((string) ($f['capital_required'] ?: '0'));

            return ['ratio_ok' => $i > 0 && $b > 0 && $i + $b === 10000, 'capital' => $capital->format(), 'rabb' => \App\Support\Percent::format($i), 'mudarib' => \App\Support\Percent::format($b)];
        } catch (\Throwable) {
            return [];
        }
    }
}
