<?php

namespace App\Livewire\Investor;

use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.investor-layout')]
#[Title('Withdrawals')]
class Withdrawals extends Component
{
    public string $amount = '';

    public string $idempotencyKey = '';

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function submit(WalletService $wallets): void
    {
        $this->reset('error', 'notice');
        try {
            $amount = Money::parse($this->amount);
        } catch (\InvalidArgumentException) {
            $this->addError('amount', 'Enter a valid amount.');

            return;
        }
        try {
            $w = $wallets->requestWithdrawal(auth()->user(), $amount, $this->idempotencyKey);
        } catch (FinancialException $e) {
            $this->error = $e->getMessage() === 'Insufficient available balance.' ? 'Your withdrawal amount exceeds your eligible balance.' : $e->getMessage();

            return;
        }
        $this->notice = "Withdrawal {$w->reference} requested. Funds are reserved while we review it.";
        $this->reset('amount');
        $this->idempotencyKey = (string) Str::uuid();
    }

    public function cancel(int $id, WalletService $wallets): void
    {
        $w = Withdrawal::findOrFail($id);
        $this->authorize('cancel', $w);
        try {
            $wallets->advanceWithdrawal($w, WithdrawalStatus::Cancelled, auth()->user(), 'Cancelled by investor');
            $this->notice = 'Withdrawal cancelled and funds released.';
        } catch (FinancialException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(WalletService $wallets)
    {
        $user = auth()->user();

        return view('livewire.investor.withdrawals', [
            'balance' => $wallets->balances($wallets->walletFor($user))['withdrawable'],
            'withdrawals' => Withdrawal::where('user_id', $user->id)->latest()->limit(20)->get(),
            'investor' => $user->investor,
        ]);
    }
}
