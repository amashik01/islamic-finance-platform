<?php

namespace App\Support;

use App\Enums\ContractType;
use App\Enums\LossAllocationBasis;
use App\Enums\RiskLevel;
use Illuminate\Validation\Rule;

/** Centralised validation rules for the project wizard (shape only; money consistency lives in ProjectBuilder). */
final class ProjectFormRules
{
    private const MONEY = ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];

    private const PCT = ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/'];

    /** @return array<string, mixed> rules keyed by "form.field" */
    public static function forStep(int $step, ?string $type): array
    {
        $r = match ($step) {
            1 => [
                'title' => ['required', 'string', 'max:150'], 'description' => ['required', 'string', 'min:30', 'max:5000'],
                'industry' => ['required', 'string', 'max:80'], 'purpose' => ['required', 'string', 'max:1000'],
                'key_risks' => ['required', 'string', 'max:3000'], 'risk_level' => ['required', Rule::enum(RiskLevel::class)],
                'duration_months' => ['required', 'integer', 'between:1,120'], 'closing_at' => ['nullable', 'date', 'after:today'],
            ],
            2 => ['contract_type' => ['required', Rule::enum(ContractType::class)]],
            3 => match ($type) {
                'MUDARABAH' => ['investor_profit' => self::PCT, 'business_profit' => self::PCT, 'loss_terms' => ['nullable', 'string', 'max:2000'], 'business_plan' => ['required', 'string', 'max:5000']],
                'MUSHARAKAH' => ['investor_profit' => self::PCT, 'business_profit' => self::PCT, 'project_activity' => ['required', 'string', 'max:3000']],
                'MURABAHA' => ['delivery_terms' => ['required', 'string', 'max:2000'], 'payment_terms' => ['required', 'string', 'max:2000'], 'installments' => ['required', 'integer', 'between:1,60'],
                    'ownership_info' => ['required', 'string', 'max:1000'], 'possession_info' => ['required', 'string', 'max:1000']],
                default => [],
            } + ['wakil_id' => ['nullable', 'integer'], 'wakalah_roles' => ['nullable', 'array'], 'wakalah_roles.*' => ['string', 'max:30']],
            4 => match ($type) {
                'MUDARABAH' => ['capital_required' => self::MONEY, 'business_contribution' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'expected_revenue' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'expected_expenses' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'minimum_amount' => self::MONEY],
                'MUSHARAKAH' => ['total_capital' => self::MONEY, 'investor_contribution' => self::MONEY, 'business_contribution' => self::MONEY, 'financial_assumptions' => ['required', 'string', 'max:3000'], 'minimum_amount' => self::MONEY],
                'MURABAHA' => ['asset_name' => ['required', 'string', 'max:150'], 'supplier' => ['required', 'string', 'max:150'], 'quantity' => ['required', 'integer', 'min:1', 'max:100000'], 'unit_cost' => self::MONEY, 'sale_profit' => self::MONEY],
                default => [],
            },
            default => [],
        };

        $out = [];
        foreach ($r as $field => $rules) {
            $out["form.$field"] = $rules;
        }

        return $out;
    }
}
