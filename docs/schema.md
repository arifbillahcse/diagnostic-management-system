# Schema design

Status: draft for approval (Phase 0).

This document covers the control database, the core tables in each clinic
database, and the three default modules: front desk, laboratory, and reports.
Finance is outlined. The remaining modules are designed in their own phases
and follow the same conventions.

Column lists show the columns that matter for design. Standard columns
(section 1) are not repeated.

## 1. Conventions

| Topic | Rule |
|---|---|
| Engine and charset | InnoDB, `utf8mb4`, strict SQL mode |
| Primary key | `id` `BIGINT UNSIGNED AUTO_INCREMENT`. Used for joins only |
| Public ID | `public_id` `CHAR(26)` ULID, unique. Used in URLs and APIs so record counts and neighbours cannot be guessed |
| Business number | Human-readable number such as an invoice number. Comes from the counter table (section 6). Unique |
| Money | `DECIMAL(12,2)`. Rounded half up to two places, in one place in code |
| Lab values | `DECIMAL(18,6)` for numeric results, plus a text column for non-numeric results |
| Time | `TIMESTAMP` in UTC. Shown in `Asia/Dhaka` |
| Standard columns | `created_at`, `updated_at`, `created_by`, `updated_by` on every table that users write to |
| Branch | `branch_id` on every operational table, from the first migration |
| Soft delete | `deleted_at` only on master data (services, doctors, users). Never on money or results |
| Foreign keys | Always declared. `RESTRICT` on delete for clinical and financial data |
| Status columns | Short strings backed by PHP enums. Changed only through the state machines in section 7 |
| Snapshots | Prices, names, units, and reference ranges are copied onto transaction rows at creation |

## 2. Control database

Holds no patient data.

| Table | Key columns | Purpose |
|---|---|---|
| `clinics` | `public_id`, `name`, `status` (trial, active, suspended, closed), `db_name`, `db_host`, `db_username`, `db_password` (encrypted), `timezone`, `plan_id` | One row per clinic |
| `clinic_domains` | `clinic_id`, `domain` (unique), `is_primary` | Finds the clinic for a request |
| `plans` | `key`, `name`, `is_active` | Starter, Professional, Enterprise, or whatever is decided later |
| `plan_modules` | `plan_id`, `module_key` | Modules a plan includes |
| `clinic_modules` | `clinic_id`, `module_key`, `source` (plan, addon, trial), `expires_at` | Entitlement overrides and add-ons |
| `platform_users` | `name`, `email`, `password`, `role` | Platform staff. Separate from clinic users |
| `support_sessions` | `platform_user_id`, `clinic_id`, `reason`, `started_at`, `ended_at` | Record of every support entry into a clinic |
| `provisioning_log` | `clinic_id`, `step`, `status`, `message` | What happened when a clinic was set up or migrated |

Subscription billing tables (subscriptions, platform invoices, payments) are
designed in Phase 8.

**Built so far (Phase 1):** `clinics`, `clinic_domains`, `plans`,
`plan_modules`, `clinic_modules`, `provisioning_log`, plus the framework's
`sessions`, `cache`, and `jobs` tables. `platform_users` and
`support_sessions` arrive with the platform admin screens. In `plan_modules`,
the module key `*` means every module.

## 3. Clinic database: core

### Users and access

| Table | Key columns | Notes |
|---|---|---|
| `users` | `public_id`, `name`, `email` (unique), `phone`, `password`, `is_active`, `default_branch_id`, `last_login_at`, `deleted_at` | |
| `roles` | `key` (unique), `name`, `is_system` | System roles cannot be deleted |
| `permissions` | `key` (unique), `module_key`, `description` | Key format `module.resource.action` |
| `role_permissions` | `role_id`, `permission_id` | |
| `user_roles` | `user_id`, `role_id`, `branch_id` (nullable) | A null branch means all branches |
| `role_limits` | `role_id`, `limit_key`, `value` | For example maximum discount percent |

Phase 1 decides whether these tables come from a permissions package or are
written in-house. The design above is what either must provide.

