# Permission model

Status: draft for approval (Phase 0).

## 1. Principles

1. **Permissions are checked in actions.** Hiding a menu item is for
   convenience. The check that counts happens when the action runs.
2. **Roles are bundles of permissions.** Screens and code check permissions,
   never role names, so a clinic can reshape its roles.
3. **Deny by default.** A new permission is granted to nobody until a role
   receives it.
4. **Module gating comes first.** A permission of a disabled module is always
   denied.
5. **Sensitive actions need a reason** and always write an audit entry:
   refunds, discounts above the limit, voiding an invoice, amending a
   result, merging patients, changing roles, switching modules.

## 2. Permission keys

Format: `module.resource.action`.

Examples:

```
core.users.manage
core.roles.manage
core.settings.manage
core.modules.manage
core.audit.view
core.patients.view
core.patients.create
core.patients.update
core.patients.merge
core.export.run

frontdesk.orders.view
frontdesk.orders.create
frontdesk.orders.cancel       (cancel an order with no money against it)
frontdesk.orders.adjust       (add or cancel tests on an issued invoice)
frontdesk.discounts.apply
frontdesk.discounts.override
frontdesk.payments.record
frontdesk.refunds.request
frontdesk.refunds.approve
frontdesk.cash.manage         (open and close own cash session)
frontdesk.cash.view_all
frontdesk.catalog.manage
frontdesk.settings.manage

laboratory.work.view
laboratory.samples.collect
laboratory.samples.receive
laboratory.samples.reject
laboratory.results.enter
laboratory.results.verify     (verifying also releases the result)
laboratory.results.amend
laboratory.catalog.manage
laboratory.settings.manage

finance.accounts.view
finance.accounts.manage
finance.expenses.record
finance.expenses.void
finance.commission.view
finance.commission.manage
finance.commission.pay
finance.reports.view

owner.dashboard.view
owner.reports.view

core.export.run

reports.reports.view
reports.reports.print
reports.reports.print_with_due
reports.settings.manage

messaging.log.view
messaging.messages.manage
messaging.templates.manage
messaging.settings.manage

inventory.stock.view
inventory.items.manage
inventory.purchases.record
inventory.purchases.void
inventory.purchases.pay
inventory.stock.adjust
people.staff.view
people.staff.manage
people.salary.manage
people.attendance.record
people.advances.manage
people.payroll.prepare
people.payroll.approve
people.payroll.pay
imaging.templates.manage
imaging.reports.write
imaging.reports.sign
imaging.reports.print
analyzers.machines.manage
analyzers.inbox.resolve
```

Each module declares its keys and its default role grants in its entry
class. The full list is generated from the code in Phase 1, so this document
does not need to be kept in step by hand.

## 3. Default roles

A new clinic starts with these roles. The clinic can edit them, copy them,
or create its own, except where noted.

| Role | For | Notes |
|---|---|---|
| Owner | The clinic owner | Has everything. Cannot be deleted. At least one active owner must always exist |
| Admin | A manager who runs the system | Everything except removing the owner |
| Receptionist | Front desk staff | Patients, orders, payments, own cash session |
| Lab technician | Sample and result staff | Samples, result entry |
| Verifier | Pathologist or consultant | Verify, release, amend |
| Accountant | Accounts staff | Invoices, dues, refunds approval, cash closing, finance |

## 4. Default grants

