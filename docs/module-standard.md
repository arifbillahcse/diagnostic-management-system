# Module standard

Status: draft for approval (Phase 0).

This document defines what a module is, how it is loaded, how a clinic
switches it on or off, and how modules extend the system without changing
each other.

## 1. Core and modules

**Core** is always on and cannot be disabled. It holds only what every clinic
and every module needs:

- Clinic resolution and database switching
- Users, roles, permissions
- Audit log
- Settings and clinic profile
- Branches (one default branch until the multi-branch module is enabled)
- Patients
- Number counters
- Money handling
- Document rendering (PDF and print, clinic letterhead)
- Notifications inside the application
- The dashboard frame
- The module manager

**A module** is a self-contained package under `modules/`. Everything else in
the product is a module, including front desk and laboratory. Those two are
enabled by default, but they follow the same rules as any other module.

## 2. Module folder

```
modules/Finance/
  module.json              Manifest
  src/
    FinanceModule.php      Entry class: registers everything the module adds
    Models/
    Actions/
    States/
    Policies/
    Filament/              Resources, pages, widgets
    Events/
    Listeners/
    Jobs/
  database/migrations/     Tables owned by this module
  resources/views/
  lang/en/
  tests/
```

## 3. Manifest

`module.json` describes the module. The loader reads it before any module
code runs.

```json
{
  "key": "finance",
  "name": "Finance",
  "description": "Expenses, accounts, referral commission, profit reports.",
  "version": "1.0.0",
  "core": ">=1.0.0",
  "requires": ["frontdesk"],
  "suggests": ["inventory"],
  "table_prefix": "fin_",
  "default_enabled": false,
  "entry": "Modules\\Finance\\FinanceModule"
}
```

| Field | Meaning |
|---|---|
| `key` | Unique, lowercase, never changes after release |
| `version` | Semantic version of the module |
| `core` | Lowest core version the module works with, written as `>=1.0.0`. Other constraint forms are not supported yet |
| `requires` | Modules that must be enabled first. Hard dependency |
| `suggests` | Modules it can work with if they are enabled. Soft dependency |
| `table_prefix` | Prefix for every table the module owns |
| `default_enabled` | Whether a new clinic starts with it on |
| `entry` | The class that registers the module's parts |

## 4. Lifecycle

```
discovered -> installed -> enabled <-> disabled -> purged
```

| State | Meaning |
|---|---|
| Discovered | The code is present and the manifest is valid |
| Installed | The module's migrations have run in this clinic's database |
| Enabled | Screens, permissions, listeners, and jobs are active for this clinic |
| Disabled | Hidden and inactive. **All data is kept** |
| Purged | Module tables for this clinic are dropped. Separate, confirmed, audited action, offered only after an export |

Two things decide whether a clinic can use a module:

1. **Entitlement**, in the control database: does the clinic's plan include
   the module, and has it expired? Set by the platform owner.
2. **Enabled state**, in the clinic database: has the clinic's admin switched
   it on? Set by the clinic.

A module is active only when both are true.

### Enable rules

A module can be enabled when:

- the clinic is entitled to it,
- every module in `requires` is already enabled,
- its `core` version constraint is met,
- its migrations have run.

### Disable rules

A module can be disabled when no enabled module lists it in `requires`. If
one does, the screen names the dependent modules and refuses.

Disabling:

- removes the module's menu items, screens, and dashboard widgets,
- stops its listeners, scheduled tasks, and queued jobs from doing work,
- keeps its permissions assigned to roles, so re-enabling restores access,
- keeps every row of its data,
- writes an audit entry with who did it and when.

## 5. Loading

1. **Discover.** Read every `modules/*/module.json`.
2. **Validate.** Check required fields, unique keys, unique table prefixes,
   and that every name in `requires` exists.
3. **Order.** Build a dependency graph and sort it so each module loads after
   the modules it requires (topological sort). A cycle is an error.
4. **Register.** Call each entry class in that order.
5. **Gate per clinic.** After the clinic is resolved, read the enabled set
   (cached) and activate only those modules for the request.

Steps 1 to 3 are also a command, `modules:validate`, that runs in the test
pipeline. A broken manifest or a cycle fails the build, so it never reaches
production.

**Why gating happens at request time:** all clinics share one code base, and
each clinic has a different set of enabled modules. So module code is always
loaded, but every entry point checks whether the module is enabled for the
current clinic:

| Entry point | How it is gated |
|---|---|
| Screens and menu items | Always registered, because all clinics share the code. Each screen uses the `GatesModuleAccess` trait, which hides it from the menu and refuses access when the module is disabled or the permission is missing |
| Routes | Middleware returns "not found" for a disabled module |
| Event listeners | A shared base class skips the listener when its module is disabled |
| Scheduled tasks and jobs | Check enabled state after resolving the clinic, before doing work |
| Permissions | A permission of a disabled module always evaluates to "denied" |

## 6. Extension points

A module adds to the system only through these points. It never edits core
files or another module's files.

