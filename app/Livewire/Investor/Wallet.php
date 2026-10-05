<?php

namespace App\Livewire\Investor;

use App\Exceptions\FinancialException;
use App\Models\Transaction;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.investor-layout')]
#[Title('My Wallet')]
class Wallet extends Component
{
    use WithPagination;

    public string $depositAmount = '';

    public string $paymentReference = '';

    public string $depositKey = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->depositKey = (string) Str::uuid();
    }

    public function requestDeposit(WalletService $wallets): void
    {
        $this->reset('error', 'notice');
        $this->validate(['paymentReference' => ['nullable', 'string', 'max:100']]);
        try {
            $amount = Money::parse($this->depositAmount);
            $d = $wallets->requestDeposit(auth()->user(), $amount, $this->depositKey, $this->paymentReference ?: null);
        } catch (\InvalidArgumentException) {
            $this->addError('depositAmount', 'Enter a valid amount.');

            return;
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->notice = "Deposit {$d->reference} recorded. It will be added to your available balance once we verify your payment.";
        $this->reset('depositAmount', 'paymentReference');
        $this->depositKey = (string) Str::uuid();
        $this->dispatch('close-modal', 'deposit');
    }

    public function render(WalletService $wallets)
    {
        $user = auth()->user();
        $wallet = $wallets->walletFor($user);

        return view('livewire.investor.wallet', [
            'b' => $wallets->balances($wallet),
            'transactions' => Transaction::with('project')->where('user_id', $user->id)->latest('posted_at')->latest('id')->paginate(10),
        ]);
    }
}
