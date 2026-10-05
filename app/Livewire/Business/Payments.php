<?php

namespace App\Livewire\Business;

use App\Models\PaymentSchedule;
use App\Support\Money\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.business-layout')]
#[Title('Payments')]
class Payments extends Component
{
    public function render()
    {
        $id = auth()->user()->business->id;
        $schedules = PaymentSchedule::with('receivable.sale.murabahaContract.contract.project')
            ->whereHas('receivable', fn ($q) => $q->where('business_id', $id))->orderBy('due_date')->get();

        return view('livewire.business.payments', [
            'schedules' => $schedules,
            'outstanding' => Money::minor((int) $schedules->sum(fn ($s) => $s->amount - $s->paid_amount)),
            'overdue' => $schedules->where('status', \App\Enums\PaymentStatus::Overdue)->count(),
        ]);
    }
}
