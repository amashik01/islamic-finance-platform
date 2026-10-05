# Build status by phase

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
| 13 Testing | 208 tests (SQLite) + 3 parallel-connection MySQL tests | Done |
| 14 Polish | Security headers, throttling, loading/empty/error states | Mostly done — see gaps |

## Known gaps / not yet built
- Email delivery (notifications are database-only; the `mail` channel is a one-line change once SMTP is configured).
- Payment gateway / bank / mobile-money integration, real KYC/AML providers, transaction monitoring, regulatory and tax reporting (architecture leaves room; nothing integrated).
- Investor and business "Settings" pages, theme preference, bulk actions and column selection in tables.
- Admin staff creation UI (role changes exist; new staff are created via seeder/tinker).
- Native PDF generation, full automated accessibility audit, and image optimisation.
- Legal pages are placeholders; Shariah/legal/regulatory review is required before any real funds.

## Testing
- `php artisan test` — runs on SQLite; the 3 concurrency tests skip.
- MySQL (full suite + real parallel-connection race tests):
  `DB_CONNECTION=mysql DB_DATABASE=islamic_finance_test DB_USERNAME=root php artisan test`
