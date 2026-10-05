# Shariah rule matrix

> Generated from `config/shariah_rules.php` by `php artisan shariah:rules export`. Do not edit by hand.
> Paraphrases, not quotations. Nothing here is a fatwa, a certification or legal advice. Every rule is UNDER_REVIEW until a qualified Shariah reviewer records otherwise.

Verification: TEXT_READ = clause read in the source text; VIA_SUMMARY = official page fetched through a summariser, confirm numbering; SECONDARY = cited by a regulator compendium, base text not read; UNVERIFIED = **source verification required**.

## MUDARABAH

| Code | Rule | Class | Source | Clause | Verification | System effect |
|---|---|---|---|---|---|---|
| `MUD-PROFIT-RATIO` | Profit is a share of actual profit | PROHIBITED | TKBB Participation Finance Standards No. 5 — Mudarabah Standard | §2.4.1 | TEXT_READ | Rejects fixed-amount and %-of-capital profit; requires two ratios totalling 100%. |
| `MUD-LOSS-RABB` | Ordinary loss falls on the capital provider | MANDATORY | TKBB Participation Finance Standards No. 5 — Mudarabah Standard | §2.2.4, §2.4.6 | TEXT_READ | Rejects Mudarib capital-guarantee clauses; ordinary loss reduces capital with no Mudarib debt. |
| `MUD-MUDARIB-FAULT` | Mudarib at fault compensates and forfeits remuneration | MANDATORY | TKBB Participation Finance Standards No. 5 — Mudarabah Standard | §2.4.6 | TEXT_READ | Manager recovery follows a staged lifecycle (suspected → fault established → liability recognised → recovery). |
| `MUD-CAPITAL-DELIVERY` | Capital is delivered to the Mudarib | MANDATORY | TKBB Participation Finance Standards No. 5 — Mudarabah Standard | §2.2.2 | TEXT_READ | Capital deployment is an explicit, recorded event; no deployment without an executed agreement. |
| `MUD-ADVANCES` | Interim payments are advances; final result on liquidation | MANDATORY | TKBB Participation Finance Standards No. 5 — Mudarabah Standard | §2.4.5, §2.6.4 | TEXT_READ | Interim remittances are recorded as interim proceeds held pending final determination. |
| `MUD-GUARANTEE-FAULT-ONLY` | Collateral only against fault or breach | PERMISSIBLE | TKBB Participation Finance Standards No. 5 — Mudarabah Standard | §2.5.1, §2.5.2 | TEXT_READ | Security terms, if any, are stored as fault-only. |
| `MUD-VOLUNTARY-LOSS` | Voluntary assumption of loss is not a stipulable term | PROHIBITED | TKBB Participation Finance Standards No. 5 — Mudarabah Standard | §2.4.7 | TEXT_READ | No field exists for the Mudarib to assume ordinary loss. |
| `MUD-NO-CAPITAL-PROFIT-GUARANTEE` | No guarantee of capital or fixed profit by the manager | PROHIBITED | International Islamic Fiqh Academy — Resolution 30 (5/4) Muqaradah and Investment Certificates | cl. 4, cl. 8 (as reported — confirm numbering) | VIA_SUMMARY | Blocks guarantee language in terms; UI never says guaranteed. |
| `MUD-DISTRIBUTION-SS40` | Profit distribution in Mudarabah-based investment accounts | REQUIRES_SCHOLAR_REVIEW | AAOIFI Shari'ah Standard No. 40 | — | UNVERIFIED | None asserted. |

## MUSHARAKAH

