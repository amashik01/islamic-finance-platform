# Musharakah specification
Parties: **Musharik** (partner) — the investor side and the business. Each partner's **Capital Contribution** is recorded with method, date and evidence; the business contribution is a ledger-backed event received **before activation** (MUS-CAPITAL-TIMING).
Profit-sharing ratio: agreed, independent of the capital ratio (MUS-PROFIT-RATIO).
Loss-sharing ratio: **always the capital ratio**; there is no field to change it (MUS-LOSS-CAPITAL). The previous agreed-ratio exception is **LEGACY / DISABLED FOR NEW AQD** (MUS-LOSS-EXCEPTION-FROZEN); historical rows are marked `legacy_loss_exception` and settle on their stored terms.
Partner capital is an ownership interest, not a debt: no business receivable arises from an ordinary loss; no capital or profit guarantee (MUS-NO-CAPITAL-GUARANTEE, MUS-NO-PROFIT-GUARANTEE, MUS-NO-FACE-VALUE-BUYBACK).
Governance fields: management rights, partner authority, withdrawal restrictions, asset ownership, exit, dissolution, breach, dispute resolution.