| Permission area | Owner | Admin | Receptionist | Lab technician | Verifier | Accountant |
|---|---|---|---|---|---|---|
| Users, roles, settings, modules | Yes | Yes | | | | |
| Audit log | Yes | Yes | | | | View |
| Patients: view, create, update | Yes | Yes | Yes | View | View | View |
| Patients: merge | Yes | Yes | | | | |
| Orders: create | Yes | Yes | Yes | | | |
| Orders: cancel | Yes | Yes | Before payment | | | Yes |
| Discounts within limit | Yes | Yes | Yes | | | Yes |
| Discounts above limit | Yes | Yes | | | | |
| Payments: record | Yes | Yes | Yes | | | Yes |
| Refunds: request | Yes | Yes | Yes | | | Yes |
| Refunds: approve | Yes | Yes | | | | Yes |
| Invoices: void | Yes | Yes | | | | |
| Cash: close own session | Yes | Yes | Yes | | | Yes |
| Cash: see all sessions | Yes | Yes | | | | Yes |
| Service catalog and prices | Yes | Yes | | | | |
| Samples: collect, receive, reject | Yes | Yes | Collect | Yes | Yes | |
| Results: enter | Yes | Yes | | Yes | Yes | |
| Results: verify and release | Yes | | | | Yes | |
| Results: amend | Yes | | | | Yes | |
| Lab catalog and reference ranges | Yes | Yes | | | Yes | |
| Reports: print released reports | Yes | Yes | Yes | Yes | Yes | |
| Data export | Yes | Yes | | | | |
| Messaging: see the message log | Yes | Yes | Yes | | | |
| Messaging: send again, stop a number, wording, settings | Yes | Yes | | | | |
| Number formats (part of clinic settings) | Yes | Yes | | | | |
| Inventory: see stock, purchases, movements | Yes | Yes | | Yes | Yes | Yes |
| Inventory: items and suppliers | Yes | Yes | | | | |
| Inventory: record and pay purchases | Yes | Yes | | | | Yes |
| Inventory: void a purchase | Yes | Yes | | | | |
| Inventory: take out stock, count, sort out shortfalls | Yes | Yes | | | | |
| Staff: see staff list and attendance | Yes | Yes | | | | Yes |
| Staff: add, edit, mark as left | Yes | Yes | | | | |
| Staff: see and change salaries | Yes | Yes | | | | Yes |
| Staff: record attendance | Yes | Yes | | | | |
| Staff: give and void advances | Yes | Yes | | | | Yes |
| Staff: prepare salary runs | Yes | Yes | | | | Yes |
| Staff: approve or cancel an approved salary run | Yes | Yes | | | | |
| Staff: mark a salary run paid | Yes | Yes | | | | Yes |
| Report layout and report signatures (clinic settings permission) | Yes | Yes | | | | |
| Imaging: templates, phrases, settings | Yes | Yes | | | | |
| Imaging: write and sign reports | Yes | Yes | | | Yes | |
| Imaging: see the list and print signed reports | Yes | Yes | Yes | | Yes | Yes |
| Lab machines: add machines, keys, test codes | Yes | Yes | | | | |
| Lab machines: inbox and matching by hand | Yes | Yes | | Yes | Yes | |
| Database updates page (temporary, trial only) | Yes | | | | | |

Two grants need the owner's attention:

- **Admin cannot verify results by default.** Verification is a clinical
  responsibility, so it is granted only to the Verifier and the Owner. An
  owner who is not medically qualified should not use it; the system cannot
  know who is qualified, so this is the clinic's responsibility and should
  be stated in the customer terms.
- **Receptionists can print only released reports.** No role can print an
  unverified result as a patient report.

## 5. Rules beyond simple permissions

| Rule | Default | Can the clinic change it? |
|---|---|---|
| The verifier must be a different user from the one who entered the result | On | Yes, by the owner. The change is audited |
| Maximum discount percent per role | Receptionist and accountant 10 percent. Admin and owner have no limit | The values are stored per role. A screen to edit them is not built yet |
| A refund needs approval by a second user with the approve permission | On | Yes, by the owner |
| A user records cash only into their own open cash session | On | No |
| A user sees only the branches they are assigned to | On | No |
| A closed cash session cannot be reopened | On | No. Corrections are new entries |
| Verified results cannot be edited | On | No. Use amend |

## 6. Branch scope

A role is assigned to a user for one branch or for all branches. Lists,
searches, and reports are filtered to the branches the user can see. Until
the multi-branch module is enabled, there is one branch and this has no
visible effect.

Patients are shared across branches of the same clinic, so a returning
patient is found at any branch. Orders, invoices, cash sessions, and samples
belong to one branch.

## 7. Platform staff

Platform staff (the vendor) are not clinic users and have no standing access
to clinic data.

- To help a clinic, a platform user opens a **support session** with a
  reason. It is recorded in the control database and in the clinic's audit
  log.
- The clinic owner can see every support session.
- Actions taken during a support session are recorded with the platform
  user as the actor.
- Whether support access needs the owner's approval each time is an open
  decision.

## 8. Tests required

- Each permission: allowed for a role that has it, refused for one that does
  not.
- Each rule in section 5.
- A disabled module's permissions are denied.
- A user limited to one branch cannot read or change another branch's
  records, including by typing a record's address directly.
- A user of one clinic cannot reach another clinic's data.
- The last active owner cannot be removed or deactivated.
