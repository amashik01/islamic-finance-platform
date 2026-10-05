<?php

namespace App\Domain\Aqd;

use App\Enums\ContractType;
use App\Exceptions\FinancialException;

/**
 * Murabaha: a SALE of an asset the seller has acquired, owns and possessed, at disclosed cost plus disclosed profit.
 * It is not a cash loan, and a promise or a Wakalah never replaces any step of
 * request -> promise -> wakalah -> acquisition -> ownership -> qabd -> risk-bearing -> sale -> receivable -> instalments.
 */
final class MurabahaAqd extends AqdDefinition
{
    public function type(): ContractType
    {
        return ContractType::Murabaha;
    }

    public function version(): string
    {
        return 'MURABAHA-FORM-1';
    }

    public function persistFromStep(): int
    {
        return 6;
    }

    public function typedKeys(): array
    {
        return ['asset_name', 'asset_description', 'quantity', 'unit_cost', 'supplier', 'sale_profit', 'installments', 'delivery_terms', 'payment_terms', 'ownership_info', 'possession_info', 'wakil_id'];
    }

    public function prohibitedKeys(): array
    {
        $loan = 'Murabaha is a sale of an owned asset at disclosed cost plus profit, not a cash loan (rules MUR-POSSESSION-BEFORE-SALE, MUR-NO-RECEIVABLE-BEFORE-SALE).';

        return ['cash_amount' => $loan, 'loan_amount' => $loan, 'cash_disbursement' => $loan, 'interest_rate' => $loan, 'late_fee_rate' => $loan, 'penalty_interest' => $loan, 'receivable_amount' => $loan,
            'guaranteed_return' => $loan, 'capital_guarantee' => $loan];
    }

