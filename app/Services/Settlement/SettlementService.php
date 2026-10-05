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

    /**
     * The business remits the actual proceeds (profit) into the project pool: Dr Platform cash / Cr Project funds.
     * Settlement distributes only money the pool really holds, so this must precede a profitable settlement.
     */
    public function recordBusinessRemittance(Contract $contract, Money $amount, string $idempotencyKey, ?User $by = null)
    {
        Currency::require($contract->currency, 'Contract currency');
        if (! in_array($contract->contract_type, [ContractType::Mudarabah, ContractType::Musharakah], true)) {
            throw new FinancialException('Only Mudarabah and Musharakah contracts take a business remittance.');
        }
        if (! $amount->isPositive()) {
            throw new FinancialException('Enter a remittance amount greater than zero.');
        }
        if ($contract->fresh()->status !== ContractStatus::Active) {
            throw new FinancialException('A remittance can only be recorded for an active contract.');
        }
        $tx = $this->ledger->post(TransactionType::BusinessRemittance, [
            ['account' => $this->ledger->systemAccount(A::PlatformCash), 'direction' => D::Debit, 'amount' => $amount],
            ['account' => $this->projectFunds($contract), 'direction' => D::Credit, 'amount' => $amount],
        ], $idempotencyKey, ['project_id' => $contract->project_id, 'description' => 'Business remittance — '.$contract->contract_number, 'created_by' => $by?->id]);
        $this->audit->record('contract.remittance_recorded', $contract, null, ['amount' => $amount->minor, 'transaction_id' => $tx->id]);

        return $tx;
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

            $r = $this->pools($contract, $capital, $bizCapital, $netResult, $managerAtFault);

            // Settlement distributes only funds that exist: capital held + remitted proceeds must cover every payout.
            $distribution = $capital->add($bizCapital)->add($r['investor_profit'])->add($r['business_profit']);
            $poolBalance = (int) \App\Models\LedgerAccount::whereKey($this->projectFunds($contract)->id)->lockForUpdate()->value('balance');
            if ($poolBalance < $distribution->minor) {
                throw new FinancialException('The project does not hold enough funds to settle. Record the business remittance of the actual profit first (short by '.Money::minor($distribution->minor - $poolBalance)->format().').');
            }

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
                // The invested capital leaves the project pool (returned or lost); mirrors the funding leg.
                $this->ledger->post(TransactionType::CapitalRelease, [
                    ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $invested],
                    ['account' => $this->ledger->systemAccount(A::CapitalDeployed, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $invested],
                ], "settlement:{$settlement->id}:release:{$inv->id}", ['project_id' => $contract->project_id, 'investment_id' => $inv->id, 'description' => 'Project capital release — '.$contract->contract_number]);
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

            // Musharakah: the business partner's own capital is returned (less its share of any loss) from the pool.
            if ($contract->contract_type === ContractType::Musharakah) {
                if ($r['business_capital_return']->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::BusinessCapitalReturn, [
                        ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $r['business_capital_return']],
                        ['account' => $this->ledger->systemAccount(A::BusinessFunds, Currency::CODE, $contract->project_id), 'direction' => D::Credit, 'amount' => $r['business_capital_return']],
                    ], "settlement:{$settlement->id}:business-capital", ['user_id' => $business->user_id, 'project_id' => $contract->project_id, 'description' => 'Business capital returned — '.$contract->contract_number]);
                    $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::BusinessCapitalReturn, 'amount' => $r['business_capital_return']->minor, 'transaction_id' => $tx->id]);
                }
                if ($r['business_loss']->isPositive()) {
                    // Ordinary commercial loss is borne as capital: the funds are consumed, never a debt of the business.
                    $tx = $this->ledger->post(TransactionType::CapitalLoss, [
                        ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $r['business_loss']],
                        ['account' => $this->ledger->systemAccount(A::PlatformCash), 'direction' => D::Credit, 'amount' => $r['business_loss']],
                    ], "settlement:{$settlement->id}:business-loss", ['user_id' => $business->user_id, 'project_id' => $contract->project_id, 'description' => 'Business share of capital loss — '.$contract->contract_number]);
                    $settlement->items()->forceCreate(['user_id' => $business->user_id, 'item_type' => Item::BusinessCapitalLoss, 'amount' => -$r['business_loss']->minor, 'transaction_id' => $tx->id]);
                }
                $contract->musharakahContribution?->forceFill(['status' => \App\Enums\CapitalContributionStatus::Settled])->save();
            }

            // The business's own share of profit is a first-class record, never silently dropped.
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
            $contract->transitionTo(ContractStatus::Completed);
            $contract->forceFill(['recovery_status' => $r['manager_liability']->isPositive() ? RecoveryStatus::InRecovery : $contract->recovery_status])->save();
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
