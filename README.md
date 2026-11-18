# Diagnostic Management System

A paid, module-based management system for diagnostic centers in Bangladesh:
front desk and billing, laboratory workflow, reports, finance, owner tools,
messaging, inventory, and staff with payroll. The product name is not decided yet, so the documents use the working
title **DMS**.

## Status

**Phases 1 to 5, 7, 9 and 10, and steps 11a to 11c and 11d's bridge program, are built.** Phases 6 (pilot) and 8 are set aside for
later; Phases 10 and 11 are not started.

- **Phase 1, skeleton:** one database per clinic, sign-in, users, roles and
  permissions, the audit log, clinic settings, branches, number counters,
  exact money handling, and the module system.
- **Phase 2, front desk and billing:** patients, the service catalog with
  packages, referral doctors, orders, invoices, discounts with role limits,
  part payments and dues, refunds with approval, cancelling and adding tests
  after issue, daily cash closing, printable invoices and receipts, and
  today's figures on the dashboard.

- **Phase 3, laboratory and reports:** lab tests with analytes and reference
  ranges by sex and age, samples with barcode labels, result entry with
  automatic flags, verification by a second person, amendments that keep
  every version, and PDF reports with letterhead and signatories.

- **Phase 4, owner basics:** accounts for cash, bank, and mobile money that
  fill themselves from payments; expenses; referral commission with payouts;
  a profit report; an owner dashboard with this month's figures and a
  collections chart; sales reports by day, test, doctor, and cashier; and a
  full export of the clinic's data.

- **Phase 5, performance and safety:** every screen timed against two years
  of simulated data and the slow ones fixed; backups with a trial restore;
  two-step sign-in; database-level protection of the audit trail; and a
  security review.

- **Phase 7, messaging:** SMS and email to patients (report ready, bill
  receipt, due reminders) through any HTTP bulk SMS provider, with a test
  mode, editable wording, a message log, retries, and a stop list.

- **Phase 9, inventory and people** (two modules, both off until switched on):
  - **Inventory:** stock items, suppliers, purchases with batches and expiry,
    stock on hand at average cost. Stock is taken out automatically when a
    test is billed (stock use per test) and put back when it is cancelled;
    missing stock is listed and never blocks billing. Manual issue, waste,
    expired and stock counts; dashboard alerts, an expiry page, and the cost
    of stock per test.
  - **Staff and attendance:** staff records with salary history, a daily
    attendance sheet and a monthly grid, salary advances, a monthly salary
    run per branch (draft, approved, paid) with hand-typed deductions and
    advance recovery, A5 payslips, and Finance expenses for advances and
    salaries.

- **Phase 10a, imaging reports** (module, off until switched on): report
  templates per imaging test (with example sets to start from), phrases, a
  work list filled from the bills, writing from a template, signing by the
  reporting doctor, amendments kept as versions, and A4 printing on the
  clinic letterhead. Report layout and signatures are now shared by lab and
  imaging reports, under Administration.
- **Phase 10b, imaging delivery:** a Reports section on the order page with
  print buttons, lab results and imaging reports in one PDF, the "report
  ready" SMS waiting for imaging too (its link opens the combined file), and
  the unpaid-due print rule applied to imaging.
- **Phase 10c, patient portal** (module, off until switched on): patients
  open `<clinic domain>/reports`, enter the bill number and mobile number, see
  what is ready, and download their lab report, imaging reports, or both in
  one file. No account needed; tries are limited, every download is
  recorded, and the bill prints the portal address.
- **Phase 11a, multi-branch** (module, off until switched on): a branch
  switcher for staff in more than one branch, every list limited to the
  user's branches, each branch's own address and phone on printed papers,
  and branch figures in the owner's sales reports.
- **Phase 11b, collection centres:** a branch can send its samples to
  another branch's laboratory (Lab set-up, Collection centres). Samples are
  collected at the centre, sent in numbered transfers with a printed courier
  list, received at the lab, tested and verified there, and the report is
  printed back at the centre.
- **Phase 11c, lab machine integration, server side** (module, off until
  switched on): machines with their own keys, test code mapping, an API for
  the bridge program, ASTM and HL7 reading, matching by sample barcode,
  results saved as drafts for a person to submit and verify, a machine inbox,
  and a simulator.
- **Phase 11d, the lab bridge** (`bridge/`): a small Python program for the
  lab PC that talks to the analyzer over a serial cable or the network
  (ASTM or HL7), keeps every message in a local queue, and sends it to DMS
  with the machine's key. See [bridge/README.md](bridge/README.md). Still to
  do: a live test with the first real machine at a clinic.

