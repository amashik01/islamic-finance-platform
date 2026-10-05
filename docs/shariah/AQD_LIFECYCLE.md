# Aqd lifecycle and gates

```
Aqd chosen → Terms entered (Shariah-aware validation) → Submitted
  → Shariah review (project / aqd terms / template version)  [NEEDS_REVISION loops back]
  → Master agreement generated from approved terms + approved template version
  → Business signs (executes) the master agreement
  → Wakalah appointments (if any): proposed → Wakil acceptance → appointment-level Shariah review → CONFIRMED
  → Project published
  → Investor reviews disclosure → consents → participation agreement generated (exact amount, document hash)
  → Investor signs → participation EXECUTED
  → FINANCIAL ACTIVATION GATE (server side, inside the investment transaction)
  → Ledger posting
```

## Financial activation gate (checked inside the locked investment transaction — `AqdGate`)
1. Investor KYC approved; project approved and in FUNDING; contract APPROVED.
2. Latest Shariah review APPROVED, and the executed master agreement is the exact text (`terms_hash`) that was approved.
3. No live Wakalah appointment that is not CONFIRMED.
4. Master agreement EXECUTED (business signed) with a stored hash that recomputes.
5. An EXECUTED participation agreement of this investor for exactly this project and amount, hash intact, not already consumed — consumed atomically by the investment.
6. Platform role approved (`shariah.platform_role` not `UNSET`); the sandbox (`finance.sandbox`, default on outside production) is the only exemption.
7. Idempotency key unused (or same request hash).

Murabaha is not an investment product and never passes this gate.

Selecting an aqd, a Wakil, or any form field posts **no** ledger entry. Only financial events do.

## Contract status vs signature
APPROVED (terms accepted by reviewers) ≠ EXECUTED (signed). Activation of the venture still needs full funding and, for Musharakah, the received business capital.
