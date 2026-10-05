<?php

namespace App\Services\Ledger;

use App\Enums\EntryDirection;
use App\Enums\LedgerAccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\IdempotencyGuard;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Double-entry ledger. The ledger is the financial source of truth: every movement is a balanced
 * Transaction made of immutable LedgerEntry rows. Account balances are caches mutated only here,
 * under row locks, inside a database transaction.
 */
class LedgerService
{
    /** Account types that may never go below zero. */
    private const NON_NEGATIVE = [
        LedgerAccountType::InvestorAvailable, LedgerAccountType::InvestorInvested, LedgerAccountType::InvestorPending,
        LedgerAccountType::MurabahaReceivable, LedgerAccountType::MurabahaInventory,
        LedgerAccountType::ProjectFunds, LedgerAccountType::CapitalDeployed,   // the project pool can never distribute money it does not hold
        LedgerAccountType::CustodyCash, LedgerAccountType::VentureCapital, LedgerAccountType::BusinessCapital,   // custody cannot be overdrawn; capital cannot be written down below zero
    ];

    public function systemAccount(LedgerAccountType $type, string $currency = Currency::CODE, ?int $projectId = null): LedgerAccount
    {
        Currency::require($currency, 'Ledger account currency');
        $code = 'sys:'.strtolower($type->value).':'.$currency.($projectId ? ":p$projectId" : '');

        return LedgerAccount::firstOrCreate(
            ['code' => $code],
            ['type' => $type, 'name' => $type->label(), 'currency' => $currency, 'normal_side' => $type->normalSide(), 'project_id' => $projectId],
        );
    }