Also built at the owner's request: the billing screen with live patient
search and a print step after save, A5 invoices, report banners, number
formats, cPanel support, sample data, and a temporary database updates page.

Next: the MySQL test before the first real clinic (see Tests), then the pilot.

![Owner dashboard](docs/screenshots/owner-dashboard.png)

A sample report is in
[docs/screenshots/sample-lab-report.pdf](docs/screenshots/sample-lab-report.pdf).

## Requirements

- PHP 8.3 with the `mbstring`, `intl`, `pdo_mysql`, `gd`, and `zip` extensions
- Composer
- MySQL 8

## Setup

1. Install dependencies and create the environment file.

   ```bash
   composer install
   ```

   ```bash
   cp .env.example .env
   ```

   ```bash
   php artisan key:generate
   ```

2. Create the control database and a MySQL user for the application. The
   user needs to create databases and users, because each clinic gets its
   own database and its own MySQL user.

   ```sql
   CREATE DATABASE dms_control CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'dms'@'127.0.0.1' IDENTIFIED BY 'choose-a-strong-password';
   GRANT ALL PRIVILEGES ON *.* TO 'dms'@'127.0.0.1' WITH GRANT OPTION;
   ```

   Put the same password in `DB_PASSWORD` in `.env`.

3. Create the control tables.

   ```bash
   php artisan migrate
   ```

4. Create a clinic. The domain is the address staff will open in the
   browser. For local work, use `localhost`.

   ```bash
   php artisan clinic:create "Demo Diagnostic Center" localhost --owner-name="Demo Owner" --owner-email=owner@example.com
   ```

   You are asked for the owner's password (at least 10 characters).

5. Start the development server and sign in at <http://localhost:8000>.

   ```bash
   php artisan serve
   ```

A request to an address that belongs to no clinic gets "not found". That
includes `127.0.0.1` unless you create a clinic with that domain.

## Shared hosting (cPanel)

A server you control (a VPS) is the recommended home, because the system
creates one database and one MySQL user per clinic by itself. Shared cPanel
hosting does not allow that, so there each clinic database is made in cPanel
first and handed to `clinic:create`.

1. **Files.** Put the project outside `public_html`, for example in
   `~/dms`. In cPanel, *Domains*, set the domain's document root to the
   project's `public` folder (`dms/public`). Nothing else may be reachable
   from the web.
2. **PHP.** Select PHP 8.3 or newer for the domain (*MultiPHP Manager*) and
   use the same version on the command line, for example
   `/opt/cpanel/ea-php84/root/usr/bin/php`.
3. **Databases.** In *MySQL Databases* create a control database (for
   example `account_dmscontrol`), one database per clinic (for example
   `account_clinic1`), and one MySQL user. Add the user to every one of these
   databases with *All privileges*.
4. **Environment.** In `.env`:

   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.example
   DB_HOST=localhost
   DB_DATABASE=account_dmscontrol
   DB_USERNAME=account_dmsuser
   DB_PASSWORD=the-password
   SESSION_SECURE_COOKIE=true
   ```

5. **Install.**

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

   ```bash
   php artisan key:generate
   ```

   ```bash
   php artisan migrate --force
   ```

6. **Create the clinic** in the empty database from step 3. The domain must
   be exactly the address typed in the browser, without `https://`.

   ```bash
   php artisan clinic:create "My Diagnostic Center" your-domain.example --owner-name="Owner" --owner-email=owner@example.com --database=account_clinic1
   ```

   To give the clinic a MySQL user of its own, add
   `--db-username=account_clinic1user`; you are asked for its password.
7. **Cron.** In *Cron Jobs* add, every minute:

   ```bash
   cd ~/dms && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >/dev/null 2>&1
   ```

   ```bash
   cd ~/dms && /opt/cpanel/ea-php84/root/usr/bin/php artisan queue:work --stop-when-empty --max-time=50 >/dev/null 2>&1
   ```

Limits on shared hosting: the database triggers that guard append-only
tables are usually refused (`clinic:create` says so; the application itself
still never edits those rows), and `clinic:backup` needs `mysqldump` and
`gzip` on the command line.

A "not found" page on every address means no clinic has that domain: run
`php artisan clinic:list`. A server error page means look in
`storage/logs/laravel.log`.

## The billing screen

**Front desk, New bill** is the counter screen. The patient is one full-width
section on top; below it sit the tests and the bill summary side by side.
Cards in the clinic's green, tight spacing, and everything visible at once on a
wide monitor (on a 1366 x 768 monitor the page scrolls a little).

