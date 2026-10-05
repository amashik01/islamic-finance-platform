# Mudarabah specification
Parties: **Rabb-ul-Mal** (capital provider) and **Mudarib** (entrepreneur / working partner). The Mudarib is *not* called an agent unless a separate Wakalah exists.
Capital (**Ras-ul-Mal**): BDT only, delivered to the Mudarib through an explicit deployment event (rule MUD-CAPITAL-DELIVERY).
Profit: two ratios of **actual** profit totalling 100%; fixed amounts, % of capital and guarantees are rejected (MUD-PROFIT-RATIO, MUD-NO-CAPITAL-PROFIT-GUARANTEE).
Loss: ordinary loss reduces the capital provider's capital; no Mudarib debt (MUD-LOSS-RABB). Mudarib liability only for fault/breach/negligence/misconduct, through a staged recovery lifecycle: SUSPECTED → UNDER_REVIEW → FAULT_ESTABLISHED → LIABILITY_RECOGNIZED → recovery. A recovery is compensation, **not** investment profit, and is not shared as profit.
Interim remittances are advances/interim proceeds, final result on liquidation (MUD-ADVANCES).
Validation lives in `app/Domain/Aqd/MudarabahAqd.php`; the form guidance and rule codes come from the same definition.
