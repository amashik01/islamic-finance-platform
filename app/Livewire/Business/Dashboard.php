<?php

namespace App\Livewire\Business;

use App\Enums\ProjectStatus;
use App\Support\Money\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.business-layout')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render()
    {
        $business = auth()->user()->business;
        $projects = $business->projects()->latest()->get();

        return view('livewire.business.dashboard', [
            'business' => $business,
            'projects' => $projects,
            'active' => $projects->whereIn('status', [ProjectStatus::Funding, ProjectStatus::Active])->count(),
            'funding' => Money::minor((int) $projects->sum('funded_amount')),
        ]);
    }
}