- The cursor starts in **Find registered patient**. Type part of an ID, a
  name, or a mobile number: a list shows each match with its ID, name, age,
  sex, and mobile. Arrow keys and Enter (or a click) pick one; the details
  fill in and lock, and **Change patient** undoes it. If nobody matches,
  press Enter and what was typed becomes the new patient's mobile number (if
  it is a number) or name. A new patient is **registered when the bill is
  saved**; there is no separate step. The Mobile box shows the same list when
  the number is already registered.
- **Code + Enter** adds a test; the list and **Add** do the same. Supplies set
  up as add-ons (tubes, needle) appear in italics and are billed once.
- **Discount %** and **Discount Tk** follow each other; the box typed in last
  is the one the bill is made from. A reason is required, and the role's
  discount limit applies, as everywhere else.
- **Paid amount** starts equal to the net payable (**exact** goes back to it).
  Less leaves a due; more shows the **Return** (the change), and only the
  amount owed is recorded.
- **Ctrl+S** saves, **F2** starts a new bill.
- **After saving**, a panel offers the printing: **P** prints the invoice
  (patient copy and office copy, one per page), **L** prints the sample
  labels for the order (when the laboratory module is on), and **Esc** closes
  it. The Print button has the focus, so Enter prints at once. Printing is done
  in the background, so the counter stays on the billing screen. What happens
  after a save is the clinic's choice under **Front desk settings**: show
  these options (the default), print the invoice at once, or do nothing. The
  strip at the top always keeps the print links for reprinting.
- For printing without a dialog each time, set the browser to print silently
  to the invoice printer (Chrome: start it with `--kiosk-printing`); the
  software cannot do that by itself, because a web page may not choose a printer.
- Cash needs an open cash session (Cash sessions); the screen warns when
  there is none.

The old order form was replaced by this screen; its address (`/orders/create`) opens it.

## Number formats

Bill, receipt, patient, and other numbers follow a format the clinic sets
under **Administration, Settings, Number formats**. A new clinic starts with
short formats: bill `2611-00001`, order `ORD-2611-00001`, money receipt
`MR-2611-00001`, refund `RF-2611-00001`. A clinic that issued numbers before
these became the defaults keeps its long format (for example
`INV-MALIBUGH-2611-00034`) until it changes it here.

- `{branch}` is the branch code; `{yymm}` year and month; `{yymmdd}` the
  full date; `{yy}` `{yyyy}` `{mm}` `{dd}` the parts; and one counter such as
  `{00001}`, whose zeros set the width.
- The count restarts when the date part changes: monthly with `{yymm}`,
  daily with `{yymmdd}`, never with none.
- A new format applies from the next number. Numbers already issued never
  change. If the new format restarts at the same moments as the old one, the
  count carries on where it was, so a clinic moving from the long format to
  `{yymm}-{00001}` does not start again at 00001 in the same month.
- Leave a box empty to go back to the built-in format.

## Messaging (SMS and email)

The Messaging module is off by default. Switch it on under **Modules**, then
open **Messaging, Messaging settings**.

- **Test mode is the default.** With `SMS_DRIVER=log`, messages appear in the
  message log as sent but nothing reaches a phone. Use it to try the wording.
- **Real SMS** needs a bulk SMS provider whose API takes a web request. Set it
  once in `.env`, using the field names from the provider's own
  documentation:

  ```ini
  SMS_DRIVER=http
  SMS_HTTP_URL=https://your-provider.example/api/send
  SMS_HTTP_METHOD=post
  SMS_HTTP_PARAMS="api_key={api_key}&senderid={sender}&number={to}&message={message}"
  SMS_API_KEY=your-key
  SMS_SENDER_ID=your-sender-name
  SMS_HTTP_SUCCESS_TEXT=
  ```

  `{to}` is the number as `8801XXXXXXXXX`. If the provider's reply contains a
  fixed word on success, put it in `SMS_HTTP_SUCCESS_TEXT`. Run
  `php artisan config:clear`, then use **Send a test SMS** on the settings page.
- **Email** uses the `MAIL_*` settings in `.env`.
- Messages are sent by the queue, so the queue worker (or the cron line for
  shared hosting above) must be running. `php artisan schedule:run` must run
  for reminders and retries.

## Temporary: database updates from the portal

For the trial period only. After the software is updated on the server, the
database may need new columns or tables. This page lists them and applies
them, so the owner does not need a terminal.

1. In `.env` set `DMS_PORTAL_UPDATES=true`, then clear the cached settings:

   ```bash
   php artisan config:clear
   ```

