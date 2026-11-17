# Build phases

| Phase | Delivers | Owner reviews or decides |
|---|---|---|
| **0. Design documents** (done) | Architecture, module standard, schema, permission model, feature checklist, open decisions | Approve the schema and module rules |
| **1. Skeleton** (done) | Laravel on PHP 8.3, Filament admin, MySQL setup, control database and per-clinic databases, login, users, roles and permissions, audit log, settings, branches, number counters, module manager with dependency checks, one demo module, test pipeline with MySQL, setup README | Confirm the module manager behaves as wanted |
| **2. Front desk and billing** (done) | Patients, referral doctors, service catalog, orders, invoices, payments, partial payments, dues, refunds, discounts, receipts, daily cash closing | Check billing rules against a real clinic |
| **3. Laboratory and reports** (done) | Lab catalog with reference ranges and packages, samples with barcode labels, result entry, flags, verification, amendments, PDF and printed reports, templates, sign-off | Check reports against real report formats |
| **4. Owner basics** (done) | Owner dashboard, basic reports, expenses, accounts, referral commission, profit reports, data export | Decide whether the accounting is enough |
| **5. Performance and safety pass** (built, awaiting review) | Two to three years of simulated data, query timing and fixes, backup and restore test, security review | Provide a MySQL server for testing |
| **6. Pilot** | Install at one real clinic, collect feedback, fix issues | Legal review done first. Run real work and report problems |
| **7. Communication and payments** (messaging built ahead of the pilot at the owner's request; payments and WhatsApp wait for a provider) | Built: SMS and email (bill confirmation, report ready with a secure link, due reminders), message log, wording, retries. Not built: online payment gateway, WhatsApp | Choose the SMS provider, the payment gateway, and whether WhatsApp is worth its cost |
| **8. Subscription layer** | Plans, module entitlements, license and trial dates, platform billing | Set editions and pricing |
| **9. Inventory and people** (built: 9a to 9d; polish and review next) | 9a items, suppliers, purchases, stock on hand (built). 9b stock use per test, manual issue and wastage, stock count, alerts, reports (built). 9c staff and attendance (built). 9d advances, salary run, payslip (built) | Confirm with pilot clinics |
| **10. Patient portal and imaging reports** | Online report access; imaging report templates and printing | Decide with pilot data |
| **11. Machine integration and multi-branch** | Local bridge agent and analyzer connectors; branches and branch reports | Choose analyzer models |
| **12. Signup and provisioning** | Automatic clinic setup on the hosting platform | After there are paying customers |

## Changes to a finished phase

- **Counter billing screen** (Phase 2 follow-up, from the owner's own
  layout): one compact page for patient, tests, discount, and payment. No
  VAT, no per-test discount, no booth, and commission stays with Finance.
  The old order form was removed; its address opens the billing screen.
- **Polish of Phases 0 to 7** (after an inconsistency review): one timezone
  rule, full-width screens, one text size, short default numbers for new
  clinics, the supplies table in the data export, doctor code and delivery
  time on the order page, logo on the receipt, babies' age in months and
  days, documents brought in line with the code. Not done: the MySQL test
  run (skipped at the owner's request).

## How every phase ends

1. All tests pass.
2. A short summary states what was built, what was tested, and what could
   not be verified.
3. The work is committed on the development branch.
4. The owner reviews it before the next phase starts.
