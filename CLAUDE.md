# Project notes for AI coding tools

Read the documents in `docs/` before changing anything. They are the source
of truth. If code and a document disagree, stop and ask which one is right.

## Fixed decisions

- PHP 8.3 minimum, Laravel, Filament, MySQL 8 (`utf8mb4`, strict mode).
- English only. No translation layer beyond Laravel's default language files.
- Fully paid, proprietary product. No free tier, ads, or watermark logic.
- One control database plus one database per clinic.
- Small fixed core plus modules. See `docs/module-standard.md`.

## Rules that must not be broken

1. **Core never depends on a module.** A module depends only on core and on
   the modules named in its manifest.
2. **No business logic in Filament resources, pages, or controllers.** Put it
   in Action classes so it can be tested and reused.
3. **Money is exact.** Use the Money value object and `DECIMAL` columns.
   Never use floats for money.
4. **Financial and clinical rows are never deleted or edited in place.**
   Corrections are new rows: reversals, adjustments, or result versions.
5. **Every change to money, results, permissions, or module state writes an
   audit log entry** in the same database transaction.
6. **State changes go through the state machines** in `docs/schema.md`. Do
   not set a status column directly.
7. **Business numbers come from the counter service.** Never compute
   `MAX(number) + 1`.
8. **Every action checks a permission.** Hiding a button is not access
   control.
9. **Every feature ships with tests.** Billing math, state transitions,
   permissions, and number generation are mandatory test areas.
10. **Clinic data never crosses clinics.** No query may run without a
    resolved clinic connection.

## Working method

- Build one phase at a time, in the order in `docs/phases.md`.
- Run the full test suite before every commit.
- Report what was tested and what could not be verified.
- Do not add packages without recording the reason and the license in
  `docs/architecture.md`.
- Out of scope unless the owner asks: PACS image viewing, hospital inpatient
  features, prescriptions and OPD, pharmacy, native mobile apps.