2. Sign in as the owner. **Administration, Database updates** appears, with a
   number in orange when updates are waiting. Press **Apply updates**.

It is off unless that setting is true, and only the owner role sees it. It
updates the platform database and the clinic you are signed in to; another
clinic on the same server is updated with `php artisan clinic:migrate`. If an
update fails, the page says why; what was applied before it stays applied and
the rest is still waiting, so fix the cause and apply again. Take a backup
first when there is real patient data, because a failed structure change
cannot always be undone.

**To remove it after the trial:** set `DMS_PORTAL_UPDATES=false` (the page
disappears at once), or delete these and run `php artisan optimize:clear`:

- `app/Core/Tenancy/PendingUpdates.php`
- `app/Filament/Pages/PortalUpdates.php`
- `resources/views/filament/pages/portal-updates.blade.php`
- `tests/Feature/PortalUpdatesTest.php`
- the `portal_updates` block in `config/dms.php` and the
  `DMS_PORTAL_UPDATES` lines in `.env.example`

## Commands

| Command | What it does |
|---|---|
| `php artisan clinic:create <name> <domain>` | Creates a clinic: database, tables, default roles, owner. With `--database=` it uses an existing empty database |
| `php artisan plans:list` | Lists the plans (editions) with prices, branch limit, modules, and how many clinics use each |
| `php artisan plans:set <key> --monthly= --branches= --modules=` | Sets a plan's name, prices, branch limit, or modules. Yearly defaults to ten months |
| `php artisan clinic:plan <clinic id> <plan>` | Moves a clinic to another plan; modules outside it are switched off and their data kept |
| `php artisan clinic:licence <clinic id> [--extend-trial=14] [--paid-until=YYYY-MM-DD]` | Shows or sets a clinic's trial end and paid-until dates; its status follows at once |
| `php artisan clinic:licence-check` | Moves clinics to trial, active, overdue or read-only from their dates, and emails owners reminders. Scheduled daily |
| `php artisan clinic:list` | Lists clinics with their domains and databases |
| `php artisan clinic:migrate` | Runs core and module migrations for every clinic. Run on every deploy |
| `php artisan migrate` | Migrates the control database only |
| `php artisan modules:list` | Lists modules in load order |
| `php artisan modules:validate` | Checks every module manifest and dependency. Fails on any problem |
| `php artisan queue:work` | Runs queued jobs. Each job reconnects to the clinic it was created for |
| `php artisan schedule:run` | Runs scheduled tasks. Call it every minute from cron in production |
| `php artisan frontdesk:summarize` | Rebuilds the daily front desk summaries. Scheduled nightly |
| `php artisan inventory:check` | Checks every stock item's stored quantity and value against its movements and corrects any difference. Scheduled nightly |
| `php artisan messaging:due-reminders` | Reminds patients about unpaid bills, for clinics that switched reminders on. Scheduled daily at 10:30 |
| `php artisan messaging:retry` | Tries failed messages again (three tries in all). Scheduled every ten minutes |
| `php artisan laboratory:sample-data <clinic id>` | Loads a new clinic with sample tests, doctors, three staff accounts, patients, and ten days of orders, for trying the software. Never run it on a clinic with real data |
| `php artisan inventory:sample-data <clinic id>` | Loads sample stock items, a supplier, one purchase, and stock use rules for the sample tests. Turn on Inventory first; best after `laboratory:sample-data` |
| `php artisan imaging:sample-data <clinic id>` | Adds the example imaging templates to the sample ultrasound and X-ray tests and signs a few imaging reports. Turn on Imaging first; best after `laboratory:sample-data` |
| `php artisan people:sample-data <clinic id>` | Loads four sample staff, attendance for last month and this month, one advance, and a draft salary run for last month. Turn on Staff and attendance first |
| `php artisan imaging:sync-studies` | Adds imaging studies missing from recent open orders, for example orders billed before Imaging was switched on. Scheduled hourly |
| `php artisan analyzers:replay <clinic id> <machine> <file>` | Feeds recorded machine output to a machine as the bridge would, to test code mappings without the machine. Messages in the file are separated by lines of `---` |
| `php artisan laboratory:sync-work` | Creates laboratory work missing for recent orders. Scheduled hourly |
| `php artisan finance:sync` | Posts any payments and refunds missing from the accounts and updates commission. Scheduled nightly |
| `php artisan clinic:backup` | Backs up the control database and every clinic database. Add `--verify` to restore each into a scratch database and check it. Scheduled nightly once `BACKUP_PATH` is set |
| `php artisan clinic:verify-backup <file>` | Checks one backup file by restoring it |
| `php artisan platform:doctor` | Checks the installation's settings are safe for real patient data |
| `php artisan frontdesk:check-invoices` | Recalculates every invoice and reports any stored total that differs. Scheduled nightly, changes nothing |

