# Wakalah specification
Every appointment records: **Muwakkil** (principal), **Wakil**, **Wakalah role**, **scope**, **authority**, underlying aqd/project, appointment date, acceptance, status, evidence, revocation.
Lifecycle: `PROPOSED → PENDING_WAKIL_ACCEPTANCE → PENDING_SHARIAH_REVIEW → CONFIRMED`; `REJECTED` (by the Wakil or the reviewer); `CONFIRMED → REVOKED`. Selecting a Wakil is never an effective appointment.
Who the Muwakkil is **is a structural decision and is never assumed** (WAK-PRINCIPAL-ROLE): the appointment requires an explicit principal chosen from the options the aqd definition allows, and the choice is Shariah-reviewed.
Mudarabah and Musharakah define **no generic Wakil role**; a Wakalah there needs a specific defined structure first.
Appointment-level Shariah review is on by default (`shariah.wakalah_requires_review`); turning it off needs governance approval and is audited.
