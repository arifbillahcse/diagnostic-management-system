# Architecture and conventions

Status: draft for approval (Phase 0).

## 1. What the system is

DMS is a hosted, paid management system for small and mid-size diagnostic
centers in Bangladesh. Each clinic gets its own address and its own database.
The system is a small fixed core with modules that a clinic can switch on or
off.

**In scope:** front desk and billing, laboratory workflow, reports, finance,
inventory, staff, communication, owner dashboard, patient portal, online
payments, imaging reports, analyzer integration, multi-branch.

**Out of scope:** PACS image viewing, hospital inpatient features (beds, OT,
IPD), prescriptions and OPD, pharmacy, native mobile apps, free tier, ads.

## 2. Stack

| Layer | Choice | Notes |
|---|---|---|
| Language | PHP 8.3 (minimum) | Code must not use features newer than 8.3 |
| Framework | Laravel 13 | Pinned in `composer.lock` |
| Admin UI | Filament 5 (with Livewire 4) | Pinned in `composer.lock` |
| Database | MySQL 8 | `utf8mb4`, InnoDB, strict SQL mode |
| Cache and queue | Database driver first, Redis when Phase 7 adds SMS and heavy jobs | Queue jobs must not assume a driver |
| Web server | Nginx with PHP-FPM | |
| PDF | dompdf | Pure PHP, so it runs on any hosting with no extra programs. Remote files and embedded PHP are switched off |
| Barcodes | picqer/php-barcode-generator | Code 128 on sample labels |
| Tests | PHPUnit | Runs on SQLite (fast) and on MySQL |
| Analyzer bridge | Python agent on the clinic's lab PC (Phase 11) | Talks to the server over an authenticated HTTPS API |

Every third-party package added later is recorded in section 11 with its
purpose and license. The product is proprietary, so packages with copyleft
licenses that would force the source open (GPL, AGPL) are not allowed in the
application code.

## 3. Tenancy

Two kinds of database:

- **Control database.** One for the whole platform. It holds clinics,
  domains, plans, subscriptions, and which modules each clinic is entitled
  to. It holds no patient data.
- **Clinic database.** One per clinic. It holds everything that clinic
  enters.

Request flow:

1. The request arrives on a clinic domain, for example
   `clinic-name.example.com`.
2. Middleware looks the domain up in the control database (cached).
3. If the clinic is active, the application switches its database
   connection, cache prefix, file storage path, and queue context to that
   clinic.
4. If the domain is unknown or the clinic is suspended, the request stops
   with a clear page. No clinic connection is opened.

Rules:

- No query runs against a clinic database before step 3 succeeds.
- Queued jobs and scheduled tasks carry the clinic ID and repeat step 3
  before doing any work.
- Each clinic database has its own MySQL user with rights only on that
  database. Credentials are stored encrypted in the control database.
- Migrations are split: control migrations, core clinic migrations, and
  module migrations. A deploy runs clinic migrations for every clinic and
  records the result per clinic.
- Uploaded files (logo, signatures) live on a private disk under
  `clinics/<clinic id>/`. They have no web address at all; documents embed
  them directly, and only from the current clinic's own folder. Generated
  PDFs are not stored: a report is produced from the database each time.

Tenancy is implemented in-house in `app/Core/Tenancy` (decided in Phase 1).
It is about ten small classes, has no third-party dependency to keep
compatible, and does exactly what this section describes.

**Sessions are tied to a clinic.** User IDs repeat between clinic databases,
so a session cookie copied to another clinic's address could otherwise sign
in as a different person. The clinic is written into the session at login
and checked on every request; a mismatch signs the user out.

**Long-running PHP servers.** The code assumes one PHP process per request
(PHP-FPM). Filament and some core services keep per-request state in memory.
Do not run the application under Laravel Octane, Swoole, or FrankenPHP worker
mode without first reviewing that state.

**Why one database per clinic:** a clinic's data can be exported, backed up,
restored, or deleted without touching another clinic; a query bug cannot leak
data between clinics; and a large clinic can move to its own server. The cost
is that migrations run once per clinic, which the deploy tooling handles.

## 4. Code structure

```
app/                     Core application code
  Core/
    Tenancy/             Clinic resolution and connection switching
    Modules/             Module registry, manifest loader, enable and disable
    Auth/                Users, roles, permissions
    Audit/               Audit log writer
    Numbering/           Counter service for business numbers
    Money/               Money value object and rounding
    Documents/           Shared PDF and print rendering, clinic letterhead
    Patients/            Patient records (shared by all modules)
    Settings/            Clinic settings
modules/                 One folder per module (see module-standard.md)
  FrontDesk/
  Laboratory/
  Reports/
  ...
database/
  migrations/control/    Control database
  migrations/clinic/     Core tables in each clinic database
docs/                    Design documents
tests/                   Core tests (modules keep their own tests)
```

Layers inside core and inside each module:

| Layer | Holds | Rule |
|---|---|---|
| Models | Eloquent models, relations, casts | No business rules beyond simple accessors |
| Actions | One class per business operation, for example `RecordPayment` | All business rules live here. Each runs in a transaction and writes its audit entry |
| States | Status enums and allowed transitions | The only place a status may change |
| Policies | Permission checks | Called by every action and every screen |
| Filament | Resources, pages, widgets | Screens call actions. They contain no business rules |
| Events and listeners | Things that happened, and reactions to them | The way modules talk to each other |
| Jobs | Queued work | Idempotent: safe to run twice |

## 5. Conventions

- `declare(strict_types=1);` in every PHP file. Typed properties, parameters,
  and return types everywhere.