    /**
     * Posts a balanced, BDT-only transaction. Idempotent: the same key with the same request returns the
     * original transaction; the same key with a different request is rejected.
     *
     * @param  list<array{account: LedgerAccount, direction: EntryDirection, amount: Money}>  $lines
     * @param  array<string,mixed>  $attributes  user_id, project_id, investment_id, description, meta, created_by
     */
    public function post(TransactionType $type, array $lines, ?string $idempotencyKey = null, array $attributes = []): Transaction
    {
        $hash = $this->requestHash($type, $lines, $attributes);
        try {
            return $this->write($type, $lines, $idempotencyKey, $hash, $attributes);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if ($idempotencyKey && ($existing = Transaction::where('idempotency_key', $idempotencyKey)->first())) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing;
            }
            throw $e;
        }
    }

    private function write(TransactionType $type, array $lines, ?string $idempotencyKey, string $hash, array $attributes): Transaction
    {
        return DB::transaction(function () use ($type, $lines, $idempotencyKey, $hash, $attributes) {
            if ($idempotencyKey && ($existing = Transaction::where('idempotency_key', $idempotencyKey)->first())) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing; // duplicate request: same result, no second posting
            }

            $this->assertBalanced($lines);
            Currency::require($attributes['currency'] ?? Currency::CODE, 'Transaction currency');
            foreach ($lines as $line) {
                Currency::require($line['amount']->currency, 'Ledger amount');
                Currency::require($line['account']->currency, 'Ledger account');
            }

            // Lock every touched account in a stable order to prevent races and deadlocks.
            $ids = collect($lines)->pluck('account.id')->unique()->sort()->values();
            $accounts = LedgerAccount::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($accounts->count() !== $ids->count()) {
                throw new FinancialException('A ledger entry references an account that does not exist.');
            }
            foreach ($accounts as $locked) {
                Currency::require($locked->currency, 'Ledger account');   // re-checked on the locked row
            }

            $gross = array_sum(array_map(fn ($l) => $l['direction'] === EntryDirection::Debit ? $l['amount']->minor : 0, $lines));

            $tx = Transaction::create([
                'reference' => $this->newReference($type),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'type' => $type,
                'status' => TransactionStatus::Posted,
                'currency' => Currency::CODE,
                'amount' => $gross,
                'posted_at' => now(),
                'created_by' => auth()->id(),
            ] + $attributes);

            foreach ($lines as $line) {
                /** @var LedgerAccount $account */
                $account = $accounts[$line['account']->id];
                /** @var Money $amount */
                $amount = $line['amount'];
                $delta = ($line['direction']->value === $account->normal_side) ? $amount->minor : -$amount->minor;
                $newBalance = $account->balance + $delta;

                if ($newBalance < 0 && in_array($account->type, self::NON_NEGATIVE, true)) {
                    throw new FinancialException(match ($account->type) {
                        LedgerAccountType::MurabahaReceivable => 'The payment exceeds the outstanding receivable.',
                        LedgerAccountType::ProjectFunds, LedgerAccountType::CapitalDeployed => 'The project does not hold enough funds for this movement.',
                        LedgerAccountType::CustodyCash => 'The platform does not hold enough client custody cash for this movement.',
                        LedgerAccountType::VentureCapital, LedgerAccountType::BusinessCapital => 'The venture capital cannot go below zero.',
                        default => 'Insufficient available balance.',
                    });
                }
                LedgerAccount::withBalanceWrites(fn () => $account->forceFill(['balance' => $newBalance])->save());

                LedgerEntry::create([
                    'transaction_id' => $tx->id,
                    'ledger_account_id' => $account->id,
                    'direction' => $line['direction'],
                    'amount' => $amount->minor,
                    'balance_after' => $newBalance,
                ]);
            }

            return $tx;
        });
    }

    /** Fingerprint of what the request economically does (accounts, directions, amounts, subject). */
    private function requestHash(TransactionType $type, array $lines, array $attributes): string
    {
        $legs = array_map(fn ($l) => $l['account']->id.':'.$l['direction']->value.':'.$l['amount']->minor, $lines);
        sort($legs);

        return IdempotencyGuard::hash([
            'op' => $type->value, 'legs' => implode('|', $legs), 'user' => $attributes['user_id'] ?? null,
            'project' => $attributes['project_id'] ?? null, 'investment' => $attributes['investment_id'] ?? null,
        ]);
    }

    /** Correct history by posting the opposite entries; the original is only flagged as reversed. */
    public function reverse(Transaction $original, string $reason, ?User $by = null): Transaction
    {
        return DB::transaction(function () use ($original, $reason, $by) {
            $original = Transaction::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($original->status === TransactionStatus::Reversed) {
                throw new FinancialException('This transaction has already been reversed.');
            }
            $lines = $original->entries()->with('account')->get()->map(fn (LedgerEntry $e) => [
                'account' => $e->account,
                'direction' => $e->direction === EntryDirection::Debit ? EntryDirection::Credit : EntryDirection::Debit,
                'amount' => Money::minor($e->amount, $original->currency),
            ])->all();

            $reversal = $this->post(TransactionType::Reversal, $lines, 'reversal:'.$original->id, [
                'user_id' => $original->user_id,
                'project_id' => $original->project_id,
                'investment_id' => $original->investment_id,
                'reverses_transaction_id' => $original->id,
                'description' => 'Reversal of '.$original->reference.': '.$reason,
                'created_by' => $by?->id ?? auth()->id(),
            ]);
            $original->update(['status' => TransactionStatus::Reversed]);

            return $reversal;
        });
    }

    /** Recompute a balance from entries (integrity check). */
    public function recomputeBalance(LedgerAccount $account): int
    {
        $sum = fn (string $dir) => (int) $account->entries()->where('direction', $dir)->sum('amount');
        $debit = $sum('DEBIT');
        $credit = $sum('CREDIT');

        return $account->normal_side === 'DEBIT' ? $debit - $credit : $credit - $debit;
    }

    /** @param list<array{account: LedgerAccount, direction: EntryDirection, amount: Money}> $lines */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new FinancialException('A transaction needs at least two entries.');
        }
        $debit = $credit = 0;
        foreach ($lines as $l) {
            if (! $l['amount']->isPositive()) {
                throw new FinancialException('Entry amounts must be positive.');
            }
            $l['direction'] === EntryDirection::Debit ? $debit += $l['amount']->minor : $credit += $l['amount']->minor;
        }
        if ($debit !== $credit) {
            throw new FinancialException('Transaction is not balanced.');
        }
    }

    private function newReference(TransactionType $type): string
    {
        return 'TX-'.now()->format('ymd').'-'.strtoupper(Str::random(8));
    }
}
