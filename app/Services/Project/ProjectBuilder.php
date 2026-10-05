<?php

namespace App\Services\Project;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\LossAllocationBasis;
use App\Enums\ProjectStatus;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Project;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\MudarabahProfitCalculator;
use App\Services\Finance\MurabahaSaleCalculator;
use App\Services\Finance\MusharakahProfitCalculator;
use App\Support\Money\Money;
use App\Support\Percent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns wizard input into a Project + Contract + contract-specific terms.
 * All financial consistency checks live here (server side), never only in the UI.
 *
 * @phpstan-type Data array<string, mixed>
 */
class ProjectBuilder
{
    public function __construct(
        private MudarabahProfitCalculator $mudarabah,
        private MusharakahProfitCalculator $musharakah,
        private MurabahaSaleCalculator $murabaha,
        private AuditLogger $audit,
        private \App\Services\Settings\SettingsService $settings,
    ) {}

    /** Creates or updates a draft. Only DRAFT / NEEDS_REVISION projects can be edited by the business. */
    public function saveDraft(Business $business, array $d, ?Project $project = null): Project
    {
        $type = ContractType::from($d['contract_type']);

        return DB::transaction(function () use ($business, $d, $project, $type) {
            if ($project) {
                $project = Project::whereKey($project->id)->where('business_id', $business->id)->lockForUpdate()->firstOrFail();
                if (! in_array($project->status, [ProjectStatus::Draft, ProjectStatus::NeedsRevision], true)) {
                    throw new FinancialException('This project cannot be modified in its current status.');
                }
            }

            $terms = $this->terms($type, $d);   // validates the contract-specific numbers
            $attrs = [
                'business_id' => $business->id, 'title' => $d['title'], 'description' => $d['description'], 'industry' => $d['industry'] ?? null,
                'purpose' => $d['purpose'] ?? null, 'contract_type' => $type, 'funding_target' => $terms['funding_target'],
                'minimum_amount' => $this->minimum($type, $d, $terms), 'duration_months' => (int) $d['duration_months'],
                'risk_level' => RiskLevel::from($d['risk_level']), 'key_risks' => $d['key_risks'] ?? null,
                'closing_at' => ! empty($d['closing_at']) ? $d['closing_at'] : null,
            ];

            $project ??= new Project(['slug' => Str::slug($d['title']).'-'.Str::lower(Str::random(5))] + $attrs);
            $project->fill($attrs);
            if (! $project->exists) {
                $project->forceFill(['status' => ProjectStatus::Draft]);
            }
            $project->save();

            $contract = $project->contract ?? new Contract(['contract_number' => Contract::nextNumber($type), 'project_id' => $project->id, 'currency' => 'BDT']);
            $contract->fill(['contract_type' => $type, 'end_date' => now()->addMonths((int) $d['duration_months'])]);
            if (! $contract->exists) {
                $contract->forceFill(['status' => ContractStatus::Draft, 'created_by' => $business->user_id]);
            }
            $contract->save();
            $before = $contract->exists && ($existing = $contract->terms) ? array_intersect_key($existing->getAttributes(), $terms) : null;
            $this->writeTerms($contract, $type, $terms, $d);
            if ($before) {
                $changed = array_filter($terms, fn ($v, $k) => array_key_exists($k, $before) && (string) $before[$k] !== (string) $v, ARRAY_FILTER_USE_BOTH);
                $changed && $this->audit->record('contract.terms_modified', $contract, array_intersect_key($before, $changed), $changed, 'Draft terms edited by business');
            }
            $this->audit->record('project.draft_saved', $project);

            return $project->load('contract');
        });
    }

    /** @return array<string, mixed> normalised, validated contract numbers (money in minor units, ratios in bps) */
    public function terms(ContractType $type, array $d): array
    {
        try {
            return match ($type) {
                ContractType::Mudarabah => $this->mudarabahTerms($d),
                ContractType::Musharakah => $this->musharakahTerms($d),
                ContractType::Murabaha => $this->murabahaTerms($d),
            };
        } catch (\InvalidArgumentException $e) {
            throw new FinancialException($e->getMessage());
        }
    }

    private function mudarabahTerms(array $d): array
    {
        $capital = Money::parse($d['capital_required']);
        $inv = Percent::toBps($d['investor_profit']);
        $biz = Percent::toBps($d['business_profit']);
        $this->mudarabah->assertRatios($inv, $biz);   // investor % + business % must equal 100%
        if (! $capital->isPositive()) {
            throw new FinancialException('Capital required must be greater than zero.');
        }

        return [
            'funding_target' => $capital->minor, 'capital_required' => $capital->minor,
            'business_contribution' => ! empty($d['business_contribution']) ? Money::parse($d['business_contribution'])->minor : 0,
            'investor_profit_bps' => $inv, 'business_profit_bps' => $biz,
            'expected_revenue' => ! empty($d['expected_revenue']) ? Money::parse($d['expected_revenue'])->minor : null,
            'expected_expenses' => ! empty($d['expected_expenses']) ? Money::parse($d['expected_expenses'])->minor : null,
        ];
    }