### Clinic setup

| Table | Key columns | Notes |
|---|---|---|
| `branches` | `public_id`, `code` (unique, short), `name`, `address`, `phone`, `is_active` | One default row until multi-branch is enabled |
| `settings` | `group`, `key`, `value` (JSON), `is_encrypted` | Unique on (`group`, `key`). Cached |
| `modules` | `module_key` (unique), `version`, `status` (installed, enabled, disabled), `enabled_at`, `disabled_at` | Per-clinic module state |
| `counters` | See section 6 | |
| `files` | `public_id`, `disk`, `path`, `mime`, `size`, `owner_type`, `owner_id` | Logos, signatures, generated documents |

**Built so far (Phase 1):** every table above except `files`, which arrives
with document rendering in Phase 3. `counters.branch_id` uses `0` for a
clinic-wide counter, not null, so the unique index can guarantee one row per
counter.

### Patients

Built in Phase 2.

| Column | Type | Notes |
|---|---|---|
| `patient_code` | string, unique | From the counter table |
| `name` | string | |
| `sex` | enum: male, female, other | Needed for reference ranges |
| `date_of_birth` | date | Always stored. See below |
| `dob_is_estimated` | boolean | True when the patient gave an age, not a birth date |
| `phone` | string | As entered |
| `phone_normalized` | string | Digits only, with country code. Indexed |
| `address`, `email`, `blood_group`, `notes` | | Optional |
| `registered_branch_id` | FK | |

**Age handling.** Many patients give an age and not a birth date. The system
then stores a date of birth calculated from that age on the registration
date, and marks it as estimated. Reference ranges always use age in days on
the day the sample is collected, so a stored date gives the right range on
later visits too.

**Duplicate check.** On registration, the system looks for existing patients
with the same normalized phone. One phone number often serves a whole family,
so the system shows the matches and lets the receptionist choose. It never
merges patients automatically. A merge is a separate, permissioned, audited
action.

### Audit log

| Column | Notes |
|---|---|
| `id` | |
| `occurred_at` | |
| `actor_type`, `actor_id` | Clinic user, platform user in a support session, or system |
| `action` | For example `payment.recorded`, `result.verified` |
| `entity_type`, `entity_id` | What was changed |
| `branch_id` | |
| `before`, `after` | JSON. Only the changed fields. Secrets never included |
| `ip`, `user_agent` | |
| `context` | JSON, for example the reason for a refund or an amendment |

Rules:

- Append-only. The application has no update or delete path for this table,
  and the model refuses both.
- Granting the clinic's MySQL user only `INSERT` and `SELECT` on this table
  is planned for the Phase 5 security pass. Today that user has full rights
  on its own database, which migrations need.
- `actor_name` is stored with each entry, so the trail stays readable if a
  user is renamed or removed.
- Written in the same transaction as the change it records.
- When it grows large, partition by month. At the expected volume this is
  not needed in the first years.

## 4. Module: front desk (`fd_`)

### Catalog

| Table | Key columns | Notes |
|---|---|---|
| `fd_service_categories` | `name`, `sort_order` | Haematology, Biochemistry, Ultrasound, and so on |
| `fd_services` | `public_id`, `code` (unique), `name`, `type` (lab_test, imaging, package, other), `category_id`, `price`, `is_active`, `deleted_at` | Everything a clinic can bill for |
| `fd_package_items` | `package_service_id`, `service_id` | Tests inside a package |
| `fd_referral_doctors` | `public_id`, `name`, `degree`, `phone`, `is_active`, `deleted_at` | Commission rules belong to finance |

### Orders and billing

