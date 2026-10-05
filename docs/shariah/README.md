# Shariah governance documentation

> **NOT LEGAL ADVICE. NOT A FATWA. NOT SHARIAH CERTIFICATION.** This directory documents how the software implements the
> *current product specification*. It requires review by a qualified Shariah scholar/board, qualified legal counsel
> (Bangladesh), an Islamic accounting professional, and compliance/security reviewers before any real-money deployment.

| File | Purpose |
|---|---|
| `SOURCES.md` | What was actually read, what was not, and how reliable each source is |
| `SHARIAH_RULE_MATRIX.md` | Generated from `config/shariah_rules.php` (35 rules, each with classification, source, verification level) |
| `AQD_LIFECYCLE.md` | The gates between a draft project and a financial movement |
| `MUDARABAH_SPEC.md`, `MUSHARAKAH_SPEC.md`, `MURABAHA_SPEC.md`, `WAKALAH_SPEC.md` | Per-aqd domain specifications |
| `CONTRACT_SPEC.md` | Templates, documents, hashing, signatures, amendments |
| `LEDGER_SEMANTICS.md` | What each ledger account and transaction type means, and whose money it is |
| `OPEN_SCHOLAR_QUESTIONS.md` | Everything that needs a human decision (Shariah, accounting, legal) |

## Phase A — audit baseline (commit `edf5884`)
**Existing correct behaviour (preserved):** BDT-only; balanced immutable double-entry ledger; idempotency with request hashes; ordinary
Mudarabah/Musharakah loss reduces capital without a debt; Murabaha stage order (verify → purchase → own → possess → sale);
the receivable is created only inside the sale; Wakil eligibility (role, active, verified, KYC); Wakil selection posts no ledger entry;
no "guaranteed return" language in the UI; Shariah approval required to publish.

**Existing incorrect or unsafe behaviour (this work addresses it):**
1. One generic project wizard for three different contracts.
2. Musharakah agreed-loss exception selectable behind a Shariah approval (not supported by the cited standard) → **frozen**.
3. Investor loss parked in `Clearing`; business loss credited to `PlatformCash` with no cash movement → artifacts in the books.
4. `PlatformCash` mixes client money, venture money and platform money; `CapitalDeployed` is a gross-up pair, not an asset.
5. Investment became active on a button click: no executed agreement, no signature, no hash.
6. No document/signature/amendment capability.
7. Wakalah appointment: no principal (Muwakkil), no scope, no Wakil acceptance; confirmed by a project-level review.
8. Manager recovery record created by an admin at settlement (an assertion, not an established liability).
9. Interim cash remittance treated as final profit.
10. Murabaha: no promise record, no risk-bearing step, no executed sale agreement before the receivable.
11. Platform role undefined.

See `OPEN_SCHOLAR_QUESTIONS.md` for what this work could not decide.
