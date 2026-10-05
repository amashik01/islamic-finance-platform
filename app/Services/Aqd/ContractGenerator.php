<?php

namespace App\Services\Aqd;

use App\Domain\Aqd\AqdRegistry;
use App\Enums\ContractDocumentKind as K;
use App\Enums\ContractDocumentStatus as S;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\KycStatus;
use App\Enums\ShariahReviewStatus;
use App\Enums\WakalahPrincipal;
use App\Enums\WakalahRole;
use App\Exceptions\FinancialException;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractTemplateVersion;
use App\Models\Investor;
use App\Models\Project;
use App\Models\ShariahReview;
use App\Models\User;
use App\Models\WakalahAppointment;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\MusharakahProfitCalculator;
use App\Services\Settings\SettingsService;
use App\Support\Money\Money;
use App\Support\Percent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates agreements from APPROVED data only: the project's stored terms, the verified identities, the Shariah review
 * and a Shariah-approved template version. Users never edit a clause. The text is hashed with SHA-256; the hash of the
 * terms (without the review block) is what a Shariah reviewer approves, the hash of the whole document is what is signed.
 */
class ContractGenerator
{
    private const REVIEW_MARK = '[SHARIAH REVIEW BLOCK]';

    public function __construct(private ContractTemplateService $templates, private AuditLogger $audit, private SettingsService $settings) {}

    /* ------------------------------------------------------------------ the project's master agreement */

