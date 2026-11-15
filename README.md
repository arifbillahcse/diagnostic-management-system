# Diagnostic Management System

A paid, module-based management system for diagnostic centers in Bangladesh:
front desk and billing, laboratory workflow, reports, finance, and owner
tools. The product name is not decided yet, so the documents use the working
title **DMS**.

## Status

**Phase 0: design.** This repository holds design documents only. No
application code exists yet. Phase 1 (the Laravel and Filament skeleton)
starts after these documents are approved.

## Documents

| Document | What it covers |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Stack, tenancy, code structure, conventions, security, testing |
| [docs/module-standard.md](docs/module-standard.md) | How a module is described, loaded, enabled, disabled, and extended |
| [docs/schema.md](docs/schema.md) | Databases, tables, keys, indexes, number counters, state machines |
| [docs/permissions.md](docs/permissions.md) | Roles, permission keys, and the rules that protect money and results |
| [docs/feature-checklist.md](docs/feature-checklist.md) | Features compared with competitors, to fill in during clinic interviews |
| [docs/open-decisions.md](docs/open-decisions.md) | Decisions still open, with a recommendation and a deadline for each |
| [docs/phases.md](docs/phases.md) | The build phases and how each one ends |

## Stack

PHP 8.3, Laravel, Filament, MySQL 8. English only. Exact Laravel and Filament
versions are pinned in Phase 1.
