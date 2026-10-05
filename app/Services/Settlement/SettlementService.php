<?php

namespace App\Services\Settlement;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\EntryDirection as D;
use App\Enums\InvestmentStatus;
use App\Enums\LedgerAccountType as A;
use App\Enums\ProjectStatus;
use App\Enums\RecoveryStatus;
use App\Enums\SettlementItemType as Item;
use App\Enums\SettlementStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Models\Investment;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\MudarabahProfitCalculator;
use App\Services\Finance\MusharakahProfitCalculator;
use App\Services\Ledger\LedgerService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Settles Mudarabah / Musharakah contracts: an explicit financial event that returns principal
 * and distributes profit as separate ledger transactions and separate settlement items.
 */
class SettlementService
{
    public function __construct(
        private LedgerService $ledger,
        private WalletService $wallets,
        private MudarabahProfitCalculator $mudarabah,
        private MusharakahProfitCalculator $musharakah,
        private AuditLogger $audit,
    ) {}

    /** Business remits cash into the project (Dr platform cash / Cr project funds). */
    public function recordBusinessRemittance(Contract $contract, Money $amount, string $idempotencyKey, ?User $by = null)
    {
        return $this->ledger->post(TransactionType::Adjustment, [
            ['account' => $this->ledger->systemAccount(A::PlatformCash, $amount->currency), 'direction' => D::Debit, 'amount' => $amount],
            ['account' => $this->projectFunds($contract), 'direction' => D::Credit, 'amount' => $amount],
        ], $idempotencyKey, ['project_id' => $contract->project_id, 'description' => 'Business remittance for '.$contract->contract_number, 'created_by' => $by?->id]);
    }

    /** @param Money $netResult signed actual net profit (positive) or loss (negative) for the whole project */
    public function settle(Contract $contract, Money $netResult, User $by, bool $managerAtFault = false, ?string $reason = null): Settlement
    {
        if (! in_array($contract->contract_type, [ContractType::Mudarabah, ContractType::Musharakah], true)) {
            throw new FinancialException('Use the Murabaha payment flow to settle a Murabaha contract.');
        }

        return DB::transaction(function () use ($contract, $netResult, $by, $managerAtFault, $reason) {
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
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
            $currency = $contract->currency;
            $capital = Money::minor((int) $investments->sum('amount'), $currency);

            [$principalPool, $profitPool, $terms] = $this->poolResult($contract, $capital, $netResult, $managerAtFault);

            $settlement = new Settlement(['reference' => 'STL-'.strtoupper(Str::random(8)), 'contract_id' => $contract->id, 'project_id' => $contract->project_id, 'currency' => $currency, 'actual_net_result' => $netResult->minor, 'created_by' => $by->id]);
            $settlement->forceFill(['status' => SettlementStatus::Draft, 'approved_by' => $by->id])->save();

            $weights = $investments->pluck('amount')->map(fn ($a) => (int) $a)->all();
            $principals = $principalPool->allocate($weights);
            $profits = $profitPool->isZero() ? array_map(fn () => Money::zero($currency), $weights) : $profitPool->allocate($weights);
            $projectFunds = $this->projectFunds($contract);

            foreach ($investments as $i => $inv) {
                $wallet = $this->wallets->walletFor($inv->investor->user, $currency);
                $invested = Money::minor($inv->amount, $currency);
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
                    $this->ledger->post(TransactionType::Adjustment, [
                        ['account' => $this->wallets->account($wallet, A::InvestorInvested), 'direction' => D::Debit, 'amount' => $loss],
                        ['account' => $this->ledger->systemAccount(A::Clearing, $currency, $contract->project_id), 'direction' => D::Credit, 'amount' => $loss],
                    ], "settlement:{$settlement->id}:loss:{$inv->id}", ['user_id' => $inv->investor->user_id, 'project_id' => $contract->project_id, 'investment_id' => $inv->id, 'description' => 'Loss allocated — '.$contract->contract_number]);
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::Adjustment, 'amount' => -$loss->minor]);
                }
                if ($profits[$i]->isPositive()) {
                    $tx = $this->ledger->post(TransactionType::ProfitDistribution, [
                        ['account' => $projectFunds, 'direction' => D::Debit, 'amount' => $profits[$i]],
                        ['account' => $this->wallets->account($wallet, A::InvestorAvailable), 'direction' => D::Credit, 'amount' => $profits[$i]],
                    ], "settlement:{$settlement->id}:profit:{$inv->id}", ['user_id' => $inv->investor->user_id, 'project_id' => $contract->project_id, 'investment_id' => $inv->id, 'description' => 'Profit distribution — '.$contract->contract_number]);
                    $settlement->items()->forceCreate(['investment_id' => $inv->id, 'user_id' => $inv->investor->user_id, 'item_type' => Item::InvestmentProfit, 'amount' => $profits[$i]->minor, 'transaction_id' => $tx->id]);
                }
                $inv->forceFill(['status' => InvestmentStatus::Completed])->save();
            }

            $terms->forceFill(['actual_net_result' => $netResult->minor])->save();
            $contract->forceFill(['status' => ContractStatus::Completed, 'recovery_status' => $managerAtFault && $netResult->isNegative() ? RecoveryStatus::InRecovery : $contract->recovery_status])->save();
            $contract->project->forceFill(['status' => ProjectStatus::Completed])->save();
            $settlement->forceFill(['status' => SettlementStatus::Posted, 'posted_at' => now()])->save();
            $this->audit->record('settlement.posted', $settlement, null, ['net_result' => $netResult->minor, 'manager_at_fault' => $managerAtFault], $reason);

            return $settlement->load('items');
        });
    }

    /** @return array{0: Money, 1: Money, 2: \Illuminate\Database\Eloquent\Model} principal pool, profit pool, terms row */
    private function poolResult(Contract $contract, Money $capital, Money $net, bool $atFault): array
    {
        $zero = Money::zero($capital->currency);
        if ($contract->contract_type === ContractType::Mudarabah) {
            $t = $contract->mudarabah ?? throw new FinancialException('Mudarabah terms are missing.');
            $r = $this->mudarabah->settle($capital, $net, $t->investor_profit_bps, $t->business_profit_bps, $atFault);

            return [$r['principal_returned'], $r['investor_profit'], $t];
        }
        $t = $contract->musharakah ?? throw new FinancialException('Musharakah terms are missing.');
        $r = $this->musharakah->settle($capital, Money::minor($t->business_contribution, $capital->currency), $net, $t->investor_profit_bps, $t->business_profit_bps, $t->loss_allocation_basis);

        return [$capital->subtract($r['investor_loss']), $r['investor_profit'], $t];
    }

    private function projectFunds(Contract $contract)
    {
        $acct = $this->ledger->systemAccount(A::ProjectFunds, $contract->currency, $contract->project_id);
        if ($acct->normal_side !== 'CREDIT') {
            $acct->forceFill(['normal_side' => 'CREDIT'])->save();
        }

        return $acct;
    }
}
