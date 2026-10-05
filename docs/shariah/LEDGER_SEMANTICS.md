# Ledger semantics

The ledger is one double-entry book. These are **bookkeeping accounts for the platform's records**; whether a balance is the platform's own money, a client's money or a partner's capital is stated per account. `Σ debit-normal = Σ credit-normal` holds by construction and proves nothing about Shariah or economics.

| Account | Normal | Meaning | Owner of the economic interest | When it increases | When it decreases | Non-zero after a completed project? |
|---|---|---|---|---|---|---|
| `CustodyCash` | debit | Cash the platform holds **in custody for clients and partners** | Clients (not the platform) | Verified deposit, business capital received, business remittance, recovery received | Withdrawal paid, capital deployed to the venture | Yes (shared pool) |
| `PlatformCash` | debit | The platform's **own** funds | Platform | Murabaha payments received | Murabaha asset purchase | Yes |
| `InvestorAvailable` | credit | Claim of an investor to withdraw custody cash | Investor | Deposit, capital/profit/recovery paid out | Investment, withdrawal request | Yes |
| `InvestorPending` | credit | Withdrawal in progress | Investor | Withdrawal requested | Paid or released | Yes |
| `InvestorInvested` | credit | **Capital at risk** committed to a venture (an investment interest, never a guaranteed claim) | Investor | Investment | Loss recognised, capital returned | No |
| `BusinessCapital` | credit | The business partner's capital at risk in a Musharakah | Business | Business contribution received | Loss recognised, capital returned | No |
| `VentureCapital` | debit | Participant capital delivered to the venture, carried at cost, written down by loss | Participants (investor and business) | Capital deployment | Capital returned, loss recognised | No |
| `ProjectFunds` | credit | **Interim proceeds** received from the business, held pending the final determination | Participants | Interim profit/proceeds remitted | Distributed at settlement | No |
| `BusinessFunds` | credit | Settlement payable to the business after a valid settlement (capital returned, profit share) | Business | Settlement | Payout | Yes |
| `Clearing` | credit | Technical suspense. **Never** a loss account. Must be zero | — | — | — | Never |
| `CapitalDeployed` | debit | **LEGACY** gross-up pair account from the earlier funding design | — | no new postings | — | Legacy only |
| `MurabahaInventory` | debit | Goods the platform owns after purchase (at cost) | Platform | Purchase | Sale | No |
| `MurabahaReceivable` | debit | Buyer's debt **after** a valid sale | Platform | Sale | Buyer payment | No |
| `MurabahaSaleProfit` | credit | Disclosed sale profit | Platform | Sale | — | Yes |
| `PlatformFees` | credit | Fees (unused) | Platform | — | — | — |

Transaction types are documented in `app/Enums/TransactionType::semantics()` (business event, Shariah meaning, debit, credit, owner, evidence, lifecycle) and enforced by a test that every case has an entry.

## Reconciliation of the Aqd layer (`php artisan finance:reconcile [--strict]`)
`Aqd Document Integrity` (hashes, signatures bound to the document hash, executed ⇒ all required signatures, one live executed master per contract, superseded ⇒ executed successor), `Aqd Shariah Activation` (funding/active/completed projects have an executed master whose terms are the terms the reviewer approved; platform role outside the sandbox), `Aqd Term Integrity` (agreement terms equal the contract terms the books use), `Investor Contract Linkage` (each investment backed by one executed participation of the same investor/project/amount), `Wakalah Integrity`, `Murabaha Sequence`. Output names record ids only. Broken invariants are errors (non-zero exit in both modes); LEGACY records are warnings that fail only under `--strict`.