| Extension point | What a module can add |
|---|---|
| Navigation | Menu groups and items |
| Screens | Filament resources, pages, and relation tabs |
| Dashboard | Widgets, each with its own permission |
| Record tabs | Extra tabs or panels on shared records such as the patient page |
| Permissions | Its own permission keys, default role grants, and default role limits (for example a maximum discount percent) |
| Settings | Its own settings page and setting keys |
| Events | Listeners for events from core and from the modules it requires or suggests |
| Documents | Printable document types that use the shared renderer |
| Notification channels | For example SMS or WhatsApp |
| Report registry | Named reports that appear on the reports screen |
| Scheduled tasks | Recurring jobs |
| Export | Its tables, so a full data export includes module data |

### Events the code raises

Every event is raised after its database change has been committed.

| Event | Raised by | Listeners today |
|---|---|---|
| `PatientRegistered` | Core | None yet (a welcome message could use it) |
| `ClinicSwitched` | Core | Core: forgets per-clinic values held in memory |
| `ModuleEnabled`, `ModuleDisabled` | Core | Core: cache clearing. Finance: catches up when switched on |
| `OrderPlaced` | Front desk | Laboratory (creates lab work), Messaging (bill confirmation) |
| `OrderItemAdded` | Front desk | Laboratory (lab work for the new test), Finance (commission) |
| `OrderItemCancelling` | Front desk | Laboratory (refuses when the test already has results) |
| `OrderItemCancelled`, `OrderCancelled` | Front desk | Laboratory (cancels lab work), Finance (commission) |
| `InvoiceIssued` | Front desk | None yet |
| `PaymentReceived`, `RefundIssued` | Front desk | Finance (account entries and commission) |
| `CashSessionClosed` | Front desk | None yet |
| `SampleCollected`, `SampleRejected` | Laboratory | None yet |
| `ResultsReleased` | Laboratory | Messaging ("report ready" once the whole order is released) |
| `ResultsAmended` | Laboratory | None yet (an amended-report message could use it) |

Reports raises no events: printing is recorded in the audit trail.

Event names and payloads are a public contract. A change that breaks a
listener needs a new major version of the module that raises the event.

## 7. Boundary rules

1. **Core never references a module.** Not a class, not a table.
2. **A module uses only core, its `requires`, and its `suggests`.** For a
   suggested module it must first check that the module is enabled.
3. **A module owns its tables.** Every table name starts with its prefix.
   Another module reads or writes those tables only through the owning
   module's actions and query classes, never directly.
4. **Foreign keys point downward only:** from a module to core or to a module
   it requires. Never from core to a module, and never between unrelated
   modules.
5. **A module never adds columns to a table it does not own.** If the
   laboratory needs extra data about an order item, it keeps its own table
   that points at the order item.
6. **Migrations are additive.** A released migration is never edited. Changes
   are new migrations.
7. **A module's listeners must be safe to run twice** and must not block the
   action that raised the event. Slow reactions go on the queue.
8. **Each module ships tests** for its actions, its permissions, and its
   enable and disable behavior.

## 8. Module list and dependencies

| Key | Name | Prefix | Requires | Suggests | Default |
|---|---|---|---|---|---|
| `frontdesk` | Front desk and billing | `fd_` | | | On |
| `laboratory` | Laboratory | `lab_` | `frontdesk` | | On |
| `reports` | Lab reports | `rep_` | `laboratory` | | On |
| `finance` | Finance | `fin_` | `frontdesk` | `inventory` | Off |
| `owner` | Owner dashboard and analytics | `own_` | `frontdesk` | `laboratory`, `finance` | Off |
| `messaging` | SMS and email | `msg_` | `reports` | `imaging` | Off |
| `inventory` | Inventory | `inv_` | `frontdesk` | `laboratory`, `finance` | Off |
| `people` | Staff and attendance | `ppl_` | | `finance` | Off |
| `payments` | Online payments | `pay_` | `frontdesk` | | Off |
| `portal` | Patient portal | `por_` | `reports` | `imaging` | Off |
| `imaging` | Imaging reports | `img_` | `frontdesk` | `reports`, `laboratory` | Off |
| `analyzers` | Lab machine integration | `anz_` | `laboratory` | | Off |
| `multibranch` | Multi-branch | `mb_` | | | Off |

Notes:

- **The billable catalog lives in front desk** as "services". A lab test, an
  imaging study, and a package are all services. Laboratory attaches its
  analytes and reference ranges to a service. This lets imaging plug into
  billing later without depending on laboratory.
- **Document rendering lives in core**, because invoices, receipts, lab
  reports, and imaging reports all need it.
- **Branches live in core.** The `multibranch` module only unlocks creating
  more than one branch and adds branch reports.
- **Subscription and licensing is not a clinic module.** It belongs to the
  platform side and uses the control database (Phase 8).

## 9. Versioning and releases

- Core and every module carry their own semantic version.
- A module's migrations run per clinic on deploy, for every clinic where the
  module is installed.
- A release note lists, per module, what changed and whether any setting or
  permission was added.

## 10. Checklist for a new module

- [ ] Manifest valid, key and table prefix unique
- [ ] `modules:validate` passes
- [ ] All tables prefixed, with a branch ID where the data is operational
- [ ] Every action checks a permission and writes an audit entry
- [ ] Listeners are idempotent and gated on the module being enabled
- [ ] Tests for actions, permissions, enable, and disable
- [ ] Core test suite still passes with the module disabled
- [ ] Module tables registered for data export
- [ ] Settings page and default role grants defined
