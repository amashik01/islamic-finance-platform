# Amanah Capital — Islamic Investment & Financing Platform

Laravel 12 · Livewire 3 · Tailwind CSS 3 · Alpine.js · Pest · MySQL 8 (SQLite for tests).
Custom UI throughout — no Filament, AdminLTE, Nova, Backpack or dashboard template.

MVP contracts: **Mudarabah**, **Musharakah**, **Murabaha** (modelled separately; Murabaha is an asset sale, never "interest").

## Quick start
**Windows:** double-click `setup.bat`. **macOS/Linux:** run `./setup.sh`. It installs everything, creates a SQLite database with demo data and opens http://localhost:8000.

## Run locally (manual)
```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
# set DB_* for MySQL 8
php artisan migrate --seed      # roles + clearly-flagged demo data (non-production only)
php artisan serve
```
Demo logins (password `password`): `admin@demo.test`, `manager@demo.test`, `staff@demo.test`, `investor1@demo.test`, `business1@demo.test`.
Run tests: `php artisan test`.

## Architecture
- Money is integer minor units (`App\Support\Money\Money`); ratios are basis points. No floats.
- The ledger is the source of truth: balanced double-entry `transactions` + immutable `ledger_entries`; corrections are reversals. Cached balances change only inside `LedgerService`, under row locks, in DB transactions.
- Idempotency keys on deposits, withdrawals, investments and ledger transactions.
- Calculators (`app/Services/Finance`) are pure and independent of the UI.
- Contract extension point: add a `*_contracts` table, a `ContractType` case and a calculator; users, wallets, ledger, documents and audit stay untouched.

## Financial integrity
- **Operational currency: BDT only.** No FX, no other currencies; non-BDT is rejected in code and (on MySQL) by database constraints.
- `php artisan finance:reconcile` runs read-only integrity checks (currency, ledger, wallets, investments, project funding, contract lifecycle, settlements, Murabaha, idempotency; failures name project/contract/investment/settlement/transaction ids). Add `--strict` for CI/deployment gates; any failure exits non-zero.

## Contract lifecycle and capital flow (Mudarabah / Musharakah)
Implemented according to the current product specification; requires qualified Shariah review before real-money deployment.

- **Activation rule.** A contract is activated only by `ContractLifecycle::activateIfFunded`, inside the funding transaction, exactly once (state machine `Approved → Active`). It requires: the contract is Approved, the latest Shariah review is Approved, the project is fully funded, and — for Musharakah — the business capital contribution has been received. Partial funding, or investors alone in a Musharakah, never starts the contract.
- **Project funding.** Each investment posts two *different* facts, never two debits of the same money: the investor claim (`InvestorAvailable → InvestorInvested`) and the pool funding (`CapitalDeployed → ProjectFunds`, type `PROJECT_FUNDING`). `InvestorInvested` stays the investor's claim; `CapitalDeployed` (debit-normal) is the offset for the capital the pool holds on investors' behalf. `ProjectFunds` can no longer go negative, so the pool cannot be over-distributed.
- **Musharakah capital.** The business contribution is a ledger-backed record (`musharakah_capital_contributions`, tx `MUSHARAKAH_CAPITAL`: Dr PlatformCash / Cr ProjectFunds), exactly once per contract, amount equal to the agreed contribution.
- **Settlement funding.** Settlement pays out of `ProjectFunds`. A profit therefore requires the business to remit the actual profit first (`BUSINESS_REMITTANCE`); otherwise the settlement is refused with the shortfall. Principal is released per investment (`CAPITAL_RELEASE`).
- **Musharakah profit/loss.** Profit is split by the agreed profit ratio. An ordinary loss is allocated by capital ratio to *both* investors and the business (`CAPITAL_LOSS`, `BUSINESS_CAPITAL_LOSS`); the business capital is returned net of its share (`BUSINESS_CAPITAL_RETURN`). No business debt or manager recovery arises from an ordinary loss. The exceptional (agreed) loss ratio still needs documented Shariah approval before activation and cannot be revoked afterwards.
- Existing databases created before this change have investments without `PROJECT_FUNDING` transactions and will fail `finance:reconcile` until a backfill posts the missing funding legs through `LedgerService` (never by editing entries).

## Wakalah (appointed Wakil)
```
Project
  └── Appointed Wakil (projects.wakil_id → users.id, restrictOnDelete)
        └── Wakalah Appointment (one per Wakalah Role: PROPOSED → CONFIRMED → REVOKED)
```
- **Wakalah is an agency arrangement** (Muwakkil appoints a Wakil). It is separate from Mudarabah, Musharakah and Murabaha: appointing a Wakil never creates a sale, a ledger transaction or a cash movement, and the Murabaha lifecycle (supplier → purchase → ownership → qabd → sale → receivable) is unchanged.
- **Eligible Wakil** = user with the `WAKIL` role + active user + verified email + `WakilProfile` with KYC `APPROVED` (reviewed through the existing KYC review) + profile not suspended. Registration/suspension needs `wakils.manage`. The selector and the server-side check use the same single definition (`WakilProfile::eligible()`); a submitted id is never trusted.
- **Wakalah Role** is explicit where the contract defines roles. Murabaha: *Wakil for Purchase*, *Wakil for Asset Acquisition*, *Wakil for Delivery / Qabd* (each its own appointment). Mudarabah and Musharakah define none yet.
- **Selecting is not approving.** A selected Wakil is `PROPOSED`; the project's Shariah review approval confirms it, and publishing is refused while an appointment is unconfirmed. The software does not certify anything.
- **Editing.** The business may change the Wakil only while the project is Draft / Needs Revision; staff (`projects.edit`) until the project is published. After funding starts a separate Wakalah revocation/replacement workflow is required (not built).
- **Audit events:** `project.wakil_assigned`, `project.wakil_changed`, `project.wakil_removed`, `wakalah.role_changed`, `wakalah.appointment_confirmed`, `wakil.registered`, `wakil.suspended`, `wakil.reinstated`.

## Quality gates
- 329 tests (SQLite 315 + MySQL-only constraint/concurrency tests): money maths, calculators, ledger integrity, wallet/withdrawal flows, settlement, Murabaha stages, authorization, IDOR, CSRF, mass assignment, uploads, reports, UI components.
- Concurrency tests (MySQL) race real database connections to prove a wallet cannot be double-spent and idempotency keys create exactly one record; concurrent final funding, repeated activation, concurrent settlement and funding-vs-settlement races are covered.

## Status
See `docs/ROADMAP.md` for what is done and what is not.

> This software does not make any structure Shariah-compliant and is not a religious authority. Operating an investment or financing platform with public funds may require licences; obtain legal, regulatory and Shariah advice before handling real money.
