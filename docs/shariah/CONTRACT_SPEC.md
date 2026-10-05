# Contract template engine (as implemented)

**Status: implemented, subject to qualified Shariah review. Nothing here is a fatwa, legal advice or a Shariah certification. Template wording is UNVERIFIED — SCHOLAR REVIEW REQUIRED and legal review is required before real use.**

## Data model
`ContractTemplate` (code, aqd) → `ContractTemplateVersion` (version, `content_hash`, Shariah review status/reviewer/date) → `ContractClause` (ordered, with rule codes and optional condition).
Templates: `MUDARABAH-MASTER`, `MUDARABAH-PARTICIPATION`, `MUSHARAKAH-MASTER`, `MUSHARAKAH-PARTICIPATION`, `MURABAHA-MASTER`, `MURABAHA-SALE`, `WAKALAH-APPOINTMENT`.
A template version cannot generate an agreement until a Shariah reviewer has approved that version (`ContractTemplateService::usableVersion`). The seeded text is *not* approved; approval is a recorded human decision.

`ContractDocument` — a generated agreement: stored `content`, `document_hash` (SHA-256 of the full text, what is signed), `terms_hash` (SHA-256 of the same text with the review block, reference, version and date neutralised — **what a Shariah reviewer approves**), `terms_snapshot`, the template version, a version number (counts executed versions) and status `DRAFT → PENDING_SIGNATURE → EXECUTED → SUPERSEDED` (or `CANCELLED`).
`ContractSignature` — signer, role, method (typed name + password re-confirmation), the **document hash signed**, consent version and the SHA-256 of the exact consent text, identity check (KYC state at signing), IP and user agent (staff-visible only).
`ContractAmendment` — see below.

## Generation and review
1. Submit → a `DRAFT` master is generated and a `Submitted` Shariah review is created (`aqd.generated`, `aqd.submitted_for_shariah_review`).
2. The reviewer approves the draft's `terms_hash` (`ShariahReview.reviewed_terms_hash`). Approval needs `shariah.review`.
3. The execution version is generated from the same terms with the completed review block; it must have the **same** `terms_hash` or the approval is void (`aqd.shariah_approved`). It is `PENDING_SIGNATURE`.
4. The business signs → `EXECUTED` (`aqd.consent_given`, `aqd.signed`, `aqd.executed`). A draft cannot be signed. Changing terms after review requires a new review.
5. Investors: a `PARTICIPATION` document for exactly their amount; once signed it backs **exactly one** investment (`investments.participation_document_id` unique; the document records the investment that consumed it).

## Immutability and tamper detection
Model guard on content/hash/terms/amount/party/version/reference; DB triggers (MySQL and SQLite) refuse edits and deletes of `EXECUTED`/`SUPERSEDED` documents and any update/delete of signatures. Reconciliation recomputes every hash and checks every signature against it. A document whose hash does not match cannot be signed or used for activation.

## Amendments
An executed master is never edited. `ContractAmendmentService`: request (reason + exact term changes, Shariah text guard applied) → Shariah review (legal review recorded as required once investors are bound) → a new version is generated, reviewed on its own `terms_hash`, and signed; only when it is **executed** is the old version `SUPERSEDED` (kept, hash intact). `aqd.amended`, `aqd.superseded`. Limits: only master agreements are amended; participation agreements are replaced, not amended. Whether an amendment may bind existing investors without their fresh consent is **REQUIRES QUALIFIED SHARIAH REVIEW** and legal review.

## Access control
`ContractDocumentPolicy`: parties and authorised staff only. An investor's participation agreement is never visible to the business or to other investors; drafts are not visible to investors; Wakalah documents only to the Wakil, the business and staff. First view is audited (`aqd.viewed`). The same policy guards the HTML view (`/agreements/{reference}`) and the PDF (`/agreements/{reference}/pdf`, dompdf of the stored text and hash).

## Not implemented / limits
Electronic signature here is a typed name with password re-confirmation and recorded consent evidence; its legal validity in Bangladesh is a legal question (see OPEN_SCHOLAR_QUESTIONS). No bilingual rendering; the PDF is plain text.