| Table | Key columns | Notes |
|---|---|---|
| `fd_orders` | `public_id`, `order_no` (unique), `patient_id`, `referral_doctor_id`, `branch_id`, `status`, `ordered_at`, `notes` | One visit |
| `fd_order_items` | `order_id`, `service_id`, `service_name`, `unit_price`, `discount_amount`, `net_amount`, `package_item_of` (nullable), `status` | Name and price are snapshots |
| `fd_invoices` | `public_id`, `invoice_no` (unique), `order_id`, `patient_id`, `branch_id`, `subtotal`, `discount_total`, `total`, `paid_total`, `refunded_total`, `due_amount`, `status`, `issued_at` | One invoice per order in version 1 |
| `fd_invoice_lines` | `invoice_id`, `order_item_id` (nullable), `kind` (charge, discount, adjustment), `description`, `amount` | Never edited after the invoice is issued |
| `fd_payments` | `public_id`, `receipt_no` (unique), `invoice_id`, `amount`, `method` (cash, bkash, nagad, card, bank), `reference`, `received_by`, `cash_session_id`, `received_at`, `idempotency_key` (unique, nullable) | Append-only |
| `fd_refunds` | `public_id`, `refund_no` (unique), `invoice_id`, `payment_id` (nullable), `amount`, `method`, `reason`, `approved_by`, `refunded_by`, `cash_session_id`, `refunded_at` | Append-only |
| `fd_cash_sessions` | `public_id`, `branch_id`, `opened_by`, `opened_at`, `opening_float`, `closed_by`, `closed_at`, `expected_cash`, `counted_cash`, `difference`, `status`, `notes` | Daily cash closing |
| `fd_daily_summaries` | `summary_date`, `branch_id`, `orders_count`, `patients_count`, `billed_total`, `collected_total`, `refunded_total`, `due_total` | Unique on (`summary_date`, `branch_id`). Feeds the dashboard |

**Invoice totals.** The totals on `fd_invoices` are stored for speed, but the
truth is the lines, payments, and refunds:

```
total         = sum of invoice lines
paid_total    = sum of payments
refunded_total= sum of refunds
due_amount    = total - paid_total + refunded_total
```

Every action that adds a line, payment, or refund locks the invoice row,
inserts the new row, recalculates the totals, and writes the audit entry in
one transaction. A scheduled check recomputes the totals from the rows and
reports any invoice where the stored and computed values differ.

**Changes after an invoice is issued.** Lines are not edited. Adding or
cancelling a test adds an adjustment line, positive or negative. This rule
must be confirmed against how real clinics handle changes (see
open-decisions.md).

**Constraints.** Amounts are never negative except adjustment and discount
lines. `paid_total - refunded_total` can never exceed `total`. MySQL check
constraints enforce what they can, and the actions enforce the rest.

## 5. Module: laboratory (`lab_`)

### Catalog extension

| Table | Key columns | Notes |
|---|---|---|
| `lab_sample_types` | `name`, `container`, `label_copies` | Blood (EDTA), serum, urine, and so on |
| `lab_tests` | `service_id` (unique FK), `sample_type_id`, `department`, `turnaround_hours`, `method`, `report_notes` | Lab details for a billable service |
| `lab_analytes` | `code` (unique), `name`, `result_type` (numeric, option, text, long_text, calculated), `unit`, `decimals`, `options` (JSON), `is_active` | One measured value, for example haemoglobin |
| `lab_test_analytes` | `lab_test_id`, `analyte_id`, `sort_order`, `group_label` | Which analytes a test reports, and in what order |
| `lab_reference_ranges` | `analyte_id`, `sex` (any, male, female), `age_min_days`, `age_max_days`, `low`, `high`, `critical_low`, `critical_high`, `text_range`, `effective_from`, `effective_to` | See lookup rule below |

`calculated` is reserved for values derived from other analytes. It is not
built in the first release.

**Reference range lookup.** For an analyte, a patient, and a collection date:

1. Work out the patient's age in days on the collection date.
2. Select ranges for the analyte where the age falls between `age_min_days`
   and `age_max_days` and the collection date falls inside the effective
   dates.
3. Prefer a range for the patient's sex over a range for `any`.
4. If more than one remains, the catalog is misconfigured. The system uses
   the narrowest age band and flags the analyte for the lab manager.
5. If none matches, the result is saved with no range and no flag, and the
   report shows the range as not available.