    private function musharakahTerms(array $d): array
    {
        $investor = Money::parse($d['investor_contribution']);
        $business = Money::parse($d['business_contribution']);
        $total = Money::parse($d['total_capital']);
        if (! $investor->add($business)->equals($total)) {
            throw new FinancialException('Investor and business contributions must add up to the total capital.');
        }
        $own = $this->musharakah->ownership($investor, $business);
        $inv = Percent::toBps($d['investor_profit']);
        $biz = Percent::toBps($d['business_profit']);
        if ($inv + $biz !== Money::BPS) {
            throw new FinancialException('Investor and business profit ratios must total 100%.');
        }
        LossAllocationBasis::from($d['loss_basis']);

        return [
            'funding_target' => $investor->minor, 'total_capital' => $total->minor, 'investor_contribution' => $investor->minor, 'business_contribution' => $business->minor,
            'investor_ownership_bps' => $own['investor_ownership_bps'], 'business_ownership_bps' => $own['business_ownership_bps'],
            'investor_profit_bps' => $inv, 'business_profit_bps' => $biz, 'loss_allocation_basis' => $d['loss_basis'],
        ];
    }

    private function murabahaTerms(array $d): array
    {
        $unit = Money::parse($d['unit_cost']);
        $qty = (int) $d['quantity'];
        $cost = $this->murabaha->purchaseCost($unit, $qty);
        if (! empty($d['purchase_cost']) && Money::parse($d['purchase_cost'])->minor !== $cost->minor) {
            throw new FinancialException('Purchase cost must equal quantity × unit cost.');
        }
        $profit = Money::parse($d['sale_profit']);
        $price = $this->murabaha->salePrice($cost, $profit);
        $n = (int) ($d['installments'] ?? 1);
        if ($n < 1 || $n > 60) {
            throw new FinancialException('Choose between 1 and 60 installments.');
        }

        return ['funding_target' => $cost->minor, 'purchase_cost' => $cost->minor, 'sale_profit' => $profit->minor, 'sale_price' => $price->minor, 'unit_cost' => $unit->minor, 'quantity' => $qty, 'installments_count' => $n];
    }

    private function minimum(ContractType $type, array $d, array $terms): int
    {
        if ($type === ContractType::Murabaha) {
            return $terms['funding_target'];
        }
        $min = Money::parse($d['minimum_amount'] ?? (string) ($this->settings->minor('finance.min_investment') / 100));
        if ($min->minor > $terms['funding_target']) {
            throw new FinancialException('The minimum investment cannot exceed the funding target.');
        }

        return max($min->minor, $this->settings->minor('finance.min_investment'));
    }

    private function writeTerms(Contract $contract, ContractType $type, array $t, array $d): void
    {
        match ($type) {
            ContractType::Mudarabah => $contract->mudarabah()->updateOrCreate([], [
                'capital_required' => $t['capital_required'], 'business_contribution' => $t['business_contribution'],
                'investor_profit_bps' => $t['investor_profit_bps'], 'business_profit_bps' => $t['business_profit_bps'],
                'expected_revenue' => $t['expected_revenue'], 'expected_expenses' => $t['expected_expenses'],
                'business_plan' => $d['business_plan'] ?? null, 'loss_terms' => $d['loss_terms'] ?? null,
            ]),
            ContractType::Musharakah => $contract->musharakah()->updateOrCreate([], [
                'total_capital' => $t['total_capital'], 'investor_contribution' => $t['investor_contribution'], 'business_contribution' => $t['business_contribution'],
                'investor_ownership_bps' => $t['investor_ownership_bps'], 'business_ownership_bps' => $t['business_ownership_bps'],
                'investor_profit_bps' => $t['investor_profit_bps'], 'business_profit_bps' => $t['business_profit_bps'],
                'loss_allocation_basis' => $t['loss_allocation_basis'], 'project_activity' => $d['project_activity'] ?? null, 'financial_assumptions' => $d['financial_assumptions'] ?? null,
            ]),
            ContractType::Murabaha => $this->writeMurabaha($contract, $t, $d),
        };
    }

    /** Delivery plus the ownership/acquisition and possession (qabd) information the buyer supplies. */
    private function deliveryTerms(array $d): ?string
    {
        $parts = array_filter([
            $d['delivery_terms'] ?? null,
            ! empty($d['ownership_info']) ? 'Ownership / acquisition: '.$d['ownership_info'] : null,
            ! empty($d['possession_info']) ? 'Possession (qabd): '.$d['possession_info'] : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }

    private function writeMurabaha(Contract $contract, array $t, array $d): void
    {
        $m = $contract->murabaha()->updateOrCreate([], [
            'purchase_cost' => $t['purchase_cost'], 'sale_profit' => $t['sale_profit'], 'sale_price' => $t['sale_price'],
            'installments_count' => $t['installments_count'], 'delivery_terms' => $this->deliveryTerms($d), 'payment_terms' => $d['payment_terms'] ?? null,
        ]);
        $m->assets()->delete();
        $m->assets()->create(['name' => $d['asset_name'], 'description' => $d['asset_description'] ?? null, 'supplier_name' => $d['supplier'], 'quantity' => $t['quantity'], 'unit_cost' => $t['unit_cost']]);
    }
}
