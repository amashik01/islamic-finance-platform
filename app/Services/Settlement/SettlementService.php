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
use App\Models\Project;
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
 * Settles Mudarabah / Musharakah contracts in BDT. Settlement is an explicit financial event that:
 *   - verifies the capital was deployed and that the recorded remittances (capital returned + interim proceeds) imply exactly
 *     the result being settled (result = capital returned + interim proceeds - capital deployed);
 *   - recognises an ordinary loss against the VENTURE CAPITAL asset and the capital partners' claims (no debt of any party,
 *     no cash movement, never Clearing);
 *   - makes the remaining capital withdrawable / payable, and distributes the agreed shares of actual profit from the
 *     interim proceeds held in ProjectFunds;
 *   - opens a SUSPECTED manager recovery (memo only, no ledger asset) when fault is alleged.
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
            // Lock order everywhere: project -> contract -> investments -> ledger accounts.
            $project = Project::whereKey($contract->project_id)->lockForUpdate()->firstOrFail();
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            Currency::require($contract->currency, 'Contract currency');
            Currency::require($project->currency, 'Project currency');
            if ($contract->status !== ContractStatus::Active) {
                throw new FinancialException('Only an active contract can be settled.');
            }
            if (Settlement::where('contract_id', $contract->id)->where('status', SettlementStatus::Posted)->exists()) {
                throw new FinancialException('This contract has already been settled.');
            }

            if ($managerAtFault && $netResult->isNegative() && blank($reason)) {
                throw new FinancialException('Record the documented negligence, misconduct or breach before attributing a loss to the manager.');
            }

            $investments = Investment::where('project_id', $contract->project_id)
                ->whereIn('status', [InvestmentStatus::Confirmed, InvestmentStatus::Active])->lockForUpdate()->orderBy('id')->get();
            if ($investments->isEmpty()) {
                throw new FinancialException('There are no investments to settle.');
            }
            $capital = Money::minor((int) $investments->sum('amount'));

            $business = $project->business;
            $bizCapital = Money::zero();
            if ($contract->contract_type === ContractType::Musharakah) {
                $bizCapital = $this->verifiedBusinessCapital($contract, $capital);
            }
            $venture = $capital->add($bizCapital);

            // The venture must have received the capital, and the recorded remittances must imply exactly this result.
            $deployed = \App\Models\CapitalDeployment::where('contract_id', $contract->id)->first();
            if (! $deployed || $deployed->amount !== $venture->minor) {
                throw new FinancialException('The capital has not been deployed to the venture. Record the capital deployment before settling.');
            }
            $returned = (int) \App\Models\VentureRemittance::where('contract_id', $contract->id)->where('component', \App\Models\VentureRemittance::CAPITAL_RETURN)->sum('amount');
            $interim = (int) \App\Models\VentureRemittance::where('contract_id', $contract->id)->where('component', \App\Models\VentureRemittance::INTERIM_PROCEEDS)->sum('amount');
            $implied = $returned + $interim - $venture->minor;
            if ($implied !== $netResult->minor) {
                throw new FinancialException('The recorded remittances imply a result of '.Money::minor($implied)->format().', not '.$netResult->format().'. Record the capital returned and the interim proceeds first (capital returned '.Money::minor($returned)->format().', interim proceeds '.Money::minor($interim)->format().').');
            }
            if ($netResult->isPositive() && $returned !== $venture->minor) {
                throw new FinancialException('The deployed capital has not been fully returned. Record the capital returned before settling a profit.');
            }
            if (! $netResult->isPositive() && $interim > 0) {
                throw new FinancialException('Interim proceeds were received but the final result is not a profit. Applying advances against capital needs a qualified accounting and Shariah review and is not automated.');
            }

            $r = $this->pools($contract, $capital, $bizCapital, $netResult, $managerAtFault);

            $settlement = new Settlement(['reference' => 'STL-'.strtoupper(Str::random(8)), 'contract_id' => $contract->id, 'project_id' => $contract->project_id, 'currency' => Currency::CODE, 'actual_net_result' => $netResult->minor, 'created_by' => $by->id]);
            $settlement->forceFill(['status' => SettlementStatus::Draft, 'approved_by' => $by->id, 'idempotency_key' => $key, 'request_hash' => $hash])->save();

            $weights = $investments->pluck('amount')->map(fn ($a) => (int) $a)->all();
            $principals = $r['principal_pool']->allocate($weights);
            $profits = $r['investor_profit']->isZero() ? array_map(fn () => Money::zero(), $weights) : $r['investor_profit']->allocate($weights);
            $projectFunds = $this->projectFunds($contract);
            $ventureCapital = $this->ledger->systemAccount(A::VentureCapital, Currency::CODE, $contract->project_id);
            $meta = fn (array $more = []) => ['project_id' => $contract->project_id] + $more;

            foreach ($investments as $i => $inv) {
                $wallet = $this->wallets->walletFor($inv->investor->user);
                $invested = Money::minor($inv->amount);
                $kept = $principals[$i];
                $loss = $invested->subtract($kept);

                if ($loss->isPositive()) {
                    // Ordinary loss: the investor's capital at risk and the venture asset are written down together.
                    $tx = $this->ledger->post(TransactionType::LossRecognition, [
                        ['account' => $this->wallets->account($wallet, A::InvestorInvested), 'direction' => D::Debit, 'amount' => $loss],
                        ['account' => $ventureCapital, 'direction' => D::Credit, 'amount' => $loss],
                    ], "settlement:{$settlement->id}:loss:{$inv->id}", $meta(['user_id' => $inv->investor->user_id, 'investment_id' => $inv->id, 'description' => 'Capital loss recognised — '.$contract->contract_number]));
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::Adjustment, 'amount' => -$loss->minor, 'transaction_id' => $tx->id]);
                }
                if ($kept->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::PrincipalReturn, [
                        ['account' => $this->wallets->account($wallet, A::InvestorInvested), 'direction' => D::Debit, 'amount' => $kept],
                        ['account' => $this->wallets->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $kept],
                    ], "settlement:{$settlement->id}:principal:{$inv->id}", $meta(['user_id' => $inv->investor->user_id, 'investment_id' => $inv->id, 'description' => 'Capital recovered — '.$contract->contract_number]));
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::Principal, 'amount' => $kept->minor, 'transaction_id' => $tx->id]);
                }
                if ($profits[$i]->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::ProfitDistribution, [
                        ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $profits[$i]],
                        ['account' => $this->wallets->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $profits[$i]],
                    ], "settlement:{$settlement->id}:profit:{$inv->id}", $meta(['user_id' => $inv->investor->user_id, 'investment_id' => $inv->id, 'description' => 'Profit distribution — '.$contract->contract_number]));
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::InvestmentProfit, 'amount' => $profits[$i]->minor, 'transaction_id' => $tx->id]);
                }
                $inv->forceFill(['status' => InvestmentStatus::Completed])->save();
                $this->notify->to($inv->investor->user, 'Contract settled', 'Capital recovered: '.$kept->format().'. Profit distributed: '.$profits[$i]->format().'.', 'success', route('investor.investments.show', $inv));
            }

            // Musharakah: the business partner's capital absorbs its capital-ratio share of loss; the rest becomes payable.
            if ($contract->contract_type === ContractType::Musharakah) {
                $businessAccount = $this->ledger->systemAccount(A::BusinessCapital, Currency::CODE, $contract->project_id);
                if ($r['business_loss']->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::LossRecognition, [
                        ['account' => $businessAccount, 'direction' => D::Debit, 'amount' => $r['business_loss']],
                        ['account' => $ventureCapital, 'direction' => D::Credit, 'amount' => $r['business_loss']],
                    ], "settlement:{$settlement->id}:business-loss", $meta(['user_id' => $business->user_id, 'description' => 'Business share of capital loss — '.$contract->contract_number]));
                    $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::BusinessCapitalLoss, 'amount' => -$r['business_loss']->minor, 'transaction_id' => $tx->id]);
                }
                if ($r['business_capital_return']->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::BusinessCapitalReturn, [
                        ['account' => $businessAccount, 'direction' => D::Debit, 'amount' => $r['business_capital_return']],
                        ['account' => $this->ledger->systemAccount(A::BusinessFunds, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $r['business_capital_return']],
                    ], "settlement:{$settlement->id}:business-capital", $meta(['user_id' => $business->user_id, 'description' => 'Business capital recovered — '.$contract->contract_number]));
                    $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::BusinessCapitalReturn, 'amount' => $r['business_capital_return']->minor, 'transaction_id' => $tx->id]);
                }
                $contract->musharakahContribution?->forceFill(['status' => \App\Enums\CapitalContributionStatus::Settled])->save();
            }

            // The business's own share of profit is a first-class record, never silently dropped.
            if ($r['business_profit']->isPositive()) {
                $tx = $this->ledger->post(TransactionType::ProfitDistribution, [
                    ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $r['business_profit']],
                    ['account' => $this->ledger->systemAccount(A::BusinessFunds, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $r['business_profit']],
                ], "settlement:{$settlement->id}:business-profit", $meta(['user_id' => $business->user_id, 'description' => 'Business profit share — '.$contract->contract_number]));
                $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::BusinessProfitShare, 'amount' => $r['business_profit']->minor, 'transaction_id' => $tx->id]);
            }

            // The venture asset must be fully closed and the interim pool fully distributed.
            $left = (int) \App\Models\LedgerAccount::whereKey($ventureCapital->id)->value('balance');
            $pool = (int) \App\Models\LedgerAccount::whereKey($projectFunds->id)->value('balance');
            if ($left !== 0 || $pool !== 0) {
                throw new FinancialException('Settlement left an unexplained balance (venture capital '.$left.', interim proceeds '.$pool.'); nothing was posted.');
            }

            // Ordinary business loss falls on capital. Only documented fault creates a recoverable amount.
            if ($r['manager_liability']->isPositive()) {
                if (blank($reason)) {
                    throw new FinancialException('Record the documented negligence, misconduct or breach before attributing a loss to the manager.');
                }
                $recovery = new ManagerRecovery(['contract_id' => $contract->id, 'business_id' => $business->id, 'amount' => $r['manager_liability']->minor, 'claimed_amount' => $r['manager_liability']->minor, 'reason' => $reason, 'created_by' => $by->id] + ['settlement_id' => $settlement->id]);
                $recovery->forceFill(['status' => ManagerRecoveryStatus::Suspected])->save();   // an allegation, not a liability: no ledger asset
                $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::ManagerLiability, 'amount' => $r['manager_liability']->minor]);
            }

            $r['terms']->forceFill(['actual_net_result' => $netResult->minor])->save();
            $contract->transitionTo(ContractStatus::Completed);
            $contract->forceFill(['recovery_status' => $contract->recovery_status])->save();
            $project->forceFill(['status' => ProjectStatus::Completed])->save();
            $settlement->forceFill(['status' => SettlementStatus::Posted, 'posted_at' => now()])->save();
            $this->audit->record('settlement.posted', $settlement, null, [
                'net_result' => $netResult->minor, 'manager_at_fault' => $managerAtFault, 'business_profit' => $r['business_profit']->minor, 'manager_liability' => $r['manager_liability']->minor,
            ], $reason);

            return $settlement->load('items');
        }, 3);   // retried automatically on a database deadlock
    }

    /**
     * The business's capital must be a recorded financial fact in exactly the agreed amount, and the funded investor
     * capital must equal the agreed investor contribution — otherwise there is nothing economically to settle.
     */
    private function verifiedBusinessCapital(Contract $contract, Money $investorCapital): Money
    {
        $t = $contract->musharakah ?? throw new FinancialException('Musharakah terms are missing.');
        $c = $contract->musharakahContribution;
        if (! $c || $c->transaction_id === null) {
            throw new FinancialException('The business capital contribution was never recorded; a Musharakah cannot be settled without it.');
        }
        if ($c->amount !== (int) $t->business_contribution) {
            throw new FinancialException('The recorded business contribution does not match the agreed contract terms.');
        }
        if ((int) $t->investor_contribution + (int) $t->business_contribution !== (int) $t->total_capital) {
            throw new FinancialException('The contract terms are inconsistent: investor and business capital do not add up to the total capital.');
        }
        if ($investorCapital->minor !== (int) $t->investor_contribution) {
            throw new FinancialException('The funded investor capital does not match the agreed investor contribution.');
        }

        return Money::minor($c->amount);
    }

    /**
     * @return array{principal_pool: Money, investor_profit: Money, business_profit: Money, business_capital_return: Money, business_loss: Money, manager_liability: Money, terms: \Illuminate\Database\Eloquent\Model}
     */
    private function pools(Contract $contract, Money $capital, Money $businessCapital, Money $net, bool $atFault): array
    {
        $zero = Money::zero();
        if ($contract->contract_type === ContractType::Mudarabah) {
            $t = $contract->mudarabah ?? throw new FinancialException('Mudarabah terms are missing.');
            $r = $this->mudarabah->settle($capital, $net, $t->investor_profit_bps, $t->business_profit_bps, $atFault);
            $out = ['principal_pool' => $r['principal_returned'], 'investor_profit' => $r['investor_profit'], 'business_profit' => $r['business_profit'], 'business_capital_return' => $zero, 'business_loss' => $zero, 'manager_liability' => $r['manager_liability'], 'terms' => $t];
        } else {
            $t = $contract->musharakah ?? throw new FinancialException('Musharakah terms are missing.');
            if ($t->loss_allocation_basis === LossAllocationBasis::AgreedRatio && ! ($t->legacy_loss_exception && $this->lossExceptionApproved($t))) {
                throw new FinancialException('An agreed loss ratio is not supported for new contracts: Musharakah losses follow capital contribution.');
            }
            $r = $this->musharakah->settle($capital, $businessCapital, $net, $t->investor_profit_bps, $t->business_profit_bps, $t->loss_allocation_basis, $this->lossExceptionApproved($t));
            $out = [
                'principal_pool' => $capital->subtract($r['investor_loss']), 'investor_profit' => $r['investor_profit'], 'business_profit' => $r['business_profit'],
                'business_capital_return' => $businessCapital->subtract($r['business_loss']), 'business_loss' => $r['business_loss'], 'manager_liability' => $zero, 'terms' => $t,
            ];
        }

        // The pools must reconcile exactly to the actual result and to the capital provided before anything is posted.
        $profit = $out['investor_profit']->add($out['business_profit']);
        if ($net->isPositive() ? ! $profit->equals($net) : ! $profit->isZero()) {
            throw new FinancialException('Settlement does not reconcile to the actual result.');
        }
        if ($out['principal_pool']->minor > $capital->minor || $out['principal_pool']->isNegative() || $out['business_capital_return']->isNegative()) {
            throw new FinancialException('A loss cannot exceed the capital provided.');
        }
        $returned = $out['principal_pool']->add($out['business_capital_return']);
        $lost = $capital->add($businessCapital)->subtract($returned);
        if ($net->isNegative() && $contract->contract_type === ContractType::Musharakah && $lost->minor !== -$net->minor) {
            throw new FinancialException('Capital returned and capital lost do not add up to the capital provided.');
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
