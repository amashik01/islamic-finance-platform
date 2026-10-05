# Build status by phase

| Phase | Scope | Status |
|---|---|---|
| 1 Foundation | Laravel 12, Livewire 3, Tailwind tokens, Breeze auth, roles/permissions, 4 layouts, design system | Done |
| 2 Core domain | Schema, enums, models, policies | Done |
| 3 KYC & documents | KYC review workflow, private storage, versioning | Not started (tables + DocumentPolicy exist) |
| 4 Mudarabah | Calculator done; project flow, settlement posting | Partial |
| 5 Musharakah | Calculator done; contributions, activation, settlement | Partial |
| 6 Murabaha | Calculator, schema, seed done; stage workflow, payment posting | Partial |
| 7 Wallet & ledger | Ledger, deposits, investments, withdrawals, idempotency | Done (principal-return / profit posting arrives with the settlement service) |
| 8 Investor portal | Dashboard done; wallet, invest modal, investments, withdrawal UI | Partial |
| 9 Business portal | Dashboard done; project wizard, funding, payments | Partial |
| 10 Admin portal | Dashboard done; data tables, project review, withdrawals/ledger/audit UIs | Partial |
| 11 Public site | Home, opportunities, detail, Islamic finance, FAQ, legal placeholders | Done (legal text needs counsel) |
| 12 Reporting | Charts, statements, CSV/PDF | Not started |
| 13 Testing | 72 tests: money, calculators, ledger, wallet, authz, IDOR, CSRF, mass assignment | Ongoing |
| 14 Polish | Notifications UI, settings, upload tests, MySQL concurrency test | Not started |

Known limit: tests run on SQLite, where row locks are no-ops. A true parallel-connection concurrency test needs MySQL.
