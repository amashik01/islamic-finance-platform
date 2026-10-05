<?php

namespace App\Livewire\Investor;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.investor-layout')]
#[Title('Transactions')]
class Transactions extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $type = '';

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $q = Transaction::with('project')->where('user_id', auth()->id())
            ->when(TransactionType::tryFrom($this->type), fn ($q, $t) => $q->where('type', $t));

        return view('livewire.investor.transactions', ['transactions' => $q->latest('posted_at')->latest('id')->paginate(15)]);
    }
}