## Tests

The fast run uses SQLite and needs no database server:

```bash
php artisan test
```

The same tests run on MySQL, plus a test that issues invoice numbers from
several processes at once, which only makes sense with real row locking:

```bash
DB_DRIVER=mysql DB_DATABASE=dmstest_control DB_USERNAME=dms DB_PASSWORD=your-password php artisan test
```

Create the `dmstest_control` database first. Test clinic databases are named
`dmstest_clinic_<n>` and are dropped after each test.

`.github/workflows/tests.yml` checks code style and runs both test runs on
every push; see the **Actions** tab on GitHub. The MySQL run only starts when
the SQLite run passes.

**MySQL status.** Day-to-day work is tested on SQLite. MySQL matters for what
SQLite cannot show: database-level protection of rows that must never change,
row locking (no duplicate numbers when two cashiers save at once), stricter
column and date rules, and migrations on existing data. Before the first
real clinic:

1. The MySQL run passes (on GitHub Actions or a server with MySQL 8).
2. All migrations apply to a copy of an existing clinic database.
3. Changing a stock movement, purchase line, salary history row, or audit
   entry directly in the database is refused.
4. Two cashiers saving at the same moment get different bill numbers.
5. `clinic:backup --verify` restores cleanly.

Until then, back up the database before applying updates to any clinic on
MySQL (cPanel included), and keep only test data there.

## How it is organised

```
app/Core/            The fixed core
  Tenancy/           Finds the clinic for a request and connects to its database
  Auth/              Users, roles, permissions, last-owner protection
  Modules/           Module registry, per-clinic on/off state, dependency checks
  Audit/             Append-only audit trail
  Numbering/         Counters and formats for invoice, patient, and other numbers
  Money/             Exact money arithmetic
  Settings/          Clinic settings
  Branches/          Branches, the current branch, and which branches a user works in
  Patients/          Patients, shared by every module
  Documents/         PDF rendering, letterhead, page layout, signatures, and the clinic's private files
  Export/            Full export of a clinic's data as CSV
app/Filament/        Admin screens for the core
modules/             One folder per module
  FrontDesk/         Catalog, orders, invoices, payments, refunds, cash sessions
  Laboratory/        Lab set-up, samples, result entry, verification, amendments
  Reports/           PDF lab reports, layout, and signatories
  Finance/           Accounts, expenses, referral commission, profit report
  Owner/             Owner dashboard and sales reports
  Messaging/         SMS and email to patients, message log, retries
  Inventory/         Stock items, suppliers, purchases, stock use per test
  People/            Staff, attendance, advances, salary runs, payslips
  Imaging/           Imaging report templates, writing, signing, printing
  Portal/            Patients' own report page: bill number and mobile number
  MultiBranch/       Branch switcher (core does the limits once it is on)
  Analyzers/         Lab machine results: API, ASTM and HL7 reading, matching, inbox
  Demo/              A tiny working example to copy
bridge/              The lab bridge program for the lab PC (Python, separate from the app)
database/migrations/
  control/           Tables of the control database
  clinic/            Core tables of every clinic database
tests/               Core tests. Each module keeps its own under modules/<Name>/tests
```

To add a module, copy `modules/Demo`, change its `module.json`, and follow
[docs/module-standard.md](docs/module-standard.md).

## Documents

| Document | What it covers |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Stack, tenancy, code structure, conventions, security, testing |
| [docs/module-standard.md](docs/module-standard.md) | How a module is described, loaded, enabled, disabled, and extended |
| [docs/schema.md](docs/schema.md) | Databases, tables, keys, indexes, number counters, state machines |
| [docs/permissions.md](docs/permissions.md) | Roles, permission keys, and the rules that protect money and results |
| [docs/feature-checklist.md](docs/feature-checklist.md) | Features compared with competitors, to fill in during clinic interviews |
| [docs/open-decisions.md](docs/open-decisions.md) | Decisions still open, with a recommendation and a deadline for each |
| [docs/performance.md](docs/performance.md) | Screen and action timings against two years of data, and what was changed |
| [docs/security-review.md](docs/security-review.md) | What was checked, what was fixed, what is still open, and a checklist before real patient data |
| [docs/phases.md](docs/phases.md) | The build phases and how each one ends |
