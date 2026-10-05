<?php

namespace App\Livewire\Wakil;

use App\Exceptions\FinancialException;
use App\Models\WakalahAppointment;
use App\Services\Wakalah\WakalahService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** A Wakil sees only their own Wakalah appointments and can accept or decline them. No other action is offered. */
#[Layout('components.wakil-layout')]
#[Title('Wakalah appointments')]
class Appointments extends Component
{
    public ?string $error = null;

    public ?string $notice = null;

    public string $reason = '';

    public function accept(int $id, WakalahService $svc): void
    {
        $this->reset('error', 'notice');
        try {
            $svc->accept($this->mine($id), auth()->user());
            $this->notice = 'Appointment accepted. It still needs the Shariah review before it is confirmed.';
        } catch (FinancialException|AuthorizationException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function decline(int $id, WakalahService $svc): void
    {
        $this->reset('error', 'notice');
        try {
            $svc->reject($this->mine($id), auth()->user(), trim($this->reason));
            $this->notice = 'Appointment declined.';
            $this->reset('reason');
        } catch (FinancialException|AuthorizationException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function mine(int $id): WakalahAppointment
    {
        return WakalahAppointment::where('wakil_id', auth()->id())->findOrFail($id);   // another Wakil's appointment is a 404
    }

    public function render()
    {
        return view('livewire.wakil.appointments', ['appointments' => WakalahAppointment::where('wakil_id', auth()->id())->with('project:id,title,contract_type')->latest('id')->get()]);
    }
}