The chosen range is copied onto the result row. Editing the catalog later
never changes an existing result or report.

### Samples and results

| Table | Key columns | Notes |
|---|---|---|
| `lab_samples` | `public_id`, `accession_no` (unique), `order_id`, `patient_id`, `sample_type_id`, `branch_id`, `status`, `collected_by`, `collected_at`, `received_by`, `received_at`, `rejection_reason` | One tube or container. The accession number is the barcode |
| `lab_sample_events` | `sample_id`, `event`, `actor_id`, `occurred_at`, `note` | Full history of each sample |
| `lab_work_items` | `public_id`, `order_item_id` (unique FK), `lab_test_id`, `sample_id`, `patient_id`, `branch_id`, `status`, `entered_by`, `entered_at`, `verified_by`, `verified_at`, `released_at`, `version`, `lock_version` | One test to be performed. Carries the result state machine |
| `lab_results` | `work_item_id`, `analyte_id`, `value_numeric`, `value_text`, `unit`, `ref_low`, `ref_high`, `ref_text`, `flag` (normal, low, high, critical_low, critical_high, abnormal, none), `comment` | Unique on (`work_item_id`, `analyte_id`). Current values |
| `lab_result_versions` | `work_item_id`, `version`, `snapshot` (JSON of all result rows), `reason`, `changed_by`, `changed_at` | Written at every verification and every amendment |

Design points:

- **Verification is per test, not per value.** A full blood count is
  verified as a whole, so the state lives on `lab_work_items`.
- **`lab_work_items` exists so laboratory does not add columns to
  `fd_order_items`.** That keeps the module boundary clean.
- **`lock_version`** increases on every save. A save that carries an old
  value is refused, so two people editing the same test cannot silently
  overwrite each other.
- **After verification, values are locked.** A change needs the amend
  action, a reason, and the amend permission. The previous values stay in
  `lab_result_versions`, and the amended report says it is amended.
- **Flags are calculated by the system** from the copied range. A user can
  add a comment but cannot change a flag.

**Barcode.** The accession number is printed as Code 128. The format and
length are settled in Phase 3 after testing with the clinic's label printer
and scanner.

## 6. Number counters

| Column | Notes |
|---|---|
| `counter_key` | `patient`, `order`, `invoice`, `receipt`, `refund`, `accession` |
| `branch_id` | Counters are per branch |
| `period` | For example `2611` for November 2026, or empty for a counter that never resets |
| `next_value` | |

Unique on (`counter_key`, `branch_id`, `period`).

How a number is issued, inside the transaction that creates the record:

1. One statement creates the counter row or advances it
   (`INSERT ... ON DUPLICATE KEY UPDATE next_value = next_value + 1`). It
   takes an exclusive row lock at once, so simultaneous callers queue and
   cannot deadlock, including on the very first number of a period.
2. Read `next_value` back; the issued number is that value minus one.
3. Build the number from the clinic's format, for example
   `INV-{branch}-{yymm}-{00001}`.
4. Insert the record. The unique index on the number is the final guard.

Because the counter is updated in the same transaction as the record, a
failed save rolls the counter back and leaves no gap. The lock makes
simultaneous saves wait in line for a few milliseconds, which is fine at this
volume. Formats are a clinic setting. Whether invoice numbers must be
gapless for tax or audit purposes in Bangladesh is a question for the
accountant or lawyer (see open-decisions.md).

## 7. State machines

A status changes only through an action that checks the transition is
allowed, checks the permission, and writes the audit entry.

### Order (`fd_orders.status`)

```
draft -> confirmed -> in_progress -> completed
draft -> cancelled
confirmed -> cancelled        (only if nothing is collected or paid, or after refund)
```

### Invoice (`fd_invoices.status`)

```
issued -> partially_paid -> paid
issued -> paid
paid or partially_paid -> refunded   (fully refunded)
issued -> void                       (no payments, with reason)
```

The status is derived from the totals. It is stored for filtering.

### Cash session

```
open -> closed
```

One open session per user per branch. Payments in cash need an open session.

