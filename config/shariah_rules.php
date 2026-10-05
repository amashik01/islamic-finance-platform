<?php

/*
 * SHARIAH RULE REGISTRY — source data. Synced into the shariah_rules table by ShariahRuleRegistry::sync().
 *
 * HONESTY CONTRACT
 *  - "rule_text" is a PARAPHRASE of what the cited source says, never a quotation.
 *  - verification:
 *      TEXT_READ     the cited clause/article was read in the source text during this work (see docs/shariah/SOURCES.md)
 *      VIA_SUMMARY   obtained through a summarising fetch of the official page; clause numbers need confirmation
 *      SECONDARY     the clause is cited by a regulator's compendium (it amends or footnotes it) but the base text was not read
 *      UNVERIFIED    SOURCE VERIFICATION REQUIRED — nothing is asserted about the clause content
 *  - classification: MANDATORY | PROHIBITED | PERMISSIBLE | RECOMMENDED | DISPUTED | POLICY_CHOICE | REQUIRES_SCHOLAR_REVIEW
 *  - Every rule starts UNDER_REVIEW. Software never marks a rule APPROVED; only a recorded human review can.
 *  - Nothing here is a fatwa, a certification, or legal advice.
 */
$tkbb5 = ['source_type' => 'STANDARD', 'source_name' => 'TKBB Participation Finance Standards No. 5 — Mudarabah Standard', 'standard_code' => 'TKBB-PFS-5', 'source_url' => 'https://tkbb.org.tr/upload/Mudarabah%20Standard_ENG.pdf', 'verification' => 'TEXT_READ'];
$tkbb7 = ['source_type' => 'STANDARD', 'source_name' => 'TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025)', 'standard_code' => 'TKBB-PFS-7', 'source_url' => 'https://ar.tkbb.org.tr/upload/4630513561-ingilizce-musareke.pdf', 'verification' => 'TEXT_READ'];
$iifa30 = ['source_type' => 'RESOLUTION', 'source_name' => 'International Islamic Fiqh Academy — Resolution 30 (5/4) Muqaradah and Investment Certificates', 'standard_code' => 'IIFA-30(5/4)', 'source_url' => 'https://iifa-aifi.org/en/32300.html', 'verification' => 'VIA_SUMMARY'];
$iifa40 = ['source_type' => 'RESOLUTION', 'source_name' => 'International Islamic Fiqh Academy — Resolutions 40-41 (2/5, 3/5) Keeping a Promise and Murabahah to the Purchase Orderer', 'standard_code' => 'IIFA-40-41(2-3/5)', 'source_url' => 'https://iifa-aifi.org/en/32332.html', 'verification' => 'VIA_SUMMARY'];
$sbp = ['source_type' => 'REGULATOR_COMPENDIUM', 'source_name' => 'State Bank of Pakistan — Compendium of Shariah Standards (updated 31 Jul 2025), citing AAOIFI clauses (Pakistan jurisdiction; not Bangladesh)', 'source_url' => 'https://www.sbp.org.pk/assets/document/publications/Compendium.pdf', 'verification' => 'SECONDARY'];
$none = ['source_type' => 'PLATFORM_POLICY', 'source_name' => 'Platform policy — no external source; requires governance decision', 'standard_code' => null, 'clause_reference' => null, 'source_url' => null, 'verification' => 'UNVERIFIED'];

