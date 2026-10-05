<?php

namespace App\Services\Settlement;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\EntryDirection as D;
use App\Enums\InvestmentStatus;
use App\Enums\LedgerAccountType as A;
use App\Enums\LossAllocationBasis;
use App\Enums\ManagerRecoveryStatus;
use App\Enums\ProjectStatus;
use App\Enums\RecoveryStatus;
use App\Enums\SettlementItemType as Item;
use App\Enums\SettlementStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Models\Investment;
use App\Models\ManagerRecovery;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\IdempotencyGuard;
use App\Services\Finance\MudarabahProfitCalculator;
use App\Services\Finance\MusharakahProfitCalculator;
use App\Services\Ledger\LedgerService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Settles Mudarabah / Musharakah contracts in BDT. Settlement is an explicit financial event that records,
 * as separate ledger transactions and separate settlement items:
 *   investor principal return, investor profit share, business profit share, investor loss, and
 *   (Mudarabah only, and only for documented fault) the amount recoverable from the manager.
 */
class SettlementService
{
    public function __construct(
        private LedgerService $ledger,
        private WalletService $wallets,
        private MudarabahProfitCalculator $mudarabah,
        private MusharakahProfitCalculator $musharakah,
        private AuditLogger $audit,
        private \App\Services\Notify\Notifier $notify,
    ) {}

    /** Business remits cash into the project (Dr platform cash / Cr project funds). */
    public function recordBusinessRemittance(Contract $contract, Money $amount, string $idempotencyKey, ?User $by = null)
    {
        Currency::require($contract->currency, 'Contract currency');

        return $this->ledger->post(TransactionType::Adjustment, [
            ['account' => $this->ledger->systemAccount(A::PlatformCash), 'direction' => D::Debit, 'amount' => $amount],
            ['account' => $this->projectFunds($contract), 'direction' => D::Credit, 'amount' => $amount],
        ], $idempotencyKey, ['project_id' => $contract->project_id, 'description' => 'Business remittance for '.$contract->contract_number, 'created_by' => $by?->id]);
    }

