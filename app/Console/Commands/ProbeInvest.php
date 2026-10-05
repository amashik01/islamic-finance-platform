<?php

namespace App\Console\Commands;

use App\Models\Investor;
use App\Models\Project;
use App\Services\Wallet\InvestmentService;
use App\Services\Wallet\WalletService;
use App\Support\Money\Money;
use Illuminate\Console\Command;

/** Test helper used by tests/Concurrency to race real database connections. Disabled in production. */
class ProbeInvest extends Command
{
    protected $signature = 'finance:probe {action : invest|withdraw|settle|contribute|remit} {investor : investor id (invest/withdraw) or contract id (settle/contribute/remit)} {amount} {key} {project?}';

    protected $description = 'Concurrency probe (testing only)';

    public function handle(InvestmentService $investments, WalletService $wallets): int
    {
        if (app()->isProduction()) {
            return self::FAILURE;
        }
        try {
            $amount = Money::parse($this->argument('amount'));
            $action = $this->argument('action');
            if (in_array($action, ['settle', 'contribute', 'remit'], true)) {
                $contract = \App\Models\Contract::findOrFail($this->argument('investor'));
                $admin = \App\Models\User::orderBy('id')->firstOrFail();
                match ($action) {
                    'settle' => app(\App\Services\Settlement\SettlementService::class)->settle($contract, $amount, $admin, false, 'probe', $this->argument('key')),
                    'contribute' => app(\App\Services\Contract\MusharakahCapitalService::class)->recordBusinessContribution($contract, $amount, $this->argument('key'), $admin),
                    'remit' => app(\App\Services\Settlement\SettlementService::class)->recordBusinessRemittance($contract, $amount, $this->argument('key'), $admin),
                };
            } else {
                $investor = Investor::findOrFail($this->argument('investor'));
                if ($action === 'invest') {
                    $investments->invest($investor, Project::findOrFail($this->argument('project')), $amount, $this->argument('key'));
                } else {
                    $wallets->requestWithdrawal($investor->user, $amount, $this->argument('key'));
                }
            }
            $this->line('OK');
        } catch (\App\Exceptions\FinancialException $e) {
            $this->line('REFUSED: '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->line('ERROR: '.$e::class.': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