| Code | Rule | Class | Source | Clause | Verification | System effect |
|---|---|---|---|---|---|---|
| `MUS-LOSS-CAPITAL` | Loss follows capital share | MANDATORY | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 12, Art. 19 | TEXT_READ | Ordinary loss is always allocated by capital ratio; the agreed-loss exception is frozen for new contracts. |
| `MUS-PROFIT-RATIO` | Profit ratio may differ from capital ratio | PERMISSIBLE | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 15, Art. 16 | TEXT_READ | Profit ratio is an independent field; never derived from capital ratio. |
| `MUS-NO-CAPITAL-GUARANTEE` | Capital is at risk and cannot be guaranteed by a partner or manager | PROHIBITED | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 14, Art. 25 | TEXT_READ | Business capital is partnership capital; no business receivable arises from ordinary loss. |
| `MUS-NO-PROFIT-GUARANTEE` | No profit guarantee | PROHIBITED | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 17 | TEXT_READ | Blocks fixed-return terms; every partner has a positive profit share. |
| `MUS-MANAGER-FAULT` | Manager liable only for fault or breach | MANDATORY | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 20 | TEXT_READ | No ordinary-loss liability for the managing partner. |
| `MUS-CAPITAL-TIMING` | Agreed shares are paid before operations start | MANDATORY | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 11 | TEXT_READ | Activation requires the business contribution to be received and recorded. |
| `MUS-ADVANCES` | Interim payments are advances | MANDATORY | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 21 and its justification | TEXT_READ | Same interim-proceeds treatment as Mudarabah. |
| `MUS-NO-FACE-VALUE-BUYBACK` | No promise to buy partnership assets at face or pre-agreed value | PROHIBITED | State Bank of Pakistan — Compendium of Shariah Standards (updated 31 Jul 2025), citing AAOIFI clauses (Pakistan jurisdiction; not Bangladesh) | 3/1/6/2 (SBP footnote) | SECONDARY | No buy-back or capital-return promise field exists. |
| `MUS-LOSS-EXCEPTION-FROZEN` | Agreed loss-ratio exception is not supported | REQUIRES_SCHOLAR_REVIEW | TKBB Participation Finance Standards No. 7 — Musharakah Standard (23.06.2025) | Art. 19, Art. 12 | TEXT_READ | New contracts cannot select it; settlement of new contracts always uses capital ratio. LEGACY / DISABLED FOR NEW AQD. |
| `MUS-SS12-BASE` | AAOIFI SS12 base text | REQUIRES_SCHOLAR_REVIEW | AAOIFI Shari'ah Standard No. 12 | — | UNVERIFIED | None asserted. |

## MURABAHA

| Code | Rule | Class | Source | Clause | Verification | System effect |
|---|---|---|---|---|---|---|
| `MUR-POSSESSION-BEFORE-SALE` | Goods must be in the seller's possession; seller bears pre-delivery risk | MANDATORY | International Islamic Fiqh Academy — Resolutions 40-41 (2/5, 3/5) Keeping a Promise and Murabahah to the Purchase Orderer | Resolution 40-41 (2/5, 3/5) — confirm numbering | VIA_SUMMARY | Sale is refused until purchase, ownership, qabd and the risk-bearing period are recorded. |
| `MUR-PROMISE` | A promise is not the sale; mutual promises need an option | MANDATORY | International Islamic Fiqh Academy — Resolutions 40-41 (2/5, 3/5) Keeping a Promise and Murabahah to the Purchase Orderer | Resolution 40-41 (2/5, 3/5) — confirm numbering | VIA_SUMMARY | Promise is its own record; a bilateral promise without an option is rejected; no sale or receivable from a promise. |
| `MUR-PROMISE-BREACH` | Breach of a binding promise: perform or compensate actual damage | REQUIRES_SCHOLAR_REVIEW | International Islamic Fiqh Academy — Resolutions 40-41 (2/5, 3/5) Keeping a Promise and Murabahah to the Purchase Orderer | Resolution 40-41; AAOIFI SS8 2/5/6 (SBP amendment) | VIA_SUMMARY | Promise terms store an actual-damages basis only. |
| `MUR-AGENT-NO-DISPOSAL` | Purchasing agent may not consume or sell the goods before the sale to the customer | MANDATORY | State Bank of Pakistan — Compendium of Shariah Standards (updated 31 Jul 2025), citing AAOIFI clauses (Pakistan jurisdiction; not Bangladesh) | 3/1/3 (SBP clarification) | SECONDARY | Wakil scope excludes disposal; recorded in the Wakalah terms. |
| `MUR-SUPPLIER-PAYMENT` | Supplier payment mechanism | REQUIRES_SCHOLAR_REVIEW | State Bank of Pakistan — Compendium of Shariah Standards (updated 31 Jul 2025), citing AAOIFI clauses (Pakistan jurisdiction; not Bangladesh) | 3/1/4 (SBP amendment) | SECONDARY | Acquisition requires payment evidence. |
| `MUR-DISCLOSED-COST-PROFIT` | Cost and profit are disclosed | MANDATORY | AAOIFI Shari'ah Standard No. 8 (base text not read) | — | UNVERIFIED | Sale price must equal acquisition cost + profit. |
| `MUR-NO-RECEIVABLE-BEFORE-SALE` | A receivable exists only after a valid sale | MANDATORY | Derived from IIFA 40-41; platform control | — | VIA_SUMMARY | Receivable is created only inside the sale transaction. |