    public function steps(): array
    {
        $opt = fn () => ['required' => false];

        return [
            ['key' => 'request', 'title' => 'Purchase request', 'intro' => 'The asset the requesting party asks to buy, and from whom it is to be acquired.', 'fields' => array_merge($this->identification(), [
                Field::text('asset_name', 'Asset', ['valid' => 'Commercial refrigeration units', 'max' => 150]),
                Field::area('asset_description', 'Specification', ['what' => 'Model, specification and quality, precisely enough to identify the asset.', 'max' => 2000]),
                Field::number('quantity', 'Quantity', ['valid' => '4']),
                Field::money('unit_cost', 'Expected unit cost from the supplier (BDT)', ['islamic' => 'The acquisition cost is disclosed to the buyer in the sale.', 'rules' => ['MUR-DISCLOSED-COST-PROFIT']]),
                Field::text('supplier', 'Supplier', ['what' => 'The third-party seller the asset is to be bought from.', 'max' => 150]),
                Field::area('requested_purchase_terms', 'Requested purchase terms'),
                Field::area('delivery_requirements', 'Delivery requirements'),
            ])],
            ['key' => 'promise', 'title' => 'Promise (wa\'d)', 'intro' => 'A promise is not the sale. Record one only if it is used.', 'fields' => [
                Field::check('use_promise', 'A promise (wa\'d) is used before the sale', ['required' => false, 'islamic' => 'A promise is morally binding, and legally binding only where conditional and expenses were incurred. It never replaces the sale.', 'rules' => ['MUR-PROMISE']]),
                Field::select('promise_type', 'Type of promise', ['UNILATERAL' => 'Unilateral promise', 'BILATERAL_WITH_OPTION' => 'Mutual promise with an option for one or both parties'], ['when' => ['use_promise' => true], 'islamic' => 'A mutual promise is permissible only if an option is given to one or both parties.', 'invalid' => 'A mutual promise that binds both parties with no option.', 'rules' => ['MUR-PROMISE']]),
                Field::select('promisor', 'Promisor', ['BUSINESS' => 'The business (purchase orderer)', 'SELLER' => 'The seller'], ['when' => ['use_promise' => true]]),
                Field::select('option_holder', 'Who holds the option', ['BUYER' => 'The buyer', 'SELLER' => 'The seller', 'BOTH' => 'Both'], ['when' => ['promise_type' => 'BILATERAL_WITH_OPTION']]),
                Field::area('promise_conditions', 'Conditions of the promise', ['when' => ['use_promise' => true], 'rules' => ['MUR-PROMISE']]),
                Field::check('actual_damages_ack', 'I understand that breach of a binding promise is compensated by actual damages only, never a penalty', ['when' => ['use_promise' => true], 'rules' => ['MUR-PROMISE-BREACH']]),
            ]],
            ['key' => 'wakalah', 'title' => 'Wakalah (optional)', 'intro' => 'Appoint an approved Wakil to act for a principal in a defined role. A selected Wakil is only a proposal until the Wakil accepts and a Shariah reviewer reviews it.', 'fields' => [
                Field::text('wakil_id', 'Appointed Wakil', ['required' => false, 'islamic' => 'Only approved, active Wakils can be appointed. The Muwakkil (principal) is never assumed.', 'rules' => ['WAK-DEFINITION', 'WAK-PRINCIPAL-ROLE']]),
            ]],
            ['key' => 'acquisition', 'title' => 'Acquisition plan', 'intro' => 'How the asset will be bought. The actual purchase, invoice and payment are recorded later as events.', 'fields' => [
                Field::select('acquiring_party', 'Who acquires the asset', ['SELLER' => 'The seller acquires and owns the asset before selling it'], ['default' => 'SELLER', 'islamic' => 'The seller buys from the supplier in its own name and owns the asset before the sale.', 'rules' => ['MUR-POSSESSION-BEFORE-SALE']]),
                Field::area('payment_evidence_plan', 'Supplier payment and evidence', ['islamic' => 'Payment goes to the supplier directly or through the agent\'s transaction account; handing cash to an agent needs explicit Shariah approval.', 'rules' => ['MUR-SUPPLIER-PAYMENT']]),
                Field::area('title_evidence_plan', 'Title / ownership evidence', ['rules' => ['MUR-POSSESSION-BEFORE-SALE']]),
            ]],
            ['key' => 'ownership', 'title' => 'Ownership & qabd', 'intro' => 'Possession (qabd) and the seller\'s risk before the sale.', 'fields' => [
                Field::area('ownership_info', 'Ownership of the asset before the sale', ['valid' => 'Bought by the seller in its own name and held in its warehouse.', 'rules' => ['MUR-POSSESSION-BEFORE-SALE']]),
                Field::select('qabd_type', 'Possession (qabd)', ['ACTUAL' => 'Actual (physical) possession', 'CONSTRUCTIVE' => 'Constructive possession (requires Shariah review)'], ['islamic' => 'The goods must be in the seller\'s possession before the sale; whether constructive possession is enough for a given asset is a question for the Shariah reviewer.', 'valid' => 'Actual: the goods are in the seller\'s warehouse before the sale.', 'invalid' => 'Selling goods the seller has only ordered and not taken possession of.', 'rules' => ['MUR-POSSESSION-BEFORE-SALE']]),
                Field::area('possession_info', 'How possession will be taken', ['rules' => ['MUR-POSSESSION-BEFORE-SALE']]),
                Field::number('risk_bearing_days', 'Seller risk-bearing period (days) before the sale', ['islamic' => 'The seller bears the risk of loss before delivery. The minimum period is a policy choice pending scholar review.', 'rules' => ['MUR-POSSESSION-BEFORE-SALE'], 'valid' => '3']),
            ]],
            ['key' => 'sale', 'title' => 'Murabaha sale terms', 'intro' => 'The sale happens only after ownership, qabd and the risk-bearing period. Cost and profit are disclosed.', 'fields' => [
                Field::money('sale_profit', 'Disclosed sale profit (BDT)', ['islamic' => 'A disclosed profit on the asset — not interest and not a rate on a loan.', 'rules' => ['MUR-DISCLOSED-COST-PROFIT'], 'valid' => '10000', 'invalid' => '12% annual rate on the amount advanced']),
                Field::number('installments', 'Number of instalments', ['valid' => '4']),
                Field::area('payment_terms', 'Payment schedule', ['islamic' => 'Instalments are a deferred price for a completed sale; there is no late-payment interest.']),
                Field::area('delivery_terms', 'Delivery terms'),
                Field::check('customer_acceptance_ack', 'The buyer will accept the asset and the disclosed price in the sale agreement', ['rules' => ['MUR-DISCLOSED-COST-PROFIT']]),
            ]],
            ['key' => 'review', 'title' => 'Shariah review', 'intro' => 'The structure is reviewed by a qualified reviewer. This is software, not a religious authority.', 'fields' => [
                Field::area('shariah_notes', 'Notes for the Shariah reviewer (optional)', $opt()),
                Field::check('review_ack', 'I understand the sale cannot be executed until the asset is acquired, owned and in possession, and that the structure is subject to qualified Shariah review.'),
            ]],
            ['key' => 'preview', 'title' => 'Contract preview', 'intro' => 'Check every term. The sale agreement is generated after possession, from these terms and the recorded events.', 'fields' => []],
        ];
    }