    /**
     * @param  Money  $netResult  signed actual net profit (positive) or loss (negative) for the whole project
     * @param  string|null  $idempotencyKey  same key + same request returns the original settlement; a different request is rejected
     */
    public function settle(Contract $contract, Money $netResult, User $by, bool $managerAtFault = false, ?string $reason = null, ?string $idempotencyKey = null): Settlement
    {
        if (! in_array($contract->contract_type, [ContractType::Mudarabah, ContractType::Musharakah], true)) {
            throw new FinancialException('Use the Murabaha payment flow to settle a Murabaha contract.');
        }
        $hash = IdempotencyGuard::hash(['op' => 'settle', 'contract' => $contract->id, 'net' => $netResult->minor, 'fault' => $managerAtFault]);
        try {
            return $this->post($contract, $netResult, $by, $managerAtFault, $reason, $idempotencyKey, $hash);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if ($idempotencyKey && ($existing = Settlement::where('idempotency_key', $idempotencyKey)->first())) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing->load('items');
            }
            throw new FinancialException('This contract has already been settled.');
        }
    }

    private function post(Contract $contract, Money $netResult, User $by, bool $managerAtFault, ?string $reason, ?string $key, string $hash): Settlement
    {
        return DB::transaction(function () use ($contract, $netResult, $by, $managerAtFault, $reason, $key, $hash) {
            if ($key && ($existing = Settlement::where('idempotency_key', $key)->first())) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing->load('items');
            }
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            Currency::require($contract->currency, 'Contract currency');
            Currency::require($contract->project->currency, 'Project currency');
            if ($contract->status !== ContractStatus::Active) {
                throw new FinancialException('Only an active contract can be settled.');
            }
            if (Settlement::where('contract_id', $contract->id)->where('status', SettlementStatus::Posted)->exists()) {
                throw new FinancialException('This contract has already been settled.');
            }

            $investments = Investment::where('project_id', $contract->project_id)
                ->whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active])->lockForUpdate()->orderBy('id')->get();
            if ($investments->isEmpty()) {
                throw new FinancialException('There are no investments to settle.');
            }
            $capital = Money::minor((int) $investments->sum('amount'));

            $r = $this->pools($contract, $capital, $netResult, $managerAtFault);

            $settlement = new Settlement(['reference' => 'STL-'.strtoupper(Str::random(8)), 'contract_id' => $contract->id, 'project_id' => $contract->project_id, 'currency' => Currency::CODE, 'actual_net_result' => $netResult->minor, 'created_by' => $by->id]);
            $settlement->forceFill(['status' => SettlementStatus::Draft, 'approved_by' => $by->id, 'idempotency_key' => $key, 'request_hash' => $hash])->save();

            $weights = $investments->pluck('amount')->map(fn ($a) => (int) $a)->all();
            $principals = $r['principal_pool']->allocate($weights);
            $profits = $r['investor_profit']->isZero() ? array_map(fn () => Money::zero(), $weights) : $r['investor_profit']->allocate($weights);
            $projectFunds = $this->projectFunds($contract);

            foreach ($investments as $i => $inv) {
                $wallet = $this->wallets->walletFor($inv->investor->user);
                $invested = Money::minor($inv->amount);
                $returned = $principals[$i];
                $loss = $invested->subtract($returned);

                if ($returned->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::PrincipalReturn, [
                        ['account' => $this->wallets->account($wallet, A::InvestorInvested), 'direction' => D::Debit, 'amount' => $returned],
                        ['account' => $this->wallets->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $returned],
                    ], "settlement:{$settlement->id}:principal:{$inv->id}", ['user_id' => $inv->investor->user_id, 'project_id' => $contract->project_id, 'investment_id' => $inv->id, 'description' => 'Principal returned — '.$contract->contract_number]);
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::Principal, 'amount' => $returned->minor, 'transaction_id' => $tx->id]);
                }
                if ($loss->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::Adjustment, [
                        ['account' => $this->wallets->account($wallet, A::InvestorInvested), 'direction' => D::Debit, 'amount' => $loss],
                        ['account' => $this->ledger->systemAccount(A::Clearing, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $loss],
                    ], "settlement:{$settlement->id}:loss:{$inv->id}", ['user_id' => $inv->investor->user_id, 'project_id' => $contract->project_id, 'investment_id' => $inv->id, 'description' => 'Loss allocated — '.$contract->contract_number]);
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::Adjustment, 'amount' => -$loss->minor, 'transaction_id' => $tx->id]);
                }
                if ($profits[$i]->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::ProfitDistribution, [
                        ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $profits[$i]],
                        ['account' => $this->wallets->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $profits[$i]],
                    ], "settlement:{$settlement->id}:profit:{$inv->id}", ['user_id' => $inv->investor->user_id, 'project_id' => $contract->project_id, 'investment_id' => $inv->id, 'description' => 'Profit distribution — '.$contract->contract_number]);
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::InvestmentProfit, 'amount' => $profits[$i]->minor, 'transaction_id' => $tx->id]);
                }
                $inv->forceFill(['status' => InvestmentStatus::Completed])->save();
                $this->notify->to($inv->investor->user, 'Contract settled', 'Principal returned: '.$returned->format().'. Profit distributed: '.$profits[$i]->format().'.', 'success', route('investor.investments.show', $inv));
            }

            // The business's own share of profit is a first-class record, never silently dropped.
            $business = $contract->project->business;
            if ($r['business_profit']->isPositive()) {
                $tx = $this->ledger->post(TransactionType::ProfitDistribution, [
                    ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $r['business_profit']],
                    ['account' => $this->ledger->systemAccount(A::BusinessFunds, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $r['business_profit']],
                ], "settlement:{$settlement->id}:business-profit", ['user_id' => $business->user_id, 'project_id' => $contract->project_id, 'description' => 'Business profit share — '.$contract->contract_number]);
                $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::BusinessProfitShare, 'amount' => $r['business_profit']->minor, 'transaction_id' => $tx->id]);
            }

            // Ordinary business loss falls on capital. Only documented fault creates a recoverable amount.
            if ($r['manager_liability']->isPositive()) {
                if (blank($reason)) {
                    throw new FinancialException('Record the documented negligence, misconduct or breach before attributing a loss to the manager.');
                }
                ManagerRecovery::create(['contract_id' => $contract->id, 'business_id' => $business->id, 'amount' => $r['manager_liability']->minor, 'reason' => $reason, 'created_by' => $by->id] + ['settlement_id' => $settlement->id]);
                $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::ManagerLiability, 'amount' => $r['manager_liability']->minor]);
            }

            $r['terms']->forceFill(['actual_net_result' => $netResult->minor])->save();
            $contract->forceFill(['status' => ContractStatus::Completed, 'recovery_status' => $r['manager_liability']->isPositive() ? RecoveryStatus::InRecovery : $contract->recovery_status])->save();
            $contract->project->forceFill(['status' => ProjectStatus::Completed])->save();
            $settlement->forceFill(['status' => SettlementStatus::Posted, 'posted_at' => now()])->save();
            $this->audit->record('settlement.posted', $settlement, null, [
                'net_result' => $netResult->minor, 'manager_at_fault' => $managerAtFault, 'business_profit' => $r['business_profit']->minor, 'manager_liability' => $r['manager_liability']->minor,
            ], $reason);

            return $settlement->load('items');
        });
    }

    /**
     * @return array{principal_pool: Money, investor_profit: Money, business_profit: Money, manager_liability: Money, terms: \Illuminate\Database\Eloquent\Model}
     */
    private function pools(Contract $contract, Money $capital, Money $net, bool $atFault): array
    {
        $zero = Money::zero();
        if ($contract->contract_type === ContractType::Mudarabah) {
            $t = $contract->mudarabah ?? throw new FinancialException('Mudarabah terms are missing.');
            $r = $this->mudarabah->settle($capital, $net, $t->investor_profit_bps, $t->business_profit_bps, $atFault);
            $out = ['principal_pool' => $r['principal_returned'], 'investor_profit' => $r['investor_profit'], 'business_profit' => $r['business_profit'], 'manager_liability' => $r['manager_liability'], 'terms' => $t];
        } else {
            $t = $contract->musharakah ?? throw new FinancialException('Musharakah terms are missing.');
            if ($t->loss_allocation_basis === LossAllocationBasis::AgreedRatio && ! $this->lossExceptionApproved($t)) {
                throw new FinancialException('An agreed loss ratio requires documented Shariah approval. Musharakah losses follow capital contribution by default.');
            }
            $r = $this->musharakah->settle($capital, Money::minor($t->business_contribution), $net, $t->investor_profit_bps, $t->business_profit_bps, $t->loss_allocation_basis, $this->lossExceptionApproved($t));
            $out = ['principal_pool' => $capital->subtract($r['investor_loss']), 'investor_profit' => $r['investor_profit'], 'business_profit' => $r['business_profit'], 'manager_liability' => $zero, 'terms' => $t];
        }

        // The pools must reconcile exactly to the actual result before anything is posted.
        $profit = $out['investor_profit']->add($out['business_profit']);
        if ($net->isPositive() ? ! $profit->equals($net) : ! $profit->isZero()) {
            throw new FinancialException('Settlement does not reconcile to the actual result.');
        }
        if ($out['principal_pool']->minor > $capital->minor || $out['principal_pool']->isNegative()) {
            throw new FinancialException('Returned principal cannot exceed the capital provided.');
        }

        return $out;
    }

    private function lossExceptionApproved($terms): bool
    {
        return $terms->loss_exception_approved_by !== null && $terms->loss_exception_approved_at !== null && filled($terms->loss_exception_reason);
    }

    private function projectFunds(Contract $contract)
    {
        return $this->ledger->systemAccount(A::ProjectFunds, Currency::CODE, $contract->project_id);
    }
}
