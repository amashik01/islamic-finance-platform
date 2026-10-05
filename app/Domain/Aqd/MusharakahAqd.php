<?php

namespace App\Domain\Aqd;

use App\Enums\ContractType;
use App\Exceptions\FinancialException;
use App\Support\Percent;

/** Musharakah: partners contribute capital, share actual profit by agreed ratio, and bear loss by capital ratio. */
final class MusharakahAqd extends AqdDefinition
{
    public function type(): ContractType
    {
        return ContractType::Musharakah;
    }

    public function version(): string
    {
        return 'MUSHARAKAH-FORM-1';
    }

    public function persistFromStep(): int
    {
        return 4;
    }

    public function typedKeys(): array
    {
        return ['total_capital', 'investor_contribution', 'business_contribution', 'investor_profit', 'business_profit', 'minimum_amount', 'project_activity', 'financial_assumptions'];
    }

    public function prohibitedKeys(): array
    {
        $loss = 'Musharakah loss follows each partner\'s capital contribution; a different loss ratio cannot be agreed (rule MUS-LOSS-CAPITAL).';
        $guar = 'A Musharakah guarantees neither capital nor profit (rules MUS-NO-CAPITAL-GUARANTEE, MUS-NO-PROFIT-GUARANTEE).';

        return ['loss_ratio' => $loss, 'investor_loss_percent' => $loss, 'business_loss_percent' => $loss, 'investor_loss_bps' => $loss, 'loss_allocation_basis' => $loss, 'loss_exception_reason' => $loss,
            'guaranteed_return' => $guar, 'capital_guarantee' => $guar, 'fixed_profit' => $guar, 'buyback_price' => 'No buy-back at face or pre-agreed value (rule MUS-NO-FACE-VALUE-BUYBACK).', 'interest_rate' => $guar];
    }

    public function steps(): array
    {
        return [
            ['key' => 'partners', 'title' => 'Partners', 'intro' => 'The Musharik (partners): the participating investors on one side and the business on the other.', 'fields' => array_merge($this->identification(), [
                Field::select('investor_partner_structure', 'Investor-side partner structure', ['INVESTORS_COLLECTIVE' => 'Participating investors, collectively (each signs an individual participation agreement)'], ['default' => 'INVESTORS_COLLECTIVE', 'rules' => ['GEN-PLATFORM-ROLE'], 'islamic' => 'Each partner\'s capital is an ownership interest in the partnership, not a loan to the other partner.']),
                Field::text('authorized_representative', 'Business partner\'s authorised representative', ['max' => 150]),
            ])],
            ['key' => 'activity', 'title' => 'Partnership activity', 'intro' => 'What the partnership does.', 'fields' => [
                Field::area('project_activity', 'Partnership activity', ['what' => 'The business the partners carry on together.']),
                Field::area('permitted_activities', 'Permitted activities'),
                Field::area('prohibited_activities', 'Prohibited activities', ['valid' => 'No interest-bearing dealings; no activity outside the stated trade.']),
                Field::area('financial_assumptions', 'Financial assumptions', ['islamic' => 'Disclosure only — never a promised profit or return.']),
            ]],
            ['key' => 'capital', 'title' => 'Capital contributions', 'intro' => 'Each partner\'s capital contribution, in BDT.', 'fields' => [
                Field::money('total_capital', 'Total Musharakah capital (BDT)', ['islamic' => 'The sum of all partners\' contributions.', 'rules' => ['MUS-CAPITAL-TIMING']]),
                Field::money('investor_contribution', 'Investor-side capital contribution (BDT)', ['islamic' => 'Each partner\'s capital is an ownership interest in the partnership, at risk, not a loan to the other partner.', 'rules' => ['MUS-CAPITAL-TIMING', 'MUS-NO-CAPITAL-GUARANTEE'], 'valid' => '700000']),
                Field::money('business_contribution', 'Business partner capital contribution (BDT)', ['rules' => ['MUS-CAPITAL-TIMING'], 'islamic' => 'Paid before operations start and recorded with its date and evidence.']),
                Field::select('contribution_method', 'Contribution method', ['CASH' => 'Cash (BDT)'], ['default' => 'CASH', 'help' => 'In-kind contributions are not supported yet.']),
                Field::date('business_contribution_date', 'Business contribution date', ['what' => 'When the business partner pays its contribution.']),
                Field::text('business_contribution_evidence', 'Contribution evidence reference', ['what' => 'A bank or receipt reference you will supply.', 'required' => false]),
                Field::money('minimum_amount', 'Minimum participation per investor (BDT)', ['valid' => '5000']),
            ]],
            ['key' => 'profit', 'title' => 'Profit-sharing', 'intro' => 'Profit is shared by an agreed ratio of ACTUAL profit. It need not equal the capital ratio.', 'fields' => [
                Field::percent('investor_profit', 'Investor-side profit-sharing ratio (%)', ['islamic' => 'May differ from the capital ratio. A share of actual profit, never a guaranteed return.', 'valid' => '60%', 'invalid' => '10% guaranteed return on capital', 'rules' => ['MUS-PROFIT-RATIO', 'MUS-NO-PROFIT-GUARANTEE']]),
                Field::percent('business_profit', 'Business partner profit-sharing ratio (%)', ['rules' => ['MUS-PROFIT-RATIO']]),
                Field::select('profit_basis', 'Profit calculation basis', ['ACTUAL_NET_PROFIT' => 'Actual net profit after agreed expenses'], ['default' => 'ACTUAL_NET_PROFIT']),
                Field::select('measurement_period', 'Accounting / measurement period', ['FINAL_AT_LIQUIDATION' => 'Final determination at liquidation only', 'PERIODIC_ADVANCES' => 'Periodic interim advances, settled at the final determination'], ['islamic' => 'Interim payments are advances, settled at the final calculation.', 'rules' => ['MUS-ADVANCES'], 'default' => 'FINAL_AT_LIQUIDATION']),
                Field::area('profit_methodology', 'Distributable profit methodology', ['rules' => ['MUS-ADVANCES']]),
            ]],
            ['key' => 'loss', 'title' => 'Loss sharing', 'intro' => 'Ordinary loss follows the capital contribution ratio. This is fixed by the system and cannot be changed.', 'fields' => [
                Field::check('loss_ack', 'I understand that each partner bears loss in proportion to its capital contribution, that no partner guarantees another partner\'s capital, and that the system enforces this.', ['islamic' => 'Loss follows capital; the contrary cannot be agreed.', 'rules' => ['MUS-LOSS-CAPITAL', 'MUS-NO-CAPITAL-GUARANTEE']]),
            ]],
            ['key' => 'governance', 'title' => 'Governance', 'intro' => 'Management, authority and exit.', 'fields' => [
                Field::area('management_rights', 'Management rights'),
                Field::area('partner_authority', 'Partner authority and limits'),
                Field::area('withdrawal_restrictions', 'Withdrawal restrictions'),
                Field::area('asset_ownership', 'Ownership of partnership assets'),
                Field::area('distribution_rules', 'Distribution rules'),
                Field::area('exit_rules', 'Exit'),
                Field::area('dissolution_rules', 'Dissolution and liquidation', ['islamic' => 'The final result arises from liquidation — actual or by valuation.', 'rules' => ['MUS-ADVANCES']]),
                Field::area('breach_conditions', 'Breach', ['islamic' => 'A manager is liable for fault or breach only, not for ordinary loss.', 'rules' => ['MUS-MANAGER-FAULT']]),
                Field::area('dispute_resolution', 'Dispute resolution'),
            ]],
            ['key' => 'review', 'title' => 'Shariah review', 'intro' => 'The project is reviewed by a qualified reviewer before it can open for funding. This is software, not a religious authority.', 'fields' => [
                Field::area('shariah_notes', 'Notes for the Shariah reviewer (optional)', ['required' => false]),
                Field::check('review_ack', 'I understand the structure is subject to qualified Shariah review and that approval, if given, applies to this exact structure and version only.'),
            ]],
            ['key' => 'preview', 'title' => 'Contract preview', 'intro' => 'Check every term. The agreement is generated from these terms; you cannot edit the generated clauses.', 'fields' => []],
        ];
    }

