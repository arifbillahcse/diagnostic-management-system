# Pilot kit

Everything for running DMS at the first real clinic. Phase 6 in
[docs/phases.md](../phases.md).

| File | For | Use |
|---|---|---|
| [server-runbook.md](server-runbook.md) | You | Setting up the production server, once |
| [go-live-checklist.md](go-live-checklist.md) | You | Before and on the first day at the clinic |
| [daily-check.md](daily-check.md) | You and the clinic owner | Every evening of the parallel run |
| [cheat-sheet-reception.md](cheat-sheet-reception.md) | Reception staff | Print one per counter |
| [cheat-sheet-lab.md](cheat-sheet-lab.md) | Lab staff | Print one for the lab |
| [cheat-sheet-owner.md](cheat-sheet-owner.md) | Clinic owner | Print one |
| [pilot-agreement-draft.md](pilot-agreement-draft.md) | Your lawyer, then the clinic | A draft only: a lawyer must review it |
| [issue-log.csv](issue-log.csv) | You | Every problem the clinic reports |
| [services-template.csv](services-template.csv) | The clinic | Their test list and prices, for `frontdesk:import` |
| [doctors-template.csv](doctors-template.csv) | The clinic | Their referral doctors, for `frontdesk:import` |

## Timeline

| Week | Work |
|---|---|
| 0 | Server ready, legal pages reviewed, clinic chosen, their price list and doctors collected |
| 1 | Clinic set up, layouts matched to their papers, training, parallel run starts |
| 2 | Parallel run (old way and DMS side by side), daily check, fixes |
| 3 | DMS only, fixes |
| 4 | Review against the success measures in go-live-checklist.md; decide the price |

## Problem priorities

| Priority | Meaning | Fix |
|---|---|---|
| Blocker | Cannot bill, cannot print a report | Same day |
| Wrong | Wrong amount, wrong range, wrong report | 1 to 2 days |
| Annoying | Slow, too many clicks, layout | Weekly batch |
| Wish | New feature | Decide later, often a paid extra |

After each update on the server: `php artisan migrate --force`, then
`php artisan clinic:migrate`, then `php artisan optimize`.
