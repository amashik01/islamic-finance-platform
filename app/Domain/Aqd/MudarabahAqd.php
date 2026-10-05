<?php

namespace App\Domain\Aqd;

use App\Enums\ContractType;
use App\Exceptions\FinancialException;
use App\Support\Percent;

/** Mudarabah: Rabb-ul-Mal (capital provider) and Mudarib (entrepreneur / working partner) share ACTUAL profit; ordinary loss falls on capital. */
final class MudarabahAqd extends AqdDefinition
{
    public function type(): ContractType
    {
        return ContractType::Mudarabah;
    }

    public function version(): string
    {
        return 'MUDARABAH-FORM-1';
    }

    public function persistFromStep(): int
    {
        return 4;
    }

    public function typedKeys(): array
    {
        return ['capital_required', 'minimum_amount', 'investor_profit', 'business_profit', 'expected_revenue', 'expected_expenses', 'business_plan'];
    }

    public function prohibitedKeys(): array
    {
        $why = 'A Mudarabah shares actual profit by an agreed ratio; it cannot promise a return or guarantee capital (rules MUD-PROFIT-RATIO, MUD-LOSS-RABB).';

        return ['fixed_profit' => $why, 'fixed_profit_amount' => $why, 'guaranteed_return' => $why, 'expected_return_percent' => $why, 'profit_on_capital_percent' => $why,
            'capital_guarantee' => $why, 'mudarib_guarantee' => $why, 'mudarib_guarantees_capital' => $why, 'interest_rate' => $why, 'loss_basis' => 'Loss allocation is not a Mudarabah term: ordinary loss falls on the capital provider.'];
    }