- PSR-12 formatting, enforced by Laravel Pint.
- Static analysis with Larastan, level agreed in Phase 1.
- Names: models are singular (`Invoice`), tables are plural snake case with a
  module prefix (`fd_invoices`), actions are verb-first (`VerifyResult`),
  events are past tense (`InvoicePaid`), permissions are
  `module.resource.action`.
- Status values are backed PHP enums, stored as short strings, never as
  numbers.
- Dates and times are stored in UTC and displayed in the clinic's own
  timezone (`clinics.timezone`, `Asia/Dhaka` for every clinic so far). A date
  with no time (date of birth, report date) is stored as a `DATE`.
- No raw SQL in screens. Reports that need hand-written SQL keep it in a
  query class with a test.
- User-facing text is plain English, kept in language files so wording can
  change without touching logic.

## 6. Data rules

Full detail is in [schema.md](schema.md). The short version:

- Two IDs per record: a numeric ID for joins and a random public ID for URLs.
- Money in `DECIMAL(12,2)` with one rounding rule.
- No hard deletes of clinical or financial data.
- Business numbers from a locked counter table.
- Reference ranges and prices are copied onto the record when it is created,
  so later catalog edits cannot change old invoices or reports.
- A branch ID on every operational table from the first migration, so
  multi-branch can be switched on later without rewriting tables.

## 7. Security

| Area | Rule |
|---|---|
| Passwords | Laravel's default hashing. Minimum length and breach check on set |
| Login | Rate limited. Lockout after repeated failures. Optional two-factor login for owner and admin roles (Phase 5) |
| Sessions | Secure, HTTP-only cookies. Idle timeout set per clinic |
| Authorization | Policy check in every action. Screens also hide what the user cannot do |
| Audit | Append-only log for money, results, permissions, settings, module state, logins, and exports |
| Secrets | SMS and gateway keys stored encrypted. Never written to logs or audit entries |
| Files | Type and size checks on upload. Stored outside the web root |
| Support access | Platform staff enter a clinic only through a logged support session that the clinic owner can see |
| Transport | HTTPS only, with HSTS |
| Backups | Daily encrypted off-site backup per clinic, with a restore test before the pilot and on a schedule after |
| Dependencies | `composer audit` in the test pipeline |

A lawyer must review the customer terms and the data-handling rules before
any real patient data is entered. That review is not part of this document.

## 8. Testing

- Every action has tests for the normal case, the refusal cases, and the
  permission check.
- Mandatory areas: billing math, rounding, payment and refund balances,
  number generation under simultaneous requests, result state transitions,
  reference range lookup, module enable and disable rules, clinic isolation.
- Unit tests may run on SQLite for speed. **Tests that depend on locking,
  check constraints, or decimal behavior must run on MySQL**, because SQLite
  behaves differently. Phase 1 sets up a pipeline with a MySQL service.
- Each module runs its own tests, and the core suite must pass with every
  optional module disabled.
- Phase 5 loads two to three years of simulated data and times the key
  queries.

## 9. Performance approach

- Lists use cursor pagination and never load a full table.
- Dashboards read daily summary rows, not raw transactions.
- PDFs in bulk, exports, SMS, and email run on the queue.
- Settings, permissions, and module state are cached per clinic and cleared
  on change.
- Every list and report query is checked with `EXPLAIN` against the Phase 5
  data set.

## 10. Git workflow

- Work happens on the development branch. No pull request is opened unless
  the owner asks.
- One commit per finished unit of work, with a message that says what
  changed and why.
- A phase is finished when its tests pass, its summary is written, and the
  owner has reviewed it.

## 11. Third-party packages

Direct dependencies only. `composer licenses` lists everything installed.

| Package | Purpose | License | Added in |
|---|---|---|---|
| `laravel/framework` | Application framework | MIT | Phase 1 |
| `filament/filament` | Admin screens (brings Livewire) | MIT | Phase 1 |
| `laravel/tinker` | Command-line console for support work | MIT | Phase 1 |
| `dompdf/dompdf` | PDF lab reports | LGPL-2.1 | Phase 3 |
| `picqer/php-barcode-generator` | Barcodes on sample labels | LGPL-3.0-or-later | Phase 3 |

**About the two LGPL libraries.** The LGPL allows a proprietary product to
use a library as long as the library itself stays replaceable and any change
made to the library is published. Both are used unmodified through Composer,
which meets that. This is my reading, not legal advice; include it in the
lawyer's review. mPDF was ruled out because it is GPL-2.0-only, and a
Chromium-based engine because it needs a browser installed on every server.

Roles and permissions, tenancy, the audit log, and the module system are
written in-house, so they add no packages. The roles design needs
branch-scoped assignments and module gating, which did not fit the common
permissions package cleanly.

Three transitive packages (`nette/*`) are offered under BSD-3-Clause or GPL
at the user's choice. They are used under BSD-3-Clause.

## Conventions on screens and paper

- **Money**: "৳" on screens; "Tk" on printed invoices, receipts, and SMS
  (printers and phones show it reliably); "Taka" when an amount is written in
  words. All amounts have two decimals.
- **Time**: always shown in the clinic's own timezone (`clinics.timezone`).
  Scheduled tasks run on the installation's default timezone
  (`dms.clinics.default_timezone`), because they run once for every clinic.
- **Age**: years for adults and children ("25 Y"); months and days for a baby
  under one year ("3 M 12 D").
- **Width**: every screen uses the full width of the window; text is one
  compact size (about 13 px) everywhere.
