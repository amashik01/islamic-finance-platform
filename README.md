# Amanah Capital — Islamic Investment & Financing Platform

Laravel 12 · Livewire 3 · Tailwind CSS 3 · Alpine.js · Pest · MySQL 8 (SQLite for tests).
Custom UI throughout — no Filament, AdminLTE, Nova, Backpack or dashboard template.

MVP contracts: **Mudarabah**, **Musharakah**, **Murabaha** (modelled separately; Murabaha is an asset sale, never "interest").

## Run locally
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

## Status
See `docs/ROADMAP.md`.