    public function steps(): array
    {
        $ratio = ['MUD-PROFIT-RATIO'];

        return [
            ['key' => 'parties', 'title' => 'Parties', 'intro' => 'Who the Rabb-ul-Mal and the Mudarib are. The business is the Mudarib; participating investors are the Rabb-ul-Mal.', 'fields' => array_merge($this->identification(), [
                Field::select('rabb_ul_mal_structure', 'Rabb-ul-Mal (capital provider) structure', ['INVESTORS_COLLECTIVE' => 'Participating investors, collectively (each signs an individual participation agreement)'], ['what' => 'How the capital providers contract with you.', 'islamic' => 'The capital provider is the owner of Ras-ul-Mal and bears ordinary loss. The platform\'s own role is a separate, reviewed decision.', 'rules' => ['MUD-LOSS-RABB', 'GEN-PLATFORM-ROLE'], 'default' => 'INVESTORS_COLLECTIVE']),
                Field::text('authorized_representative', 'Mudarib\'s authorised representative', ['what' => 'The person who signs for the business.', 'valid' => 'A. Rahman, Managing Director', 'max' => 150]),
            ])],
            ['key' => 'activity', 'title' => 'Business activity', 'intro' => 'What the Mudarib will actually do with the capital.', 'fields' => [
                Field::area('business_activity', 'Business activity', ['what' => 'The real trade or service the capital finances.', 'valid' => 'Buying and selling dairy products through two retail outlets.']),
                Field::area('permitted_activities', 'Permitted activities', ['what' => 'What the Mudarib may do with the capital.', 'islamic' => 'Restricted authority is permitted: the Mudarib acts inside the stated limits.', 'rules' => ['MUD-GUARANTEE-FAULT-ONLY']]),
                Field::area('prohibited_activities', 'Prohibited activities', ['what' => 'What the Mudarib must not do (e.g. interest-based dealing, unrelated ventures).', 'valid' => 'No interest-bearing loans; no investment outside dairy trade.']),
                Field::area('business_plan', 'Business plan', ['max' => 5000]),
                Field::money('expected_revenue', 'Expected revenue (BDT, optional)', ['required' => false, 'islamic' => 'An estimate for disclosure only. It is not a promise to participants.']),
                Field::money('expected_expenses', 'Expected expenses (BDT, optional)', ['required' => false]),
            ]],
            ['key' => 'capital', 'title' => 'Ras-ul-Mal (capital)', 'intro' => 'The Mudarabah capital, in BDT.', 'fields' => [
                Field::money('capital_required', 'Required capital — Ras-ul-Mal (BDT)', ['what' => 'The total capital the Rabb-ul-Mal provides.', 'islamic' => 'Capital is delivered to the Mudarib and stays at risk. It is not a loan and not guaranteed.', 'rules' => ['MUD-CAPITAL-DELIVERY'], 'valid' => '100000']),
                Field::money('minimum_amount', 'Minimum participation per investor (BDT)', ['valid' => '5000']),
                Field::money('maximum_participation', 'Maximum participation per investor (BDT, optional)', ['required' => false]),
                Field::area('capital_purpose', 'Capital purpose', ['what' => 'What this capital is for.']),
                Field::area('capital_deployment_conditions', 'Deployment conditions', ['what' => 'When and how the capital is delivered to the Mudarib (after the agreement is executed and the project is fully funded).', 'islamic' => 'Delivery to the Mudarib is a recorded event, separate from funding.', 'rules' => ['MUD-CAPITAL-DELIVERY']]),
            ]],
            ['key' => 'profit', 'title' => 'Profit-sharing', 'intro' => 'How actual profit is shared. This is a share of profit, not a return on capital.', 'fields' => [
                Field::percent('investor_profit', 'Rabb-ul-Mal profit-sharing ratio (%)', ['what' => 'The agreed share of actual distributable profit that goes to the capital provider.', 'islamic' => 'A profit-sharing ratio, not a guaranteed return on capital.', 'valid' => 'Rabb-ul-Mal 70% / Mudarib 30%', 'invalid' => '10% guaranteed annual return on invested capital', 'rules' => $ratio]),
                Field::percent('business_profit', 'Mudarib profit-sharing ratio (%)', ['what' => 'The Mudarib\'s agreed share of actual distributable profit. The two ratios total 100%.', 'islamic' => 'A share of actual profit. A fixed fee or an amount independent of profit is not Mudarabah.', 'rules' => $ratio]),
                Field::select('profit_basis', 'Profit calculation basis', ['ACTUAL_NET_PROFIT' => 'Actual net profit after the agreed business expenses', 'ACTUAL_GROSS_PROFIT' => 'Actual gross profit (policy choice — requires Shariah review)'], ['rules' => ['MUD-PROFIT-RATIO'], 'default' => 'ACTUAL_NET_PROFIT']),
                Field::select('measurement_period', 'Accounting / measurement period', ['FINAL_AT_LIQUIDATION' => 'Final determination at liquidation only', 'PERIODIC_ADVANCES' => 'Periodic interim advances, settled at the final determination'], ['islamic' => 'Interim payments are advances against the final result, not final profit.', 'rules' => ['MUD-ADVANCES'], 'default' => 'FINAL_AT_LIQUIDATION']),
                Field::area('profit_methodology', 'Distributable profit methodology', ['what' => 'How actual profit is measured and verified (books, audit, valuation).', 'islamic' => 'The final result comes from liquidation — actual or by valuation.', 'rules' => ['MUD-ADVANCES']]),
            ]],
            ['key' => 'loss', 'title' => 'Loss & responsibilities', 'intro' => 'Ordinary loss falls on the Rabb-ul-Mal. The Mudarib is liable only for fault.', 'fields' => [
                Field::check('loss_disclosure_ack', 'I understand that ordinary commercial loss is borne by the Rabb-ul-Mal, that the Mudarib does not guarantee the capital, and that no return is promised.', ['islamic' => 'Ordinary loss reduces the capital provider\'s capital. The Mudarib is liable only for misconduct, negligence or breach.', 'rules' => ['MUD-LOSS-RABB', 'MUD-MUDARIB-FAULT']]),
                Field::area('management_responsibilities', 'Mudarib management responsibilities'),
                Field::area('reporting_obligations', 'Reporting obligations', ['what' => 'What the Mudarib reports to participants and how often.']),
                Field::select('reporting_frequency', 'Reporting frequency', ['MONTHLY' => 'Monthly', 'QUARTERLY' => 'Quarterly', 'SEMI_ANNUAL' => 'Every six months'], ['default' => 'QUARTERLY']),
                Field::area('use_of_funds_restrictions', 'Use-of-funds restrictions'),
                Field::area('shariah_compliance_duties', 'Shariah compliance duties of the Mudarib'),
                Field::area('negligence_definition', 'What counts as negligence', ['islamic' => 'Liability exists for established fault, not for ordinary commercial loss.', 'rules' => ['MUD-MUDARIB-FAULT']]),
                Field::area('misconduct_definition', 'What counts as misconduct', ['rules' => ['MUD-MUDARIB-FAULT']]),
                Field::area('breach_conditions', 'What counts as breach of the agreed terms', ['rules' => ['MUD-MUDARIB-FAULT']]),
                Field::area('security_for_fault', 'Security for fault or breach (optional)', ['required' => false, 'what' => 'Collateral or a guarantee that secures damages caused by fault or breach only.', 'islamic' => 'A guarantee against commercial loss is not allowed; one limited to fault or breach is.', 'valid' => 'A third-party guarantee limited to losses caused by the Mudarib\'s negligence or breach.', 'invalid' => 'The Mudarib guarantees the capital.', 'rules' => ['MUD-GUARANTEE-FAULT-ONLY']]),
            ]],
            ['key' => 'termination', 'title' => 'Termination & settlement', 'intro' => 'How the contract ends and the final result is determined.', 'fields' => [
                Field::area('maturity_conditions', 'Maturity / termination conditions'),
                Field::area('early_termination_rules', 'Early termination rules'),
                Field::area('liquidation_valuation', 'Liquidation and valuation', ['islamic' => 'The final profit or loss arises from liquidation — conversion to cash or valuation.', 'rules' => ['MUD-ADVANCES']]),
                Field::area('final_determination', 'Final profit / loss determination'),
                Field::area('settlement_process', 'Settlement process'),
            ]],
            ['key' => 'review', 'title' => 'Shariah review', 'intro' => 'The project is reviewed by a qualified reviewer before it can open for funding. This is software, not a religious authority.', 'fields' => [
                Field::area('shariah_notes', 'Notes for the Shariah reviewer (optional)', ['required' => false]),
                Field::check('review_ack', 'I understand the structure is subject to qualified Shariah review and that approval, if given, applies to this exact structure and version only.', ['rules' => ['GEN-PLATFORM-ROLE']]),
            ]],
            ['key' => 'preview', 'title' => 'Contract preview', 'intro' => 'Check every term. The agreement is generated from these terms; you cannot edit the generated clauses.', 'fields' => []],
        ];
    }

