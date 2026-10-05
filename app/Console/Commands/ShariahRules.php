<?php

namespace App\Console\Commands;

use App\Services\Shariah\ShariahRuleRegistry;
use Illuminate\Console\Command;

class ShariahRules extends Command
{
    protected $signature = 'shariah:rules {action : sync|export}';

    protected $description = 'Sync the Shariah rule registry into the database, or export the rule matrix as Markdown';

    public function handle(ShariahRuleRegistry $registry): int
    {
        if ($this->argument('action') === 'sync') {
            $this->info($registry->sync().' rule version(s) written.');

            return self::SUCCESS;
        }
        $md = "# Shariah rule matrix\n\n> Generated from `config/shariah_rules.php` by `php artisan shariah:rules export`. Do not edit by hand.\n> Paraphrases, not quotations. Nothing here is a fatwa, a certification or legal advice. Every rule is UNDER_REVIEW until a qualified Shariah reviewer records otherwise.\n\n";
        $md .= "Verification: TEXT_READ = clause read in the source text; VIA_SUMMARY = official page fetched through a summariser, confirm numbering; SECONDARY = cited by a regulator compendium, base text not read; UNVERIFIED = **source verification required**.\n\n";
        foreach ($registry->all()->groupBy('aqd_type') as $aqd => $rules) {
            $md .= "## $aqd\n\n| Code | Rule | Class | Source | Clause | Verification | System effect |\n|---|---|---|---|---|---|---|\n";
            foreach ($rules as $r) {
                $md .= '| `'.$r['code'].'` | '.str_replace('|', '/', $r['title']).' | '.$r['classification'].' | '.str_replace('|', '/', $r['source_name']).' | '.($r['clause_reference'] ?? '—').' | '.$r['verification'].' | '.str_replace('|', '/', (string) ($r['system_effect'] ?? '—'))." |\n";
            }
            $md .= "\n";
        }
        file_put_contents(base_path('docs/shariah/SHARIAH_RULE_MATRIX.md'), $md);
        $this->info('Exported '.$registry->all()->count().' rules.');

        return self::SUCCESS;
    }
}