### Sample (`lab_samples.status`)

```
pending -> collected -> received -> in_process -> completed
collected or received -> rejected -> pending   (recollect)
```

### Lab work item (`lab_work_items.status`)

```
awaiting_sample -> ready -> entered -> verified -> released
entered -> ready                 (verifier sends back, with reason)
released -> amending -> verified -> released    (version + 1)
any state before verified -> cancelled
```

Rules:

- `entered -> verified` needs the verify permission. By default the verifier
  must be a different user from the one who entered the result. A clinic
  with a single qualified person can switch that rule off; the change is
  audited.
- `released -> amending` needs the amend permission and a reason.
- A report can be printed for the patient only in `released`.

## 8. Module: finance (`fin_`), outline

Detailed in Phase 4.

| Table | Purpose |
|---|---|
| `fin_accounts` | Cash, bank, bKash, and Nagad accounts |
| `fin_account_entries` | Money in and out of each account. Append-only. Linked to the payment, refund, expense, or payout that caused it |
| `fin_expense_categories`, `fin_expenses` | Expenses with category, account, and receipt file |
| `fin_commission_rules` | Per doctor, per service, or per category; percent or fixed |
| `fin_commission_entries` | Commission earned per order item, calculated when the invoice is paid |
| `fin_commission_payouts` | Payments to doctors |

Whether this grows into a full double-entry ledger is an open decision.

## 9. Indexes for the busiest queries

| Query | Index |
|---|---|
| Find patient by phone | `patients (phone_normalized)` |
| Find patient by code | `patients (patient_code)` unique |
| Find patient by name | `patients (name)`, searched by prefix |
| Today's orders for a branch | `fd_orders (branch_id, ordered_at)` |
| A patient's history | `fd_orders (patient_id, ordered_at)` |
| Orders by status | `fd_orders (branch_id, status, ordered_at)` |
| Invoice by number | `fd_invoices (invoice_no)` unique |
| Due list | `fd_invoices (branch_id, status, issued_at)` |
| Payments in a cash session | `fd_payments (cash_session_id)` |
| Collections by date | `fd_payments (received_at)` |
| Scan a barcode | `lab_samples (accession_no)` unique |
| Lab worklist | `lab_work_items (branch_id, status, created_at)` |
| Results for a test | `lab_results (work_item_id, analyte_id)` unique |
| A patient's past results | `lab_work_items (patient_id, released_at)` |
| History of one record | `audit_logs (entity_type, entity_id, occurred_at)` |
| A user's activity | `audit_logs (actor_id, occurred_at)` |

Search rules:

- Name search matches from the start of the name, so the index is used. A
  search for text in the middle of a name is not offered in version 1. If
  clinics need it, a full-text index is added and measured.
- Lists use cursor pagination on (`sort column`, `id`).
- Every index is checked with `EXPLAIN` on the Phase 5 data set. Indexes
  that are not used are removed, because each one slows down writes.

## 10. Expected size

Estimates for one clinic with 500 patients and 1,000 tests a day. They
assume about 8 analytes per test and about 15 audit entries per order, which
are guesses to replace with pilot figures.

| Table | Rows per year |
|---|---|
| `fd_orders`, `fd_invoices` | about 180,000 each |
| `fd_order_items`, `lab_work_items` | about 365,000 each |
| `fd_payments` | about 200,000 |
| `lab_results` | about 3 million |
| `audit_logs` | about 3 million |

These sizes are well within what MySQL handles on a small server when the
indexes above are in place. Partitioning or archiving is not needed in the
first years. The first candidates, if it ever is, are `audit_logs` and
`lab_result_versions`, by month.

## 11. What must be verified on MySQL

The development environment has no MySQL server, and SQLite behaves
differently in these areas. They are tested on MySQL in Phase 1 and Phase 5:

- Row locking with `SELECT ... FOR UPDATE` for counters and invoices
- Check constraints
- Decimal arithmetic and rounding
- Query plans for every index in section 9
- Insert-only privileges on `audit_logs`
- Migration time across many clinic databases