    protected function structural(array $d): void
    {
        if (filter_var($d['use_promise'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            if (($d['promise_type'] ?? null) === 'BILATERAL_WITH_OPTION' && blank($d['option_holder'] ?? null)) {
                throw new FinancialException('A mutual promise needs an option for one or both parties; without it the promise is not permissible (rule MUR-PROMISE).');
            }
            if (($d['promise_type'] ?? null) === 'BILATERAL') {
                throw new FinancialException('A mutual promise without an option is not supported (rule MUR-PROMISE).');
            }
        }
        if (filled($d['wakil_id'] ?? null) && ! filled($d['muwakkil'] ?? null)) {
            throw new FinancialException('Choose the Muwakkil (principal) of the Wakalah. It is never assumed (rule WAK-PRINCIPAL-ROLE).');
        }
    }

    public function toBuilderInput(array $form): array
    {
        $wakalah = array_filter(['wakil_id' => $form['wakil_id'] ?? null, 'wakalah_roles' => $form['wakalah_roles'] ?? null, 'muwakkil' => $form['muwakkil'] ?? null, 'wakalah_scope' => $form['wakalah_scope'] ?? null, 'wakalah_authority' => $form['wakalah_authority'] ?? null], fn ($v) => $v !== null);

        return array_filter([
            'contract_type' => 'MURABAHA', 'title' => $form['title'] ?? null, 'description' => $form['description'] ?? null, 'industry' => $form['industry'] ?? null, 'purpose' => $form['purpose'] ?? null,
            'duration_months' => $form['duration_months'] ?? null, 'risk_level' => $form['risk_level'] ?? 'MEDIUM', 'key_risks' => $form['key_risks'] ?? null, 'closing_at' => $form['closing_at'] ?? null,
            'asset_name' => $form['asset_name'] ?? null, 'asset_description' => $form['asset_description'] ?? null, 'supplier' => $form['supplier'] ?? null, 'quantity' => $form['quantity'] ?? null,
            'unit_cost' => $form['unit_cost'] ?? null, 'sale_profit' => $form['sale_profit'] ?? null, 'installments' => $form['installments'] ?? null,
            'delivery_terms' => $form['delivery_terms'] ?? null, 'payment_terms' => $form['payment_terms'] ?? null, 'ownership_info' => $form['ownership_info'] ?? null, 'possession_info' => $form['possession_info'] ?? null,
            'aqd_terms' => $this->termsFrom($form),
        ] + $wakalah, fn ($v) => $v !== null && $v !== '');
    }

    protected function typedFrom(\App\Models\Contract $c, \Closure $m, \Closure $pct): array
    {
        $t = $c->murabaha;
        $a = $t?->assets()->first();
        if (! $t) {
            return [];
        }
        // Ownership and possession information were stored inside delivery_terms by the builder; show the delivery part only.
        $delivery = collect(preg_split("/\r?\n/", (string) $t->delivery_terms))->reject(fn ($l) => str_starts_with($l, 'Ownership / acquisition:') || str_starts_with($l, 'Possession (qabd):'))->implode("\n");

        $line = fn (string $prefix) => collect(preg_split("/\r?\n/", (string) $t->delivery_terms))->first(fn ($l) => str_starts_with($l, $prefix));

        return ['ownership_info' => trim(substr((string) $line('Ownership / acquisition:'), 24)), 'possession_info' => trim(substr((string) $line('Possession (qabd):'), 18)),
            'asset_name' => (string) $a?->name, 'asset_description' => (string) $a?->description, 'supplier' => (string) $a?->supplier_name, 'quantity' => (string) ($a?->quantity ?? ''), 'unit_cost' => $m($a?->unit_cost),
            'sale_profit' => $m($t->sale_profit), 'installments' => (string) $t->installments_count, 'delivery_terms' => $delivery, 'payment_terms' => (string) $t->payment_terms];
    }
}
