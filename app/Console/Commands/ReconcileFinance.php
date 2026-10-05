<?php

namespace App\Console\Commands;

use App\Services\Finance\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;

class ReconcileFinance extends Command
{
    protected $signature = 'finance:reconcile {--strict : Treat warnings (e.g. legacy records) as failures; for CI and deployment gates}';

    protected $description = 'Run read-only financial integrity checks (BDT-only, ledger, wallets, investments, settlements, Murabaha)';

    public function handle(ReconciliationService $service): int
    {
        $strict = (bool) $this->option('strict');
        $results = $service->run();

        $this->line('Financial Reconciliation'.($strict ? ' (strict)' : ''));
        $this->line('========================');
        foreach ($results as $r) {
            $this->line(str_pad($r->name, 26).($r->passed($strict) ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'));
        }

        $problems = collect($results)->flatMap(fn ($r) => array_map(fn ($m) => "[{$r->name}] $m", $r->errors));
        $warnings = collect($results)->flatMap(fn ($r) => array_map(fn ($m) => "[{$r->name}] $m", $r->warnings));
        if ($problems->isNotEmpty()) {
            $this->newLine();
            $this->line('Errors:');
            $problems->each(fn ($m) => $this->line("- $m"));
        }
        if ($warnings->isNotEmpty()) {
            $this->newLine();
            $this->line(($strict ? 'Warnings (fail in strict mode):' : 'Warnings:'));
            $warnings->each(fn ($m) => $this->line("- $m"));
        }

        $this->newLine();
        if (ReconciliationService::passed($results, $strict)) {
            $this->info('No financial inconsistencies detected.');

            return self::SUCCESS;
        }
        $this->error('Reconciliation FAILED.');

        return self::FAILURE;
    }
}
