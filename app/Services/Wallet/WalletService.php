<?php

namespace App\Services\Wallet;

use App\Enums\DepositStatus;
use App\Enums\EntryDirection as D;
use App\Enums\KycStatus;
use App\Enums\LedgerAccountType as A;
use App\Enums\TransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\IdempotencyGuard;
use App\Services\Ledger\LedgerService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function __construct(private LedgerService $ledger, private AuditLogger $audit, private \App\Services\Notify\Notifier $notify, private \App\Services\Settings\SettingsService $settings) {}

    public function walletFor(User $user, string $currency = Currency::CODE): Wallet
    {
        Currency::require($currency, 'Wallet currency');

        return DB::transaction(function () use ($user, $currency) {
            $wallet = Wallet::firstOrCreate(['user_id' => $user->id, 'currency' => $currency]);
            Currency::require($wallet->currency, 'Wallet currency');
            foreach ([A::InvestorAvailable, A::InvestorInvested, A::InvestorPending] as $type) {
                LedgerAccount::firstOrCreate(
                    ['code' => "w{$wallet->id}:".strtolower($type->value)],
                    ['type' => $type, 'name' => $type->label(), 'currency' => $currency, 'normal_side' => 'CREDIT', 'wallet_id' => $wallet->id],
                );
            }

            return $wallet;
        });
    }

    public function account(Wallet $wallet, A $type): LedgerAccount
    {
        return LedgerAccount::where('wallet_id', $wallet->id)->where('type', $type)->firstOrFail();
    }

    /** @return array{available: Money, invested: Money, pending: Money, pending_deposits: Money, withdrawable: Money} */
    public function balances(Wallet $wallet): array
    {
        $by = LedgerAccount::where('wallet_id', $wallet->id)->pluck('balance', 'type');
        $get = fn (A $t) => Money::minor((int) ($by[$t->value] ?? 0), $wallet->currency);
        $pendingDeposits = (int) Deposit::where('user_id', $wallet->user_id)->where('status', DepositStatus::Pending)->sum('amount');

        return [
            'available' => $get(A::InvestorAvailable),
            'invested' => $get(A::InvestorInvested),
            'pending' => $get(A::InvestorPending),
            'pending_deposits' => Money::minor($pendingDeposits, $wallet->currency),
            'withdrawable' => $get(A::InvestorAvailable),
        ];
    }

    /* ---------- Deposits: Payment -> Pending -> Verification -> Available ---------- */

    public function requestDeposit(User $user, Money $amount, string $idempotencyKey, ?string $paymentReference = null): Deposit
    {
        if (! $amount->isPositive()) {
            throw new FinancialException('Enter a deposit amount greater than zero.');
        }

        $hash = IdempotencyGuard::hash(['op' => 'deposit', 'user' => $user->id, 'amount' => $amount->minor, 'ref' => $paymentReference]);
        if ($existing = Deposit::where('idempotency_key', $idempotencyKey)->first()) {
            IdempotencyGuard::assertMatches($existing->request_hash, $hash);

            return $existing;
        }
        try {
            return Deposit::create([
                'user_id' => $user->id,
                'reference' => 'DEP-'.strtoupper(Str::random(8)),
                'amount' => $amount->minor,
                'currency' => $amount->currency,
                'payment_reference' => $paymentReference,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $existing = Deposit::where('idempotency_key', $idempotencyKey)->firstOrFail();
            IdempotencyGuard::assertMatches($existing->request_hash, $hash);

            return $existing;
        }
    }

    public function verifyDeposit(Deposit $deposit, User $by): Deposit
    {
        return DB::transaction(function () use ($deposit, $by) {
            $deposit = Deposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($deposit->status !== DepositStatus::Pending) {
                throw new FinancialException('This deposit has already been processed.');
            }
            $wallet = $this->walletFor($deposit->user, $deposit->currency);
            $amount = Money::minor($deposit->amount, $deposit->currency);

            $tx = $this->ledger->post(TransactionType::Deposit, [
                ['account' => $this->ledger->systemAccount(A::CustodyCash, $deposit->currency), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $amount],
            ], 'deposit:'.$deposit->id, ['user_id' => $deposit->user_id, 'description' => 'Deposit '.$deposit->reference, 'created_by' => $by->id]);

            $deposit->forceFill(['status' => DepositStatus::Verified, 'verified_by' => $by->id, 'verified_at' => now(), 'transaction_id' => $tx->id])->save();
            $this->audit->record('deposit.verified', $deposit, null, ['amount' => $deposit->amount]);
            $this->notify->to($deposit->user, 'Deposit verified', $amount->format().' has been added to your available balance.', 'success', route('investor.wallet'));

            return $deposit;
        });
    }

    public function rejectDeposit(Deposit $deposit, User $by, string $reason): Deposit
    {
        if ($deposit->status !== DepositStatus::Pending) {
            throw new FinancialException('This deposit has already been processed.');
        }
        $deposit->forceFill(['status' => DepositStatus::Rejected, 'verified_by' => $by->id, 'verified_at' => now()])->save();
        $this->audit->record('deposit.rejected', $deposit, null, null, $reason);

        return $deposit;
    }

    /* ---------- Withdrawals: funds are reserved (available -> pending) at request time ---------- */

    public function requestWithdrawal(User $user, Money $amount, string $idempotencyKey): Withdrawal
    {
        $hash = IdempotencyGuard::hash(['op' => 'withdrawal', 'user' => $user->id, 'amount' => $amount->minor]);
        try {
            return $this->hold($user, $amount, $idempotencyKey, $hash);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $existing = Withdrawal::where('idempotency_key', $idempotencyKey)->firstOrFail();
            IdempotencyGuard::assertMatches($existing->request_hash, $hash);

            return $existing;
        }
    }

    private function hold(User $user, Money $amount, string $idempotencyKey, string $hash): Withdrawal
    {
        return DB::transaction(function () use ($user, $amount, $idempotencyKey, $hash) {
            if ($existing = Withdrawal::where('idempotency_key', $idempotencyKey)->first()) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing;
            }
            $investor = $user->investor ?? throw new FinancialException('Only investors can withdraw funds.');
            if ($investor->kyc_status !== KycStatus::Approved) {
                throw new FinancialException('Complete identity verification before withdrawing.');
            }
            if (! $investor->bank_verified || ! $investor->bank_account_number) {
                throw new FinancialException('Add and verify a bank account before withdrawing.');
            }
            $min = Money::minor($this->settings->minor('finance.min_withdrawal'), $amount->currency);
            $max = Money::minor($this->settings->minor('finance.max_withdrawal'), $amount->currency);
            if ($amount->lt($min)) {
                throw new FinancialException('The minimum withdrawal is '.$min->format().'.');
            }
            if ($amount->minor > $max->minor) {
                throw new FinancialException('The maximum single withdrawal is '.$max->format().'.');
            }

            $wallet = $this->walletFor($user, $amount->currency);
            $withdrawal = Withdrawal::create([
                'user_id' => $user->id,
                'reference' => 'WDR-'.strtoupper(Str::random(8)),
                'amount' => $amount->minor,
                'currency' => $amount->currency,
                'bank_account_number' => $investor->bank_account_number,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
            ]);
            // Throws "Insufficient available balance." if the lock-protected balance cannot cover it.
            $tx = $this->ledger->post(TransactionType::Withdrawal, [
                ['account' => $this->account($wallet, A::InvestorAvailable), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->account($wallet, A::InvestorPending), 'direction' => D::Credit, 'amount' => $amount],
            ], 'withdrawal-hold:'.$withdrawal->id, ['user_id' => $user->id, 'description' => 'Withdrawal request '.$withdrawal->reference]);
            $withdrawal->forceFill(['transaction_id' => $tx->id])->save();
            $this->notify->toStaffWith('withdrawals.approve', 'New withdrawal request', $user->name.' requested '.$amount->format().'.', route('admin.withdrawals'));

            return $withdrawal;
        });
    }

    private const FLOW = [
        'PENDING' => ['UNDER_REVIEW', 'REJECTED', 'CANCELLED'],
        'UNDER_REVIEW' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['PROCESSING', 'REJECTED'],
        'PROCESSING' => ['PAID'],
    ];

    public function advanceWithdrawal(Withdrawal $withdrawal, WithdrawalStatus $to, User $by, ?string $reason = null): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $to, $by, $reason) {
            $withdrawal = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if (! in_array($to->value, self::FLOW[$withdrawal->status->value] ?? [], true)) {
                throw new FinancialException('This withdrawal cannot move from '.$withdrawal->status->label().' to '.$to->label().'.');
            }
            $amount = Money::minor($withdrawal->amount, $withdrawal->currency);
            $wallet = $this->walletFor($withdrawal->user, $withdrawal->currency);
            $pending = $this->account($wallet, A::InvestorPending);

            if ($to === WithdrawalStatus::Paid) {
                $this->ledger->post(TransactionType::Withdrawal, [
                    ['account' => $pending, 'direction' => D::Debit, 'amount' => $amount],
                    ['account' => $this->ledger->systemAccount(A::CustodyCash, $withdrawal->currency), 'direction' => D::Credit, 'amount' => $amount],
                ], 'withdrawal-paid:'.$withdrawal->id, ['user_id' => $withdrawal->user_id, 'description' => 'Withdrawal paid '.$withdrawal->reference, 'created_by' => $by->id]);
            } elseif (in_array($to, [WithdrawalStatus::Rejected, WithdrawalStatus::Cancelled], true)) {
                $this->ledger->post(TransactionType::Refund, [
                    ['account' => $pending, 'direction' => D::Debit, 'amount' => $amount],
                    ['account' => $this->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $amount],
                ], 'withdrawal-release:'.$withdrawal->id, ['user_id' => $withdrawal->user_id, 'description' => 'Withdrawal released '.$withdrawal->reference, 'created_by' => $by->id]);
            }

            $old = $withdrawal->status->value;
            $withdrawal->forceFill(['status' => $to, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'reason' => $reason ?? $withdrawal->reason])->save();
            $this->audit->record('withdrawal.'.strtolower($to->value), $withdrawal, ['status' => $old], ['status' => $to->value], $reason);
            $this->notify->to($withdrawal->user, 'Withdrawal '.strtolower($to->label()), 'Your withdrawal of '.$amount->format().' is now: '.$to->label().($reason && $to === WithdrawalStatus::Rejected ? ' — '.$reason : '').'.', $to === WithdrawalStatus::Rejected ? 'warning' : 'info', route('investor.withdrawals'));

            return $withdrawal;
        });
    }
}
