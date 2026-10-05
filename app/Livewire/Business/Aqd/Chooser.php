<?php

namespace App\Livewire\Business\Aqd;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Step zero: pick the Islamic contract (aqd). Each choice opens its own dedicated form. */
#[Layout('components.business-layout')]
#[Title('Create Project')]
class Chooser extends Component
{
    public function mount(): void
    {
        $this->authorize('create', \App\Models\Project::class);
    }

    public function render()
    {
        return view('livewire.business.aqd.chooser');
    }
}