return [
    // ------------------------------------------------------------------ MUDARABAH
    ['code' => 'MUD-PROFIT-RATIO', 'aqd_type' => 'MUDARABAH', 'title' => 'Profit is a share of actual profit',
        'rule_text' => 'Profit is defined at contracting as an agreed proportion of the profit that is actually earned. It cannot be a fixed amount or a proportion of the capital.',
        'guidance_text' => 'Enter the Rabb-ul-Mal and Mudarib shares of actual distributable profit (they total 100%). Never a promised return.',
        'classification' => 'PROHIBITED', 'clause_reference' => '§2.4.1', 'system_effect' => 'Rejects fixed-amount and %-of-capital profit; requires two ratios totalling 100%.', 'scholar' => 'PENDING'] + $tkbb5,
    ['code' => 'MUD-LOSS-RABB', 'aqd_type' => 'MUDARABAH', 'title' => 'Ordinary loss falls on the capital provider',
        'rule_text' => 'In the event of loss the capital provider bears it; the Mudarib, absent breach of contract or fault, cannot be held accountable and an agreement to the contrary is not permissible.',
        'guidance_text' => 'Do not ask the Mudarib to guarantee capital or ordinary loss. Liability exists only for fault, breach, misconduct or negligence.',
        'classification' => 'MANDATORY', 'clause_reference' => '§2.2.4, §2.4.6', 'system_effect' => 'Rejects Mudarib capital-guarantee clauses; ordinary loss reduces capital with no Mudarib debt.', 'scholar' => 'PENDING'] + $tkbb5,
    ['code' => 'MUD-MUDARIB-FAULT', 'aqd_type' => 'MUDARABAH', 'title' => 'Mudarib at fault compensates and forfeits remuneration',
        'rule_text' => 'A Mudarib with a breach of contract or fault compensates the loss and is not entitled to remuneration for the effort.',
        'guidance_text' => 'Define negligence, misconduct and breach in the agreement. A recovery claim needs an established finding, not an assertion.',
        'classification' => 'MANDATORY', 'clause_reference' => '§2.4.6', 'system_effect' => 'Manager recovery follows a staged lifecycle (suspected → fault established → liability recognised → recovery).', 'scholar' => 'PENDING'] + $tkbb5,
    ['code' => 'MUD-CAPITAL-DELIVERY', 'aqd_type' => 'MUDARABAH', 'title' => 'Capital is delivered to the Mudarib',
        'rule_text' => 'For the contract to be valid and enforceable the agreed capital, or enough of it to operate, is delivered to the entrepreneur (granting authority to manage it also counts as delivery).',
        'guidance_text' => 'Record when and how the Mudarabah capital is made available.',
        'classification' => 'MANDATORY', 'clause_reference' => '§2.2.2', 'system_effect' => 'Capital deployment is an explicit, recorded event; no deployment without an executed agreement.', 'scholar' => 'PENDING'] + $tkbb5,
    ['code' => 'MUD-ADVANCES', 'aqd_type' => 'MUDARABAH', 'title' => 'Interim payments are advances; final result on liquidation',
        'rule_text' => 'Interim payments to the parties may be made as advances and are settled at the final calculation. The final profit or loss arises from liquidation (conversion to cash or valuation).',
        'guidance_text' => 'Interim business remittances are not final profit until the result is determined.',
        'classification' => 'MANDATORY', 'clause_reference' => '§2.4.5, §2.6.4', 'system_effect' => 'Interim remittances are recorded as interim proceeds held pending final determination.', 'scholar' => 'PENDING'] + $tkbb5,
    ['code' => 'MUD-GUARANTEE-FAULT-ONLY', 'aqd_type' => 'MUDARABAH', 'title' => 'Collateral only against fault or breach',
        'rule_text' => 'The capital provider may take a guarantee or collateral from the entrepreneur or a third party for damages arising from fault or breach of contract.',
        'guidance_text' => 'A security clause must be limited to fault/breach, never to commercial loss.',
        'classification' => 'PERMISSIBLE', 'clause_reference' => '§2.5.1, §2.5.2', 'system_effect' => 'Security terms, if any, are stored as fault-only.', 'scholar' => 'PENDING'] + $tkbb5,
    ['code' => 'MUD-VOLUNTARY-LOSS', 'aqd_type' => 'MUDARABAH', 'title' => 'Voluntary assumption of loss is not a stipulable term',
        'rule_text' => 'The entrepreneur may assume loss unilaterally only without it being a pre-condition, a promise or an established custom.',
        'guidance_text' => 'The platform does not offer loss-assumption as a contract term.',
        'classification' => 'PROHIBITED', 'clause_reference' => '§2.4.7', 'system_effect' => 'No field exists for the Mudarib to assume ordinary loss.', 'scholar' => 'PENDING'] + $tkbb5,
    ['code' => 'MUD-NO-CAPITAL-PROFIT-GUARANTEE', 'aqd_type' => 'MUDARABAH', 'title' => 'No guarantee of capital or fixed profit by the manager',
        'rule_text' => 'The offering must not contain a guarantee, from the fund manager, of the capital or of a fixed profit; if one appears, the guarantee condition is voided. A voluntary, independent third-party promise is treated separately.',
        'guidance_text' => 'Never display or promise "guaranteed principal" or "fixed return".',
        'classification' => 'PROHIBITED', 'clause_reference' => 'cl. 4, cl. 8 (as reported — confirm numbering)', 'system_effect' => 'Blocks guarantee language in terms; UI never says guaranteed.', 'scholar' => 'PENDING'] + $iifa30,
    ['code' => 'MUD-DISTRIBUTION-SS40', 'aqd_type' => 'MUDARABAH', 'title' => 'Profit distribution in Mudarabah-based investment accounts',
        'rule_text' => 'AAOIFI Shari\'ah Standard 40 addresses profit distribution in Mudarabah-based investment accounts. Its clauses were NOT read.',
        'guidance_text' => 'Source verification required before relying on it.',
        'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'source_type' => 'STANDARD', 'source_name' => 'AAOIFI Shari\'ah Standard No. 40', 'standard_code' => 'AAOIFI-SS40', 'clause_reference' => null, 'source_url' => null, 'verification' => 'UNVERIFIED', 'system_effect' => 'None asserted.', 'scholar' => 'REQUIRED'],

    // ------------------------------------------------------------------ MUSHARAKAH
    ['code' => 'MUS-LOSS-CAPITAL', 'aqd_type' => 'MUSHARAKAH', 'title' => 'Loss follows capital share',
        'rule_text' => 'Partners bear loss in proportion to their capital shares; the contrary cannot be agreed, and loss ratios cannot differ from the participation shares.',
        'guidance_text' => 'The platform enforces capital-ratio loss. There is no option to agree a different loss ratio.',
        'classification' => 'MANDATORY', 'clause_reference' => 'Art. 12, Art. 19', 'system_effect' => 'Ordinary loss is always allocated by capital ratio; the agreed-loss exception is frozen for new contracts.', 'scholar' => 'PENDING'] + $tkbb7,
    ['code' => 'MUS-PROFIT-RATIO', 'aqd_type' => 'MUSHARAKAH', 'title' => 'Profit ratio may differ from capital ratio',
        'rule_text' => 'The profit-sharing ratio is fixed by agreement at establishment and may be proportional to capital shares or a different ratio; it can be rearranged by mutual consent while the partnership is in effect.',
        'guidance_text' => 'Enter agreed shares of actual profit (they total 100%); they need not equal capital shares.',
        'classification' => 'PERMISSIBLE', 'clause_reference' => 'Art. 15, Art. 16', 'system_effect' => 'Profit ratio is an independent field; never derived from capital ratio.', 'scholar' => 'PENDING'] + $tkbb7,
    ['code' => 'MUS-NO-CAPITAL-GUARANTEE', 'aqd_type' => 'MUSHARAKAH', 'title' => 'Capital is at risk and cannot be guaranteed by a partner or manager',
        'rule_text' => 'Risk on capital belongs to all partners; capital cannot be guaranteed against loss by any partner or the manager, nor by the partnership.',
        'guidance_text' => 'Partner capital is an ownership interest in the partnership, not a loan to the other partner.',
        'classification' => 'PROHIBITED', 'clause_reference' => 'Art. 14, Art. 25', 'system_effect' => 'Business capital is partnership capital; no business receivable arises from ordinary loss.', 'scholar' => 'PENDING'] + $tkbb7,
    ['code' => 'MUS-NO-PROFIT-GUARANTEE', 'aqd_type' => 'MUSHARAKAH', 'title' => 'No profit guarantee',
        'rule_text' => 'No profit guarantee may be given in favour of a partner and no partner may be deprived of a profit share.',
        'guidance_text' => 'Never show an expected return as a promise.', 'classification' => 'PROHIBITED', 'clause_reference' => 'Art. 17', 'system_effect' => 'Blocks fixed-return terms; every partner has a positive profit share.', 'scholar' => 'PENDING'] + $tkbb7,
    ['code' => 'MUS-MANAGER-FAULT', 'aqd_type' => 'MUSHARAKAH', 'title' => 'Manager liable only for fault or breach',
        'rule_text' => 'The managing partner or manager is liable to compensate damage caused by conduct contrary to the contract or by fault; a condition making the manager liable without breach or fault is invalid.',
        'guidance_text' => 'Define fault and breach; do not make the manager liable for ordinary loss.',
        'classification' => 'MANDATORY', 'clause_reference' => 'Art. 20', 'system_effect' => 'No ordinary-loss liability for the managing partner.', 'scholar' => 'PENDING'] + $tkbb7,
    ['code' => 'MUS-CAPITAL-TIMING', 'aqd_type' => 'MUSHARAKAH', 'title' => 'Agreed shares are paid before operations start',
        'rule_text' => 'Partners pay the agreed participation shares in accordance with the agreement before the company starts its operations.',
        'guidance_text' => 'Record each partner\'s contribution and date before activation.',
        'classification' => 'MANDATORY', 'clause_reference' => 'Art. 11', 'system_effect' => 'Activation requires the business contribution to be received and recorded.', 'scholar' => 'PENDING'] + $tkbb7,
    ['code' => 'MUS-ADVANCES', 'aqd_type' => 'MUSHARAKAH', 'title' => 'Interim payments are advances',
        'rule_text' => 'Payments to partners may be agreed as advances; the final calculation is made on liquidation, and an advance is treated akin to a qard deducted from profit or capital as the result requires.',
        'guidance_text' => 'Interim remittances are not final profit.', 'classification' => 'MANDATORY', 'clause_reference' => 'Art. 21 and its justification', 'system_effect' => 'Same interim-proceeds treatment as Mudarabah.', 'scholar' => 'PENDING'] + $tkbb7,
    ['code' => 'MUS-NO-FACE-VALUE-BUYBACK', 'aqd_type' => 'MUSHARAKAH', 'title' => 'No promise to buy partnership assets at face or pre-agreed value',
        'rule_text' => 'Being Sharikat ul Aqd, it is not permissible to promise to buy the assets of the Sharika at face value or a pre-agreed value (regulator footnote to AAOIFI SS12 clause 3/1/6/2).',
        'guidance_text' => 'No buy-back that returns capital at a fixed price.', 'classification' => 'PROHIBITED', 'standard_code' => 'AAOIFI-SS12', 'clause_reference' => '3/1/6/2 (SBP footnote)', 'system_effect' => 'No buy-back or capital-return promise field exists.', 'scholar' => 'PENDING'] + $sbp,
    ['code' => 'MUS-LOSS-EXCEPTION-FROZEN', 'aqd_type' => 'MUSHARAKAH', 'title' => 'Agreed loss-ratio exception is not supported',
        'rule_text' => 'The platform previously allowed an agreed loss ratio different from the capital ratio behind a Shariah approval. The cited standard says the contrary of capital-ratio loss cannot be agreed. The feature is frozen for new contracts and kept only as LEGACY data.',
        'guidance_text' => 'Do not re-enable without a documented ruling by a qualified Shariah board for the exact structure.',
        'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'clause_reference' => 'Art. 19, Art. 12', 'system_effect' => 'New contracts cannot select it; settlement of new contracts always uses capital ratio. LEGACY / DISABLED FOR NEW AQD.', 'scholar' => 'REQUIRED'] + $tkbb7,
    ['code' => 'MUS-SS12-BASE', 'aqd_type' => 'MUSHARAKAH', 'title' => 'AAOIFI SS12 base text',
        'rule_text' => 'AAOIFI Shari\'ah Standard 12 (Sharikah/Musharakah) is the technical benchmark but its full text was NOT accessible; clauses are cited only where a regulator compendium footnotes them.',
        'guidance_text' => 'Source verification required.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'source_type' => 'STANDARD', 'source_name' => 'AAOIFI Shari\'ah Standard No. 12', 'standard_code' => 'AAOIFI-SS12', 'clause_reference' => null, 'source_url' => 'https://cis.aaoifi.com/ar/standards/ss-12-sharikah-musharakah-and-modern-corporations/', 'verification' => 'UNVERIFIED', 'system_effect' => 'None asserted.', 'scholar' => 'REQUIRED'],

    // ------------------------------------------------------------------ MURABAHA
    ['code' => 'MUR-POSSESSION-BEFORE-SALE', 'aqd_type' => 'MURABAHA', 'title' => 'Goods must be in the seller\'s possession; seller bears pre-delivery risk',
        'rule_text' => 'Murabahah to the purchase orderer is permissible on goods already in the seller\'s physical possession as required by Shariah, with the seller bearing the risk of loss before delivery.',
        'guidance_text' => 'The sale is executed only after acquisition, ownership and the required possession (qabd).',
        'classification' => 'MANDATORY', 'clause_reference' => 'Resolution 40-41 (2/5, 3/5) — confirm numbering', 'system_effect' => 'Sale is refused until purchase, ownership, qabd and the risk-bearing period are recorded.', 'scholar' => 'PENDING'] + $iifa40,
    ['code' => 'MUR-PROMISE', 'aqd_type' => 'MURABAHA', 'title' => 'A promise is not the sale; mutual promises need an option',
        'rule_text' => 'A promise is morally binding, and legally binding where conditional and the promisee has incurred expenses. A mutual promise is permissible in Murabahah only if an option is given to one or both parties. A promise cannot replace the sale.',
        'guidance_text' => 'Record any wa\'d separately; the sale is a distinct later contract.',
        'classification' => 'MANDATORY', 'clause_reference' => 'Resolution 40-41 (2/5, 3/5) — confirm numbering', 'system_effect' => 'Promise is its own record; a bilateral promise without an option is rejected; no sale or receivable from a promise.', 'scholar' => 'PENDING'] + $iifa40,
    ['code' => 'MUR-PROMISE-BREACH', 'aqd_type' => 'MURABAHA', 'title' => 'Breach of a binding promise: perform or compensate actual damage',
        'rule_text' => 'A binding promise is either fulfilled or compensation is paid for damage caused by unjustified non-fulfilment. A regulator amendment returns earnest money after deducting actual damages.',
        'guidance_text' => 'Compensation is for actual damage, not a penalty.',
        'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'clause_reference' => 'Resolution 40-41; AAOIFI SS8 2/5/6 (SBP amendment)', 'system_effect' => 'Promise terms store an actual-damages basis only.', 'scholar' => 'REQUIRED'] + $iifa40,
    ['code' => 'MUR-AGENT-NO-DISPOSAL', 'aqd_type' => 'MURABAHA', 'title' => 'Purchasing agent may not consume or sell the goods before the sale to the customer',
        'rule_text' => 'The agent shall not consume or sell goods purchased on behalf of the institution until the institution sells them to the customer (regulator clarification of AAOIFI SS8 clause 3/1/3).',
        'guidance_text' => 'The Wakil acquires for the principal and holds for the principal.', 'classification' => 'MANDATORY', 'standard_code' => 'AAOIFI-SS8', 'clause_reference' => '3/1/3 (SBP clarification)', 'system_effect' => 'Wakil scope excludes disposal; recorded in the Wakalah terms.', 'scholar' => 'PENDING'] + $sbp,
    ['code' => 'MUR-SUPPLIER-PAYMENT', 'aqd_type' => 'MURABAHA', 'title' => 'Supplier payment mechanism',
        'rule_text' => 'Payment through a transaction account in the agent\'s name achieves the rationale of AAOIFI SS8 clause 3/1/4; giving cash to the agent for onward payment is an exception needing the Shariah adviser\'s specific approval (regulator amendment).',
        'guidance_text' => 'Record payment evidence; cash handed to an agent needs explicit approval.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'standard_code' => 'AAOIFI-SS8', 'clause_reference' => '3/1/4 (SBP amendment)', 'system_effect' => 'Acquisition requires payment evidence.', 'scholar' => 'REQUIRED'] + $sbp,
    ['code' => 'MUR-DISCLOSED-COST-PROFIT', 'aqd_type' => 'MURABAHA', 'title' => 'Cost and profit are disclosed',
        'rule_text' => 'Murabahah is a sale at disclosed cost plus a disclosed profit. The AAOIFI SS8 base text was NOT read; this is stated as the standard feature of the contract, pending verification.',
        'guidance_text' => 'The sale price equals disclosed acquisition cost plus disclosed profit.', 'classification' => 'MANDATORY', 'source_type' => 'STANDARD', 'source_name' => 'AAOIFI Shari\'ah Standard No. 8 (base text not read)', 'standard_code' => 'AAOIFI-SS8', 'clause_reference' => null, 'source_url' => 'https://pkic.com/wp-content/uploads/2025/07/SS-8-Murabahah.pdf', 'verification' => 'UNVERIFIED', 'system_effect' => 'Sale price must equal acquisition cost + profit.', 'scholar' => 'REQUIRED'],
    ['code' => 'MUR-NO-RECEIVABLE-BEFORE-SALE', 'aqd_type' => 'MURABAHA', 'title' => 'A receivable exists only after a valid sale',
        'rule_text' => 'Derived from the possession-before-sale rule: the buyer\'s debt arises from the sale, so no receivable may exist before it, and cash given to a customer under a Murabaha label would be a loan.',
        'guidance_text' => 'Never create a receivable from a request, a promise, or a deposit.', 'classification' => 'MANDATORY', 'source_type' => 'PLATFORM_POLICY', 'source_name' => 'Derived from IIFA 40-41; platform control', 'standard_code' => 'IIFA-40-41(2-3/5)', 'clause_reference' => null, 'source_url' => 'https://iifa-aifi.org/en/32332.html', 'verification' => 'VIA_SUMMARY', 'system_effect' => 'Receivable is created only inside the sale transaction.', 'scholar' => 'PENDING'],

    // ------------------------------------------------------------------ WAKALAH
    ['code' => 'WAK-DEFINITION', 'aqd_type' => 'WAKALAH', 'title' => 'Agency is delegation, revocable by both sides',
        'rule_text' => 'Agency is one party delegating another to act on its behalf in matters that can be delegated; both principal and agent can revoke it unilaterally (regulator reading of AAOIFI SS23 clauses 2/1/1 and 2/1/2).',
        'guidance_text' => 'The principal (Muwakkil) must be identified; the Wakil acts within the delegated scope.', 'classification' => 'MANDATORY', 'standard_code' => 'AAOIFI-SS23', 'clause_reference' => '2/1/1, 2/1/2 (SBP footnote)', 'system_effect' => 'Appointment records Muwakkil, Wakil, scope; revocable; not effective by selection alone.', 'scholar' => 'PENDING'] + $sbp,
    ['code' => 'WAK-RESTRICTED', 'aqd_type' => 'WAKALAH', 'title' => 'Restricted agency: act within the principal\'s conditions',
        'rule_text' => 'The principal may restrict the agency and the act delegated; conditions set by the principal are observed (regulator reading of AAOIFI SS23 clauses 2/2/3/4 and 2/2/4).',
        'guidance_text' => 'State exactly what the Wakil may do; anything else is outside authority.', 'classification' => 'MANDATORY', 'standard_code' => 'AAOIFI-SS23', 'clause_reference' => '2/2/3/4, 2/2/4 (SBP footnote)', 'system_effect' => 'Wakil actions outside the recorded scope are rejected.', 'scholar' => 'PENDING'] + $sbp,
    ['code' => 'WAK-ACCEPTANCE', 'aqd_type' => 'WAKALAH', 'title' => 'Offer and acceptance in agency',
        'rule_text' => 'Agency has offer and acceptance, a subject matter and two parties as its elements (reported for AAOIFI SS46 investment agency through a search summary only). Whether explicit written acceptance is required for each structure needs a ruling.',
        'guidance_text' => 'The platform requires an explicit Wakil acceptance record before confirmation.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'source_type' => 'STANDARD', 'source_name' => 'AAOIFI Shari\'ah Standard No. 46 / No. 23 (base text not read)', 'standard_code' => 'AAOIFI-SS46', 'clause_reference' => null, 'source_url' => 'https://pkic.com/wp-content/uploads/2025/07/SS-46-Wakala-Tul-Istithmar.pdf', 'verification' => 'UNVERIFIED', 'system_effect' => 'Acceptance workflow enabled by default (conservative).', 'scholar' => 'REQUIRED'],
    ['code' => 'WAK-PRINCIPAL-ROLE', 'aqd_type' => 'WAKALAH', 'title' => 'Who is the Muwakkil is a structural decision',
        'rule_text' => 'The platform, the business, the capital providers collectively or another party may be the principal; these are materially different structures and none is assumed.',
        'guidance_text' => 'Choose the principal explicitly; the choice is reviewed.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'system_effect' => 'Muwakkil is a mandatory field of every Wakalah appointment.', 'scholar' => 'REQUIRED'] + $none,

    // ------------------------------------------------------------------ GENERAL / ACCOUNTING
    ['code' => 'GEN-PLATFORM-ROLE', 'aqd_type' => 'GENERAL', 'title' => 'The platform\'s contractual role must be explicit',
        'rule_text' => 'The platform could be a Wakil/arranger, a Mudarib, an arranger between direct counterparties, a principal investor, or another structure. These differ in ownership, risk, and liability. None is assumed.',
        'guidance_text' => 'Live money requires a Shariah-board-approved platform role.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'system_effect' => 'Platform role is a setting; investment activation is refused while it is unset (sandbox excepted).', 'scholar' => 'REQUIRED'] + $none,
    ['code' => 'GEN-CLIENT-MONEY', 'aqd_type' => 'GENERAL', 'title' => 'Client money is not platform money',
        'rule_text' => 'Client/custody funds, project/venture funds and platform own funds must be distinguished; any transfer between them needs an explicit basis.',
        'guidance_text' => 'The ledger separates custody cash from platform own funds.', 'classification' => 'POLICY_CHOICE', 'system_effect' => 'Client cash and platform cash are separate accounts.', 'scholar' => 'REQUIRED'] + $none,
    ['code' => 'GEN-ONLINE-SS38', 'aqd_type' => 'GENERAL', 'title' => 'Online dealings and electronic consent',
        'rule_text' => 'AAOIFI Shari\'ah Standard 38 addresses online financial dealings. Its clauses were NOT read.',
        'guidance_text' => 'Source verification required; electronic-signature law is a separate legal question.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'source_type' => 'STANDARD', 'source_name' => 'AAOIFI Shari\'ah Standard No. 38', 'standard_code' => 'AAOIFI-SS38', 'clause_reference' => null, 'source_url' => null, 'verification' => 'UNVERIFIED', 'system_effect' => 'None asserted.', 'scholar' => 'REQUIRED'],
    ['code' => 'GEN-CAPITAL-PROTECTION-SS45', 'aqd_type' => 'GENERAL', 'title' => 'Protection of capital and investments',
        'rule_text' => 'AAOIFI Shari\'ah Standard 45 addresses protection of capital and investments. Its clauses were NOT read; no capital protection feature is offered.',
        'guidance_text' => 'Source verification required.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'source_type' => 'STANDARD', 'source_name' => 'AAOIFI Shari\'ah Standard No. 45', 'standard_code' => 'AAOIFI-SS45', 'clause_reference' => null, 'source_url' => null, 'verification' => 'UNVERIFIED', 'system_effect' => 'No capital-protection feature exists.', 'scholar' => 'REQUIRED'],
    ['code' => 'ACC-FAS51', 'aqd_type' => 'ACCOUNTING', 'title' => 'FAS 51 Participatory Ventures governs Mudaraba/Musharaka accounting',
        'rule_text' => 'AAOIFI issued FAS 51 "Participatory Ventures" (10 Nov 2025), replacing FAS 3 and FAS 4 for its scope. The standard text was NOT read; accounting treatment needs a qualified Islamic accounting review.',
        'guidance_text' => 'Do not rely on FAS 3 / FAS 4.', 'classification' => 'REQUIRES_SCHOLAR_REVIEW', 'source_type' => 'STANDARD', 'source_name' => 'AAOIFI FAS 51 Participatory Ventures (announcement page only)', 'standard_code' => 'AAOIFI-FAS51', 'clause_reference' => null, 'source_url' => 'https://aaoifi.com/announcement/aaoifi-accounting-board-issues-aaoifi-financial-accounting-standard-fas-51-participatory-ventures/?lang=en', 'verification' => 'UNVERIFIED', 'system_effect' => 'Ledger semantics are documented for accounting review.', 'scholar' => 'REQUIRED'],
];
