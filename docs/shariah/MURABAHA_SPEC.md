# Murabaha specification
Murabaha is a **sale**, not a cash loan, and stays separate from the partnership aqds.
Sequence enforced by the software:
`Request → Promise (if used) → Wakalah (if used) → Supplier purchase → Acquisition → Ownership → Qabd → Risk-bearing period → Murabaha sale agreement executed → Sale → Receivable → Installments → Settlement`.
- A **promise (wa'd)** is its own record (unilateral, or mutual only with an option for one or both parties — MUR-PROMISE). It never creates a sale or receivable.
- The sale references the acquired asset, discloses acquisition cost and profit, and needs an **executed** sale agreement.
- The receivable is created only inside the sale transaction (MUR-NO-RECEIVABLE-BEFORE-SALE). Murabaha is not an investment product; investor cash never becomes a receivable.
- A Wakil acts within the recorded scope; may not consume or sell the goods before the principal sells them (MUR-AGENT-NO-DISPOSAL); payment to the supplier needs evidence (MUR-SUPPLIER-PAYMENT).

## Hard gates as implemented (`MurabahaService`)
- Purchase needs the **executed master agreement** (non-legacy contracts) and, if the terms say a promise is used, the recorded promise (`murabaha_promises`: unilateral, or mutual only with an option; one per contract; recorded before purchase).
- Ownership and possession are recorded with the possession type (`ACTUAL`, or `CONSTRUCTIVE` which needs Shariah review). A Wakil may record only acts its confirmed Wakalah grants (`pay_supplier`, `record_title`, `take_delivery`); the acting Wakil is stored on the purchase.
- `confirmRiskBorne`: after possession the seller confirms, with a description, that it bore the asset's risk for at least `risk_bearing_days` (policy value — **REQUIRES QUALIFIED SHARIAH REVIEW**); not in the future.
- `prepareSaleAgreement` generates the `MURABAHA_SALE` document only after possession and risk confirmation; the buyer (business) and the seller (staff) both sign.
- `executeSale` refuses without possession, risk confirmation, the promise (if used) and an **executed, hash-intact sale agreement**; the receivable is created only inside that transaction and the sale stores `sale_document_id`.
Pre-engine ("LEGACY") contracts are reported as warnings by reconciliation, not rewritten.