    protected function structural(array $d): void
    {
        if (isset($d['loss_basis']) && filled($d['loss_basis']) && $d['loss_basis'] !== 'CAPITAL_RATIO') {
            throw new FinancialException('Musharakah loss follows capital contribution; a different loss basis is not supported (rule MUS-LOSS-CAPITAL).');
        }
        foreach (['investor_profit', 'business_profit'] as $k) {
            if (filled($d[$k] ?? null)) {
                try {
                    Percent::toBps((string) $d[$k]);
                } catch (\InvalidArgumentException) {
                    throw new FinancialException('Enter the profit-sharing ratios as percentages.');
                }
            }
        }
        if (filled($d['investor_profit'] ?? null) && filled($d['business_profit'] ?? null)) {
            $i = Percent::toBps((string) $d['investor_profit']);
            $b = Percent::toBps((string) $d['business_profit']);
            if ($i <= 0 || $b <= 0 || $i + $b !== 10000) {
                throw new FinancialException('Each partner needs a positive profit share and the shares must total 100% (rules MUS-PROFIT-RATIO, MUS-NO-PROFIT-GUARANTEE).');
            }
        }
    }

    public function toBuilderInput(array $form): array
    {
        return array_filter([
            'contract_type' => 'MUSHARAKAH', 'title' => $form['title'] ?? null, 'description' => $form['description'] ?? null, 'industry' => $form['industry'] ?? null, 'purpose' => $form['purpose'] ?? null,
            'duration_months' => $form['duration_months'] ?? null, 'risk_level' => $form['risk_level'] ?? 'MEDIUM', 'key_risks' => $form['key_risks'] ?? null, 'closing_at' => $form['closing_at'] ?? null,
            'total_capital' => $form['total_capital'] ?? null, 'investor_contribution' => $form['investor_contribution'] ?? null, 'business_contribution' => $form['business_contribution'] ?? null,
            'investor_profit' => $form['investor_profit'] ?? null, 'business_profit' => $form['business_profit'] ?? null, 'minimum_amount' => $form['minimum_amount'] ?? null,
            'project_activity' => $form['project_activity'] ?? null, 'financial_assumptions' => $form['financial_assumptions'] ?? null, 'loss_basis' => 'CAPITAL_RATIO',
            'aqd_terms' => $this->termsFrom($form),
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function typedFrom(\App\Models\Contract $c, \Closure $m, \Closure $pct): array
    {
        $t = $c->musharakah;

        return $t ? ['total_capital' => $m($t->total_capital), 'investor_contribution' => $m($t->investor_contribution), 'business_contribution' => $m($t->business_contribution), 'investor_profit' => $pct($t->investor_profit_bps), 'business_profit' => $pct($t->business_profit_bps), 'project_activity' => (string) $t->project_activity, 'financial_assumptions' => (string) $t->financial_assumptions] : [];
    }
}