## WAKALAH

| Code | Rule | Class | Source | Clause | Verification | System effect |
|---|---|---|---|---|---|---|
| `WAK-DEFINITION` | Agency is delegation, revocable by both sides | MANDATORY | State Bank of Pakistan — Compendium of Shariah Standards (updated 31 Jul 2025), citing AAOIFI clauses (Pakistan jurisdiction; not Bangladesh) | 2/1/1, 2/1/2 (SBP footnote) | SECONDARY | Appointment records Muwakkil, Wakil, scope; revocable; not effective by selection alone. |
| `WAK-RESTRICTED` | Restricted agency: act within the principal's conditions | MANDATORY | State Bank of Pakistan — Compendium of Shariah Standards (updated 31 Jul 2025), citing AAOIFI clauses (Pakistan jurisdiction; not Bangladesh) | 2/2/3/4, 2/2/4 (SBP footnote) | SECONDARY | Wakil actions outside the recorded scope are rejected. |
| `WAK-ACCEPTANCE` | Offer and acceptance in agency | REQUIRES_SCHOLAR_REVIEW | AAOIFI Shari'ah Standard No. 46 / No. 23 (base text not read) | — | UNVERIFIED | Acceptance workflow enabled by default (conservative). |
| `WAK-PRINCIPAL-ROLE` | Who is the Muwakkil is a structural decision | REQUIRES_SCHOLAR_REVIEW | Platform policy — no external source; requires governance decision | — | UNVERIFIED | Muwakkil is a mandatory field of every Wakalah appointment. |

## GENERAL

| Code | Rule | Class | Source | Clause | Verification | System effect |
|---|---|---|---|---|---|---|
| `GEN-PLATFORM-ROLE` | The platform's contractual role must be explicit | REQUIRES_SCHOLAR_REVIEW | Platform policy — no external source; requires governance decision | — | UNVERIFIED | Platform role is a setting; investment activation is refused while it is unset (sandbox excepted). |
| `GEN-CLIENT-MONEY` | Client money is not platform money | POLICY_CHOICE | Platform policy — no external source; requires governance decision | — | UNVERIFIED | Client cash and platform cash are separate accounts. |
| `GEN-ONLINE-SS38` | Online dealings and electronic consent | REQUIRES_SCHOLAR_REVIEW | AAOIFI Shari'ah Standard No. 38 | — | UNVERIFIED | None asserted. |
| `GEN-CAPITAL-PROTECTION-SS45` | Protection of capital and investments | REQUIRES_SCHOLAR_REVIEW | AAOIFI Shari'ah Standard No. 45 | — | UNVERIFIED | No capital-protection feature exists. |

## ACCOUNTING

| Code | Rule | Class | Source | Clause | Verification | System effect |
|---|---|---|---|---|---|---|
| `ACC-FAS51` | FAS 51 Participatory Ventures governs Mudaraba/Musharaka accounting | REQUIRES_SCHOLAR_REVIEW | AAOIFI FAS 51 Participatory Ventures (announcement page only) | — | UNVERIFIED | Ledger semantics are documented for accounting review. |

