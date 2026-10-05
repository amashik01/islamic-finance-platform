<?php

namespace App\Services\Shariah;

use App\Models\ShariahRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Single place that knows which Shariah rules the code relies on. config/shariah_rules.php is the authoritative text;
 * the shariah_rules table mirrors it with a version, so a review can reference the exact rule version it covered.
 */
class ShariahRuleRegistry
{
    private const DEFAULTS = ['standard_code' => null, 'clause_reference' => null, 'source_url' => null, 'system_effect' => null, 'scholar' => 'PENDING', 'guidance_text' => null, 'source_type' => 'PLATFORM_POLICY'];

    /** @return Collection<string, array<string, mixed>> keyed by code */
    public function all(): Collection
    {
        return collect(config('shariah_rules', []))->map(fn (array $r) => $r + self::DEFAULTS)->keyBy('code');
    }

    /** @return array<string, mixed> */
    public function find(string $code): array
    {
        return $this->all()->get($code) ?? throw new \InvalidArgumentException("Unknown Shariah rule [$code].");
    }

    /** @param list<string> $codes @return Collection<int, array<string, mixed>> */
    public function many(array $codes): Collection
    {
        return collect($codes)->map(fn ($c) => $this->find($c));
    }

    /** Mirrors the config into the table. A changed rule becomes a new version; the old version is SUPERSEDED, never rewritten. */
    public function sync(): int
    {
        if (! Schema::hasTable('shariah_rules')) {
            return 0;
        }
        $changed = 0;
        foreach ($this->all() as $code => $r) {
            $attrs = [
                'aqd_type' => $r['aqd_type'], 'title' => $r['title'], 'rule_text' => $r['rule_text'], 'guidance_text' => $r['guidance_text'], 'classification' => $r['classification'],
                'source_type' => $r['source_type'], 'source_name' => $r['source_name'], 'standard_code' => $r['standard_code'], 'clause_reference' => $r['clause_reference'],
                'source_url' => $r['source_url'], 'verification' => $r['verification'], 'system_effect' => $r['system_effect'],
            ];
            $current = ShariahRule::where('code', $code)->where('status', '!=', 'SUPERSEDED')->orderByDesc('version')->first();
            if ($current && $current->only(array_keys($attrs)) == $attrs) {
                continue;
            }
            if ($current) {
                $current->forceFill(['status' => 'SUPERSEDED'])->save();
            }
            ShariahRule::unguarded(fn () => ShariahRule::create(['code' => $code, 'version' => ($current?->version ?? 0) + 1, 'status' => 'UNDER_REVIEW', 'scholar_review_status' => $r['scholar']] + $attrs));
            $changed++;
        }

        return $changed;
    }
}