    protected function structural(array $d): void
    {
        if (isset($d['investor_profit'], $d['business_profit']) && filled($d['investor_profit']) && filled($d['business_profit'])) {
            try {
                $i = Percent::toBps((string) $d['investor_profit']);
                $b = Percent::toBps((string) $d['business_profit']);
            } catch (\InvalidArgumentException) {
                throw new FinancialException('Enter the profit-sharing ratios as percentages.');
            }
            if ($i <= 0 || $b <= 0 || $i + $b !== 10000) {
                throw new FinancialException('The profit-sharing ratios must both be positive and total 100% (rule MUD-PROFIT-RATIO).');
            }
        }
        if (filled($d['maximum_participation'] ?? null) && filled($d['capital_required'] ?? null) && (float) $d['maximum_participation'] > (float) $d['capital_required']) {
            throw new FinancialException('The maximum participation cannot exceed the required capital.');
        }
        if (filled($d['security_for_fault'] ?? null) && ! preg_match('/fault|negligen|misconduct|breach|fraud/i', (string) $d['security_for_fault'])) {
            throw new FinancialException('Security may only cover damages from fault or breach, never commercial loss (rule MUD-GUARANTEE-FAULT-ONLY).');
        }
    }

    public function toBuilderInput(array $form): array
    {
        return array_filter([
            'contract_type' => 'MUDARABAH', 'title' => $form['title'] ?? null, 'description' => $form['description'] ?? null, 'industry' => $form['industry'] ?? null, 'purpose' => $form['purpose'] ?? null,
            'duration_months' => $form['duration_months'] ?? null, 'risk_level' => $form['risk_level'] ?? 'MEDIUM', 'key_risks' => $form['key_risks'] ?? null, 'closing_at' => $form['closing_at'] ?? null,
            'capital_required' => $form['capital_required'] ?? null, 'minimum_amount' => $form['minimum_amount'] ?? null, 'investor_profit' => $form['investor_profit'] ?? null, 'business_profit' => $form['business_profit'] ?? null,
            'expected_revenue' => $form['expected_revenue'] ?? null, 'expected_expenses' => $form['expected_expenses'] ?? null, 'business_plan' => $form['business_plan'] ?? null,
            'loss_terms' => trim(($form['negligence_definition'] ?? '')."\n".($form['misconduct_definition'] ?? '')."\n".($form['breach_conditions'] ?? '')) ?: null,
            'aqd_terms' => $this->termsFrom($form),
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function typedFrom(\App\Models\Contract $c, \Closure $m, \Closure $pct): array
    {
        $t = $c->mudarabah;

        return $t ? ['capital_required' => $m($t->capital_required), 'investor_profit' => $pct($t->investor_profit_bps), 'business_profit' => $pct($t->business_profit_bps), 'expected_revenue' => $m($t->expected_revenue), 'expected_expenses' => $m($t->expected_expenses), 'business_plan' => (string) $t->business_plan] : [];
    }
}
