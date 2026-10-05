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
    protected $signature = 'finance:probe {action : invest|withdraw} {investor} {amount} {key} {project?}';

    protected $description = 'Concurrency probe (testing only)';

    public function handle(InvestmentService $investments, WalletService $wallets): int
    {
        if (app()->isProduction()) {
            return self::FAILURE;
        }
        try {
            $investor = Investor::findOrFail($this->argument('investor'));
            $amount = Money::parse($this->argument('amount'));
            if ($this->argument('action') === 'invest') {
                $investments->invest($investor, Project::findOrFail($this->argument('project')), $amount, $this->argument('key'));
            } else {
                $wallets->requestWithdrawal($investor->user, $amount, $this->argument('key'));
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