    public function master(Project $project, ?User $by = null): ContractDocument
    {
        return DB::transaction(function () use ($project, $by) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $contract = $project->contract ?? throw new FinancialException('The project has no contract terms.');
            if ($contract->aqd_form_version === null) {
                throw new FinancialException('The contract-specific terms are incomplete (legacy project).');
            }
            $code = $project->contract_type->value.'-MASTER';
            $version = $this->templates->usableVersion($code);
            $review = $this->latestReview($project);
            $data = $this->commonData($project, $contract) + $this->termsData($project, $contract);

            return $this->make(K::MasterAqd, $project, $contract, $version, $data, $review, null, null, $by, null);
        });
    }

    /* ------------------------------------------------------------------ an investor's participation */

    public function participation(Project $project, Investor $investor, Money $amount, ?User $by = null): ContractDocument
    {
        return DB::transaction(function () use ($project, $investor, $amount, $by) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($project->contract_type === ContractType::Murabaha) {
                throw new FinancialException('Murabaha financing is not an investment product.');
            }
            if ($project->status !== \App\Enums\ProjectStatus::Funding) {
                throw new FinancialException('This investment is no longer accepting funds.');
            }
            $contract = $project->contract ?? throw new FinancialException('The project has no contract terms.');
            if ($investor->kyc_status !== KycStatus::Approved) {
                throw new FinancialException('Complete identity verification before signing a participation agreement.');
            }
            $master = ContractDocument::where('contract_id', $contract->id)->where('kind', K::MasterAqd->value)->where('status', S::Executed->value)->latest('id')->first();
            if (! $master) {
                throw new FinancialException('The project\'s agreement has not been executed yet, so no participation agreement can be made.');
            }
            if (! $amount->isPositive() || $amount->minor < $project->minimum_amount) {
                throw new FinancialException('The amount is below the minimum for this project.');
            }
            if ($amount->minor > $project->funding_target - $project->funded_amount) {
                throw new FinancialException('The amount exceeds the remaining funding capacity.');
            }
            $version = $this->templates->usableVersion($project->contract_type->value.'-PARTICIPATION');
            $data = $this->commonData($project, $contract) + $this->termsData($project, $contract) + [
                'investor_name' => $investor->user->name, 'investor_ref' => 'INV-'.str_pad((string) $investor->id, 6, '0', STR_PAD_LEFT), 'investor_kyc' => $investor->kyc_status->label(),
                'master_reference' => $master->reference, 'master_hash' => $master->document_hash, 'amount' => $amount->format(),
                'participation_share' => Percent::format(intdiv($amount->minor * 10000, max(1, $project->funding_target))),
            ];

            return $this->make(K::Participation, $project, $contract, $version, $data, $this->latestReview($project), $investor->user_id, $amount->minor, $by ?? $investor->user, null);
        });
    }

    /* ------------------------------------------------------------------ a Wakalah appointment */

    public function wakalah(WakalahAppointment $a, ?User $by = null): ContractDocument
    {
        return DB::transaction(function () use ($a, $by) {
            $project = $a->project;
            $contract = $project->contract;
            $version = $this->templates->usableVersion('WAKALAH-APPOINTMENT');
            $role = $a->wakalah_role;
            $data = $this->commonData($project, $contract) + [
                'muwakkil_label' => $a->muwakkil ? WakalahPrincipal::from($a->muwakkil)->label() : 'not recorded', 'wakil_name' => $a->wakil->wakilProfile?->display_name ?? $a->wakil->name,
                'role_label' => $role?->label() ?? 'Wakil', 'scope' => (string) $a->scope, 'underlying_aqd' => $project->contract_type->label(),
                'authority_list' => collect($a->authority ?? [])->map(fn ($x) => $role?->acts()[$x] ?? $x)->implode('; '),
            ];
            $review = ShariahReview::query()->where('project_id', $project->id)->latest('id')->first();

            return $this->make(K::Wakalah, $project, $contract, $version, $data, $review, $a->wakil_id, null, $by, $a);
        });
    }

    /* ------------------------------------------------------------------ shared machinery */

    /**
     * @param  array<string, mixed>  $data
     */
    public function make(K $kind, Project $project, Contract $contract, ContractTemplateVersion $version, array $data, ?ShariahReview $review, ?int $partyUserId, ?int $amount, ?User $by, ?WakalahAppointment $appointment): ContractDocument
    {
        $scope = ContractDocument::where('contract_id', $contract->id)->where('kind', $kind->value)->where('party_user_id', $partyUserId)->where('wakalah_appointment_id', $appointment?->id);
        $versionNo = 1 + (int) (clone $scope)->max('version_no');
        $reference = 'AQD-'.strtoupper(Str::random(8));
        $data += ['reference' => $reference, 'document_version' => (string) $versionNo, 'template_code' => $version->template->code, 'template_version' => (string) $version->version, 'doc_date' => now()->toDateString()];

        $clauses = $version->clauses()->get()->filter(fn ($c) => $c->condition === null || ! empty($data[$c->condition]));
        $build = function (string $reviewBlock, array $identity = []) use ($clauses, $data) {
            $out = [];
            $n = 0;
            foreach ($clauses as $c) {
                $body = $this->fill($c->body, $identity + $data + ['shariah_review_block' => $reviewBlock]);
                $out[] = $c->code === 'HEADING' ? $c->heading."\n".$body : (++$n).'. '.$c->heading."\n".$body;
            }

            return implode("\n\n", $out);
        };
        $content = $build($this->reviewBlock($review, $version));
        // The terms hash is what a reviewer approves: independent of this copy's reference, version number and date.
        $terms = $build(self::REVIEW_MARK, ['reference' => '[REF]', 'document_version' => '[VER]', 'doc_date' => '[DATE]']);
        $approved = $review?->status === ShariahReviewStatus::Approved;

        // Earlier unexecuted drafts of the same document are cancelled; executed ones are never touched here.
        (clone $scope)->whereIn('status', [S::Draft->value, S::PendingSignature->value])->get()->each(fn ($d) => $d->forceFill(['status' => S::Cancelled])->save());

        $doc = ContractDocument::unguarded(fn () => ContractDocument::create([
            'reference' => $reference, 'kind' => $kind->value, 'project_id' => $project->id, 'contract_id' => $contract->id, 'template_version_id' => $version->id, 'version_no' => $versionNo,
            'party_user_id' => $partyUserId, 'amount' => $amount, 'currency' => 'BDT', 'content' => $content, 'terms_snapshot' => collect($data)->except(['shariah_review_block'])->all(),
            'terms_hash' => hash('sha256', $terms), 'document_hash' => hash('sha256', $content), 'status' => $kind === K::MasterAqd && ! $approved ? S::Draft : S::PendingSignature,
            'shariah_review_id' => $review?->id, 'wakalah_appointment_id' => $appointment?->id, 'generated_by' => $by?->id, 'generated_at' => now(),
        ]));
        $this->audit->record('aqd.generated', $project, null, ['reference' => $reference, 'kind' => $kind->value, 'version' => $versionNo, 'template' => $version->template->code.' v'.$version->version, 'document_hash' => $doc->document_hash]);

        return $doc;
    }

    /** @param array<string, mixed> $data */
    private function fill(string $body, array $data): string
    {
        $out = preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($data) {
            if (! array_key_exists($m[1], $data)) {
                throw new FinancialException("The template needs a value for [{$m[1]}] that the approved terms do not provide.");
            }
            $v = $data[$m[1]];

            return $v === null || $v === '' ? '—' : (string) $v;
        }, $body);

        return $out;
    }

    private function latestReview(Project $project): ?ShariahReview
    {
        return $project->shariahReviews()->latest('id')->first();
    }

    private function reviewBlock(?ShariahReview $r, ContractTemplateVersion $v): string
    {
        $tpl = 'Template '.$v->template->code.' version '.$v->version.' — template Shariah review: '.($v->shariah_review_status === 'APPROVED' ? 'approved by a Shariah reviewer on '.$v->shariah_reviewed_at?->toDateString() : strtolower($v->shariah_review_status)).'.';
        if ($r && $r->status === ShariahReviewStatus::Approved) {
            return 'Shariah Review Status: Approved by Shariah reviewer '.($r->reviewer?->name ?? 'unknown').'. Review date: '.$r->reviewed_at?->toDateString().'. Review version: '.$r->review_version.'. Scope: '.($r->scope ?: 'this structure').'. Conditions / notes: '.($r->conditions ?: ($r->notes ?: 'none recorded')).'. '.$tpl.' This records a reviewer\'s decision; it is not a certification.';
        }

        return 'Shariah Review Status: Pending Shariah review. Reviewer: not yet assigned. '.$tpl.' This agreement is subject to qualified Shariah review.';
    }

    /* ------------------------------------------------------------------ data */

    /** @return array<string, mixed> */
    private function commonData(Project $project, Contract $contract): array
    {
        $b = $project->business;
        $mask = fn (?string $v) => $v ? str_repeat('•', max(0, strlen($v) - 4)).substr($v, -4) : 'not provided';

        return [
            'aqd_title' => $project->contract_type->label(), 'contract_number' => $contract->contract_number, 'project_title' => $project->title, 'project_purpose' => (string) $project->purpose,
            'platform_name' => $this->settings->get('platform.name'), 'platform_role_text' => $this->platformRoleText(), 'business_legal_name' => $b->legal_name ?: $b->name,
            'business_registration' => $mask($b->registration_number), 'business_kyc' => $b->kyc_status->label(), 'representative' => (string) (($contract->aqd_terms['authorized_representative'] ?? null) ?: $b->name),
            'duration_months' => (string) $project->duration_months, 'currency' => 'BDT', 'key_risks' => (string) $project->key_risks,
        ];
    }

    private function platformRoleText(): string
    {
        return match ($this->settings->get('shariah.platform_role')) {
            'WAKIL_ARRANGER' => 'The platform acts as arranger and administrative agent (wakil) for the parties, under the platform role approved by its Shariah board.',
            'MUDARIB' => 'The platform acts as mudarib to the capital providers, under the platform role approved by its Shariah board.',
            'DIRECT_ARRANGER' => 'The contract is made directly between the parties; the platform arranges and administers it, under the platform role approved by its Shariah board.',
            'PRINCIPAL_INVESTOR' => 'The platform participates as principal investor, under the platform role approved by its Shariah board.',
            'OTHER_APPROVED' => 'The platform acts in the role approved and described in its Shariah board resolution on file.',
            default => 'The contractual role of the platform has not yet been approved by its Shariah board; no live funds are accepted until it is.',
        };
    }

    /** @return array<string, mixed> */
    private function termsData(Project $project, Contract $contract): array
    {
        $def = AqdRegistry::for($project->contract_type);
        $terms = $def->fromProject($project);   // identification + typed terms + aqd_terms, exactly as the form shows them
        $label = function (string $key, $value) use ($def) {
            $f = $def->fieldMap()[$key] ?? null;

            return $f && $f['options'] ? ($f['options'][$value] ?? $value) : $value;
        };
        $m = fn (?int $v) => $v === null ? '—' : Money::minor($v)->format();
        $d = $terms + ['security_for_fault' => null, 'maximum_participation' => null];
        $d += ['profit_basis_label' => $label('profit_basis', $terms['profit_basis'] ?? null), 'measurement_label' => $label('measurement_period', $terms['measurement_period'] ?? null),
            'reporting_frequency_label' => $label('reporting_frequency', $terms['reporting_frequency'] ?? null), 'contribution_method_label' => $label('contribution_method', $terms['contribution_method'] ?? null),
            'contribution_evidence' => $terms['business_contribution_evidence'] ?? null, 'maximum_text' => filled($terms['maximum_participation'] ?? null) ? ' and the maximum is BDT '.$terms['maximum_participation'] : '',
            'dispute_resolution' => $terms['dispute_resolution'] ?? 'the mechanism approved for this project', 'use_promise' => (bool) ($terms['use_promise'] ?? false)];

        switch ($project->contract_type) {
            case ContractType::Mudarabah:
                $t = $contract->mudarabah;

                return $d + ['capital_required' => $m($t->capital_required), 'minimum_amount' => $m($project->minimum_amount), 'rabb_ratio' => Percent::format($t->investor_profit_bps), 'mudarib_ratio' => Percent::format($t->business_profit_bps), 'business_plan' => (string) $t->business_plan];
            case ContractType::Musharakah:
                $t = $contract->musharakah;
                $own = app(MusharakahProfitCalculator::class)->ownership(Money::minor($t->investor_contribution), Money::minor($t->business_contribution));

                return $d + ['total_capital' => $m($t->total_capital), 'investor_contribution' => $m($t->investor_contribution), 'business_contribution' => $m($t->business_contribution), 'minimum_amount' => $m($project->minimum_amount),
                    'capital_ratio' => Percent::format($own['investor_ownership_bps']).' / '.Percent::format($own['business_ownership_bps']), 'investor_profit_ratio' => Percent::format($t->investor_profit_bps), 'business_profit_ratio' => Percent::format($t->business_profit_bps),
                    'project_activity' => (string) $t->project_activity, 'business_contribution_date' => (string) ($terms['business_contribution_date'] ?? '—')];
            case ContractType::Murabaha:
                $t = $contract->murabaha;
                $a = $t->assets()->first();
                $cost = Money::minor($t->purchase_cost);

                return $d + ['asset_name' => (string) $a?->name, 'asset_description' => (string) ($a?->description ?: $a?->name), 'quantity' => (string) $a?->quantity, 'unit_cost' => $m($a?->unit_cost), 'supplier' => (string) $a?->supplier_name,
                    'sale_profit' => $m($t->sale_profit), 'installments' => (string) $t->installments_count, 'payment_terms' => (string) $t->payment_terms, 'delivery_terms' => (string) $t->delivery_terms,
                    'ownership_info' => (string) ($terms['ownership_info'] ?? '—'), 'possession_info' => (string) ($terms['possession_info'] ?? '—'), 'qabd_type_label' => $label('qabd_type', $terms['qabd_type'] ?? null),
                    'acquiring_party_label' => $label('acquiring_party', $terms['acquiring_party'] ?? null), 'promise_type_label' => $label('promise_type', $terms['promise_type'] ?? null), 'promisor_label' => $label('promisor', $terms['promisor'] ?? null),
                    'promise_conditions' => $terms['promise_conditions'] ?? '—', 'acquisition_cost' => $cost->format(), 'sale_price' => Money::minor($t->sale_price)->format(), 'risk_bearing_days' => (string) ($terms['risk_bearing_days'] ?? '—')];
        }
    }

    /** True when a contract may have a master agreement generated (a project that is past draft). */
    public function canGenerate(Contract $c): bool
    {
        return $c->aqd_form_version !== null && $c->status !== ContractStatus::Cancelled;
    }
}
