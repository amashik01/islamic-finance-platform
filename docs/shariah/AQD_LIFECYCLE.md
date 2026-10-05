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

## Financial activation gate (all must hold, checked inside the locked transaction)
1. Investor KYC approved; project approved and in FUNDING.
2. Contract APPROVED; latest Shariah review APPROVED for the same aqd type and template version.
3. No live Wakalah appointment that is not CONFIRMED (and none required by the aqd that is missing).
4. Master agreement EXECUTED (business signed) with a stored hash that recomputes.
5. Participation agreement for this investor and amount EXECUTED, hash intact, not already consumed.
6. Platform role configured (sandbox mode excepted and then labelled).
7. Idempotency key unused (or same request hash).

Selecting an aqd, a Wakil, or any form field posts **no** ledger entry. Only financial events do.

## Contract status vs signature
APPROVED (terms accepted by reviewers) ≠ EXECUTED (signed). Activation of the venture still needs full funding and, for Musharakah, the received business capital.
