# Build status by phase

## Financial integrity hardening (done)
- **BDT only.** One canonical code (`Currency::CODE`); `Money` cannot be constructed in any other currency; every financial model rejects non-BDT on save; MySQL CHECK constraints enforce it in the database. No FX, no conversion.
- **Ledger.** Currency checked on the transaction, every amount and every (locked) account; balanced entries; stable lock order; account balances can only change inside `LedgerService`.
- **Idempotency.** Request fingerprints (`request_hash`) on deposits, withdrawals, investments, Murabaha payments, settlements and ledger transactions: same key + same request returns the original; same key + different request is rejected.
- **Mudarabah.** Business profit share is recorded as its own settlement item and ledger entry; documented fault creates an auditable `manager_recoveries` row for the recoverable amount; ordinary loss falls on capital.
- **Musharakah.** Loss follows capital contribution. An agreed ratio is an exception: Shariah-reviewer approval + documented reason + before contract start + audited.
- **Murabaha.** Purchase, sale, receivable and payments are on the ledger (inventory → receivable + recognised sale profit → cash); subledger reconciles to the ledger.
- **Reconciliation.** `php artisan finance:reconcile [--strict]` (read-only, ids only, non-zero exit on failure). CI runs it strict on seeded data.



| Phase | Scope | Status |
|---|---|---|
| 1 Foundation | Laravel 12, Livewire 3, Tailwind tokens, Breeze auth, roles/permissions, 4 layouts, design system | Done |
| 2 Core domain | Schema, 22 enums, models, policies | Done |
| 3 KYC & documents | Private storage, content-sniffed uploads, versioning, verification, KYC review workflow | Done |
| 4 Mudarabah | Builder validation, project workflow, funding, settlement (principal / profit / loss / manager fault) | Done |
| 5 Musharakah | Contributions, ownership vs profit ratio, loss basis, settlement | Done |
| 6 Murabaha | Ordered asset workflow (verify → purchase → own → possess → sale), receivable, schedule, payments, overdue, settlement | Done |
| 7 Wallet & ledger | Double-entry ledger, deposits, investments, withdrawals, reversals, idempotency, row locks | Done |
| 8 Investor portal | Dashboard + portfolio chart, wallet, opportunities + invest modal, investments + details, contracts, transactions, withdrawals, documents/KYC, notifications, reports | Done |
| 9 Business portal | Dashboard, 7-step wizard, projects, details + feedback, funding, contracts, payments, settlements, documents, reports | Done |
| 10 Admin portal | Command center + charts, global search, reusable data table, project review, KYC, documents, deposits, withdrawals, ledger + reversal, contracts (settle / Murabaha workflow), Shariah reviews, audit log, users + role changes, settings | Done |
| 11 Public site | Home, how it works, opportunities + detail, Islamic finance, for businesses, FAQ, legal placeholders | Done (legal text needs counsel) |
| 12 Reporting | 15 reports as CSV + print/PDF view, role-scoped, audited, formula-injection safe | Done (native PDF library not added; print view saves as PDF) |
| 13 Testing | 315 tests on SQLite; 329 on MySQL incl. 11 parallel-connection race tests | Done |
| Wakalah | Wakil role, WakilProfile eligibility (existing KYC), per-role Wakalah appointments on Projects, audit, Shariah-review confirmation | Done — no Wakil portal, no Wakalah revocation/replacement after funding, no Wakil acceptance step |
| P0 financial lifecycle | Contract activation state machine, project funding legs (`CapitalDeployed`/`ProjectFunds`), ledger-backed Musharakah business capital, settlement funded from the pool, Musharakah business capital return/loss, extended reconciliation | Done — requires qualified Shariah review before real-money deployment |
| 14 Polish | Security headers, throttling, loading/empty/error states | Mostly done — see gaps |

## Known gaps / not yet built
- Email delivery (notifications are database-only; the `mail` channel is a one-line change once SMTP is configured).
- Payment gateway / bank / mobile-money integration, real KYC/AML providers, transaction monitoring, regulatory and tax reporting (architecture leaves room; nothing integrated).
- Investor and business "Settings" pages, theme preference, bulk actions and column selection in tables.
- Admin staff creation UI (role changes exist; new staff are created via seeder/tinker).
- Native PDF generation, full automated accessibility audit, and image optimisation.
- Legal pages are placeholders; Shariah/legal/regulatory review is required before any real funds.

- Backfill for databases created before the P0 lifecycle change (missing `PROJECT_FUNDING` legs must be posted via `LedgerService`, never by editing entries).
- No payout flow yet for business funds (capital return, profit share) or for manager recoveries.
- Contract status changes in project approve/reject and Murabaha still use direct updates (activation/completion of Mudarabah/Musharakah use the state machine).
- MySQL CHECK constraints for the new tables exist only on MySQL.
- Not ready for real money: financial-professional, Shariah, legal/regulatory and security reviews, payment provider and KYC/AML integration are still required.

## Testing
- `php artisan test` — runs on SQLite; the MySQL-only concurrency/constraint tests skip.
- MySQL (full suite + real parallel-connection race tests):
  `DB_CONNECTION=mysql DB_DATABASE=islamic_finance_test DB_USERNAME=root php artisan test`
