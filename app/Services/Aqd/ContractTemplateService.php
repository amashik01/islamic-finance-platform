<?php

namespace App\Services\Aqd;

use App\Enums\ShariahReviewStatus;
use App\Exceptions\FinancialException;
use App\Models\ContractClause;
use App\Models\ContractTemplate;
use App\Models\ContractTemplateVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Template versions are immutable once created. A change in the clause source becomes a NEW version (the previous one is
 * SUPERSEDED), so every executed agreement keeps pointing at the exact clauses it was generated from. A version can be used
 * only after a qualified Shariah reviewer has approved THAT version (a review applies to an aqd type, a template version and a
 * structure - never to the whole platform).
 */
class ContractTemplateService
{
    public function __construct(private AuditLogger $audit) {}

    /** Creates missing templates and a new version when the clause source changed. Returns the number of versions written. */
    public function seed(): int
    {
        $written = 0;
        foreach (require database_path('data/contract_templates.php') as $code => $def) {
            $template = ContractTemplate::firstOrCreate(['code' => $code], ['aqd_type' => $def['aqd'], 'kind' => $def['kind'], 'title' => $def['title']]);
            $hash = $this->sourceHash($def['clauses']);
            $latest = $template->versions()->orderByDesc('version')->first();
            if ($latest && $latest->content_hash === $hash) {
                continue;
            }
            DB::transaction(function () use ($template, $def, $hash, $latest, &$written) {
                $latest?->forceFill(['status' => 'SUPERSEDED', 'effective_until' => now()->toDateString()])->save();
                $v = ContractTemplateVersion::unguarded(fn () => ContractTemplateVersion::create([
                    'template_id' => $template->id, 'version' => ($latest?->version ?? 0) + 1, 'language' => 'en', 'status' => 'ACTIVE', 'effective_from' => now()->subDay()->toDateString(), 'content_hash' => $hash,
                ]));
                foreach ($def['clauses'] as $i => $c) {
                    ContractClause::create(['template_version_id' => $v->id, 'position' => $i + 1, 'code' => $c[0], 'heading' => $c[1], 'body' => $c[2], 'condition' => $c[3] ?? null, 'rule_codes' => $c[4] ?? null]);
                }
                $written++;
            });
        }

        return $written;
    }

    /** @param list<array<int, mixed>> $clauses */
    private function sourceHash(array $clauses): string
    {
        return hash('sha256', collect($clauses)->map(fn ($c, $i) => implode("\x1f", [$i + 1, $c[0], $c[1], $c[2], (string) ($c[3] ?? '')]))->implode("\x1e"));
    }

    /** The version that may be used today: ACTIVE, inside its effective window, and approved by a Shariah reviewer. */
    public function usableVersion(string $code): ContractTemplateVersion
    {
        $v = ContractTemplateVersion::whereHas('template', fn ($q) => $q->where('code', $code))->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', now()->toDateString())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()->toDateString()))
            ->orderByDesc('version')->first();
        if (! $v) {
            throw new FinancialException("No active template is available for $code.");
        }
        if ($v->shariah_review_status !== 'APPROVED') {
            throw new FinancialException("The $code template (version {$v->version}) has no Shariah reviewer's approval yet, so no agreement can be generated from it.");
        }

        return $v;
    }

    public function review(ContractTemplateVersion $version, User $reviewer, ShariahReviewStatus $decision, ?string $notes): ContractTemplateVersion
    {
        if (! $reviewer->can('shariah.review')) {
            throw new FinancialException('Only a Shariah reviewer can review a contract template.');
        }
        if ($decision === ShariahReviewStatus::Pending) {
            throw new FinancialException('Choose approve, reject or request revision.');
        }
        if ($decision !== ShariahReviewStatus::Approved && blank($notes)) {
            throw new FinancialException('Record the reason for the decision.');
        }
        if ($version->computeContentHash() !== $version->content_hash) {
            throw new FinancialException('The template clauses do not match their recorded hash; the version was altered and cannot be approved.');
        }
        $version->forceFill(['shariah_review_status' => $decision->value, 'shariah_reviewer_id' => $reviewer->id, 'shariah_reviewed_at' => now(), 'shariah_notes' => $notes])->save();
        $this->audit->record('aqd.template_reviewed', $version->template, null, ['version' => $version->version, 'decision' => $decision->value], $notes);

        return $version;
    }
}
