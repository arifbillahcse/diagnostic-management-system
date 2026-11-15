# Build phases

| Phase | Delivers | Owner reviews or decides |
|---|---|---|
| **0. Design documents** (done) | Architecture, module standard, schema, permission model, feature checklist, open decisions | Approve the schema and module rules |
| **1. Skeleton** (built, awaiting review) | Laravel on PHP 8.3, Filament admin, MySQL setup, control database and per-clinic databases, login, users, roles and permissions, audit log, settings, branches, number counters, module manager with dependency checks, one demo module, test pipeline with MySQL, setup README | Confirm the module manager behaves as wanted |
| **2. Front desk and billing** | Patients, referral doctors, service catalog, orders, invoices, payments, partial payments, dues, refunds, discounts, receipts, daily cash closing | Check billing rules against a real clinic |
| **3. Laboratory and reports** | Lab catalog with reference ranges and packages, samples with barcode labels, result entry, flags, verification, amendments, PDF and printed reports, templates, sign-off | Check reports against real report formats |
| **4. Owner basics** | Owner dashboard, basic reports, expenses, accounts, referral commission, profit reports, data export | Decide whether the accounting is enough |
| **5. Performance and safety pass** | Two to three years of simulated data, query timing and fixes, backup and restore test, security review | Provide a MySQL server for testing |
| **6. Pilot** | Install at one real clinic, collect feedback, fix issues | Legal review done first. Run real work and report problems |
| **7. Communication and payments** | SMS, email, WhatsApp report delivery; online payment gateway | Choose providers |
| **8. Subscription layer** | Plans, module entitlements, license and trial dates, platform billing | Set editions and pricing |
| **9. Inventory and people** | Reagents and consumables, purchases, suppliers, stock alerts; staff, attendance, salary basics | Confirm with pilot clinics |
| **10. Patient portal and imaging reports** | Online report access; imaging report templates and printing | Decide with pilot data |
| **11. Machine integration and multi-branch** | Local bridge agent and analyzer connectors; branches and branch reports | Choose analyzer models |
| **12. Signup and provisioning** | Automatic clinic setup on the hosting platform | After there are paying customers |

## How every phase ends

1. All tests pass.
2. A short summary states what was built, what was tested, and what could
   not be verified.
3. The work is committed on the development branch.
4. The owner reviews it before the next phase starts.
