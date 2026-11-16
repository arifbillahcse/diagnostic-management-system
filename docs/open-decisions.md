# Open decisions

Status: working document.

Each decision has a recommendation, what happens if nobody decides, and the
phase by which it must be settled. "Default" is what will be built if the
decision is still open when that phase starts.

## Needed before Phase 1

| # | Decision | Recommendation | Default |
|---|---|---|---|
| 1 | **Offline behavior** when the clinic's internet drops | Hosted only for the first release, with a printed downtime procedure. Revisit after the pilot shows how often it happens | Hosted only. Counters are per branch, so a local install with sync stays possible later |

## Built in Phase 2 with these defaults

The defaults below are now what the system does. Each still needs checking
against a real clinic, and each can be changed.

| # | Decision | Recommendation | Default |
|---|---|---|---|
| 4 | **Changes after an invoice is issued** (add or cancel a test) | Adjustment lines on the same invoice, never editing issued lines. Confirm with a real clinic | Adjustment lines |
| 5 | **Number formats** for patient, invoice, receipt | Configurable per clinic, monthly reset for invoices | `INV-{branch}-{yymm}-{00001}` |
| 6 | **Must invoice numbers be gapless** for tax or audit purposes? | Ask an accountant | Gapless per branch and month, which the counter design already gives |
| 7 | **Discount limits** per role | Take the figures from clinic interviews | Receptionist up to 10 percent, above that needs override |
| 8 | **Refund approval** by a second person | On by default, owner can switch off | On |

## Built in Phase 3 with these defaults

Decision 12 is settled: dompdf, for the reasons in architecture.md. Decision
11 still needs a real label printer and scanner.

| # | Decision | Recommendation | Default |
|---|---|---|---|
| 9 | **Verifier must differ from the person who entered the result** | On by default, owner can switch off for a one-person lab | On |
| 10 | **Report layout**: pre-printed letterhead or full-page print | Support both, chosen per clinic | Both |
| 11 | **Barcode format and label size** | Test with a real label printer and scanner | Code 128 |
| 12 | **PDF engine** | Compare candidates on real report layouts | Decided by the comparison |

## Built in Phase 4 with these defaults

Both defaults below are what the system does now: simple accounts, and
commission as a percent per doctor with per-test overrides, earned when the
invoice is fully paid.

| # | Decision | Recommendation | Default |
|---|---|---|---|
| 13 | **Accounting depth**: simple accounts and expenses, or a full double-entry ledger | Simple accounts first. Add export to accounting software if owners ask | Simple accounts |
| 14 | **Commission rules**: per doctor, per test, per category, on billed or collected amount | Take from clinic interviews | Percent per doctor on collected amount, with per-service overrides |

## Needed before the pilot (Phase 6)

| # | Decision | Recommendation | Default |
|---|---|---|---|
| 15 | **Legal review** of customer terms, data handling, and liability | A lawyer in Bangladesh must review before any real patient data is entered | None. The pilot does not start without it |
| 16 | **Support access**: does the owner approve each support session? | Owner approval required, with an emergency option that notifies the owner | Approval required |
| 17 | **Backup retention and location** | Daily, encrypted, off-site, 30 days, with a tested restore | As recommended |
| 18 | **Production server and MySQL for testing** | A staging server on the hosting platform, used from Phase 1 for MySQL-only tests | None. Phase 5 cannot finish without MySQL |

## Later

| # | Decision | Needed by | Recommendation |
|---|---|---|---|
| 19 | Product name, domain, and trademark check | Before the marketing site | Check the trademark registry as well as domain availability |
| 20 | Editions and pricing | Phase 8 | After competitor quotes and a real cost per clinic |
| 21 | SMS, email, and WhatsApp providers | Phase 7 | Compare prices and terms at that time |
| 22 | Payment gateway | Phase 7 | Compare fees, settlement time, and onboarding requirements |
| 23 | Analyzer integration timing and first models | After clinic interviews | Move earlier if most clinics rely on automated analyzers |
| 24 | Imaging reports in the first release or later | After clinic interviews | Later, unless interviews show most centers need it |
| 25 | Licensed local installs | After the pilot | Only if clinics refuse hosted, and priced for the support cost |
| 26 | Two-factor login for owners and admins | Phase 5 | Optional per clinic, recommended for owners |

## Decided

| Decision | Outcome |
|---|---|
| Business model | Fully paid. No free tier, ads, or watermark |
| License | Proprietary |
| Stack | PHP 8.3, Laravel 13, Filament 5, MySQL 8 |
| Tenancy implementation | In-house (`app/Core/Tenancy`) |
| Permissions implementation | In-house (`app/Core/Auth`), because roles are branch-scoped and gated by module |
| Language | English only |
| Tenancy | Control database plus one database per clinic |
| Structure | Small core plus switchable modules |
| Out of scope | PACS, hospital inpatient features, prescriptions and OPD, pharmacy, native mobile apps |
