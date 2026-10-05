<?php

namespace App\Services\Wallet;

use App\Enums\EntryDirection as D;
use App\Enums\InvestmentStatus;
use App\Enums\KycStatus;
use App\Enums\LedgerAccountType as A;
use App\Enums\ProjectStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Contract;
use App\Models\Project;
use App\Services\Finance\IdempotencyGuard;
use App\Services\Ledger\LedgerService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

class InvestmentService
{
    public function __construct(private LedgerService $ledger, private WalletService $wallets, private \App\Services\Notify\Notifier $notify, private \App\Services\Settings\SettingsService $settings, private \App\Services\Contract\ContractLifecycle $lifecycle, private \App\Services\Aqd\AqdGate $gate) {}

    /**
     * Accepts an investment atomically, once per idempotency key: Dr InvestorAvailable / Cr InvestorInvested.
     * That is the investor's capital COMMITTED to the venture (capital at risk), not a deployment: the cash stays in client
     * custody until CapitalDeploymentService records the real delivery of the capital to the business. Funding is not
     * ownership, deployment, profit, sale or settlement.
     * If this investment completes the funding, the project and its contract are activated in the same transaction.
     */
    public function invest(Investor $investor, Project $project, Money $amount, string $idempotencyKey, ?\App\Models\ContractDocument $participation = null): Investment
    {
        $hash = IdempotencyGuard::hash(['op' => 'invest', 'investor' => $investor->id, 'project' => $project->id, 'amount' => $amount->minor]);
        try {
            return $this->place($investor, $project, $amount, $idempotencyKey, $hash, $participation);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // A parallel request with the same key won the race: return its result, never a second investment.
            $existing = Investment::where('idempotency_key', $idempotencyKey)->firstOrFail();
            IdempotencyGuard::assertMatches($existing->request_hash, $hash);

            return $existing;
        }
    }

    private function place(Investor $investor, Project $project, Money $amount, string $idempotencyKey, string $hash, ?\App\Models\ContractDocument $participation): Investment
    {
        return DB::transaction(function () use ($investor, $project, $amount, $idempotencyKey, $hash, $participation) {
            if ($existing = Investment::where('idempotency_key', $idempotencyKey)->first()) {
                IdempotencyGuard::assertMatches($existing->request_hash, $hash);

                return $existing;
            }
            if ($investor->kyc_status !== KycStatus::Approved) {
                throw new FinancialException('Complete identity verification before investing.');
            }

            // Lock order everywhere: project -> contract -> investments -> ledger accounts (deadlock-safe).
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $contract = Contract::where('project_id', $project->id)->lockForUpdate()->first();
            // Single-currency invariant: amount, project, contract and wallet must all be BDT.
            Currency::require($amount->currency, 'The investment amount');
            Currency::require($project->currency, 'The project');
            $contract && Currency::require($contract->currency, 'The contract');
            if ($project->status !== ProjectStatus::Funding || ($project->closing_at && $project->closing_at->isPast())) {
                throw new FinancialException('This investment is no longer accepting funds.');
            }
            if ($project->contract_type === \App\Enums\ContractType::Murabaha) {
                throw new FinancialException('Murabaha financing is not an investment product.');
            }
            if ($amount->minor < $project->minimum_amount || $amount->minor < $this->settings->minor('finance.min_investment')) {
                throw new FinancialException('The amount is below the minimum for this project.');
            }
            if ($amount->minor > $project->funding_target - $project->funded_amount) {
                throw new FinancialException('The amount exceeds the remaining funding capacity.');
            }

            // Financial activation gate: the executed agreements, the Shariah review, the Wakalah and the platform role, checked under the locks.
            $agreement = $this->gate->assertCanInvest($project, $investor, $amount, $participation);

            $wallet = $this->wallets->walletFor($investor->user);
            $investment = Investment::unguarded(fn () => Investment::create([
                'participation_document_id' => $agreement->id,
                'investor_id' => $investor->id,
                'project_id' => $project->id,
                'contract_id' => $contract?->id,
                'amount' => $amount->minor,
                'currency' => $amount->currency,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'status' => InvestmentStatus::Confirmed,
                'invested_at' => now(),
                'maturity_date' => now()->addMonths($project->duration_months)->toDateString(),
            ]));

            $agreement->forceFill(['consumed_by_investment_id' => $investment->id])->save();   // one agreement funds exactly one investment

            $this->ledger->post(TransactionType::Investment, [
                ['account' => $this->wallets->account($wallet, A::InvestorAvailable), 'direction' => D::Debit, 'amount' => $amount],
                ['account' => $this->wallets->account($wallet, A::InvestorInvested), 'direction' => D::Credit, 'amount' => $amount],
            ], 'investment:'.$investment->id, [
                'user_id' => $investor->user_id, 'project_id' => $project->id, 'investment_id' => $investment->id,
                'description' => 'Investment in '.$project->title,
            ]);

            $project->forceFill(['funded_amount' => $project->funded_amount + $amount->minor])->save();
            $this->lifecycle->activateIfFunded($project);   // project + contract, atomically, exactly once

            $this->notify->to($investor->user, 'Investment confirmed', 'You invested '.$amount->format().' in '.$project->title.'.', 'success', route('investor.investments.show', $investment));
            if ($project->status === ProjectStatus::Active) {
                $this->notify->to($project->business->user, 'Funding completed', $project->title.' has reached its funding target.', 'success', route('business.projects.show', $project));
            }

            return $investment;
        }, 3);   // retried automatically on a database deadlock
    }
}
