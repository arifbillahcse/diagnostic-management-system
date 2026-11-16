# Performance

Status: Phase 5. Measured on a development machine, not on production
hosting. Treat the numbers as a comparison of before and after, and as a
sign of which screens are cheap or costly. Repeat the measurement on the real
server before relying on them.

## How it was measured

- **Data:** one clinic loaded with two years at 500 orders a day:
  365,000 orders, 219,000 patients, 730,000 tests, 3.6 million results,
  1.1 million audit entries, 365,000 payments. About 2.6 GB on disk.
- **Database:** MySQL 8.0 with a 1 GB buffer pool, default durability
  settings, on 4 CPU cores. The database is larger than the buffer pool, as
  it would be on a small server.
- **Method:** `php artisan perf:measure` runs the queries behind each screen
  and each main action five times (actions three times) and reports the
  middle time. Times are for the database work and PHP, not the browser.
- **Not measured:** many people working at once, the network, page rendering
  in the browser, and a server with less memory than the data needs.

Both commands are in the repository, so the test can be repeated:

```bash
php artisan perf:seed <clinic id> --days=730 --orders=500
```

```bash
php artisan perf:measure <clinic id>
```

`perf:seed` writes rows straight to the database and must only be pointed
at a throwaway clinic.

## Results

Times in milliseconds. "Before" is the first measurement; "after" is with the
changes listed below.

| What | Before (ms) | Queries | After (ms) | Queries |
|---|---:|---:|---:|---:|
| Patient search by start of name | 1.0 | 1 | 0.8 | 1 |
| Patient search by phone | 0.4 | 1 | 0.4 | 1 |
| Patient search by code | 0.3 | 1 | 0.3 | 1 |
| Duplicate check by phone on registration | 0.4 | 1 | 0.3 | 1 |
| Patient list, first page | 19.7 | 2 | 15.9 | 2 |
| Order list, first page | 30.0 | 4 | 27.0 | 4 |
| Order search by order number | 1.9 | 1 | 1.7 | 1 |
| Order search by patient name | 8.1 | 1 | 5.8 | 1 |
| One patient's past orders | 0.3 | 1 | 1.1 | 1 |
| Open one order (items, invoice, payments) | 2.0 | 7 | 2.9 | 7 |
| Dues list, first page | 245.3 | 4 | 19.6 | 4 |
| Dashboard: today's figures | 4.8 | 6 | 5.0 | 6 |
| Dashboard: this month so far | 6.1 | 8 | 5.6 | 8 |
| Dashboard: 14-day collections chart | 5.7 | 8 | 5.4 | 8 |
| Dashboard: laboratory status | 393.0 | 4 | 1.1 | 4 |
| Dashboard: money held in accounts | 251.3 | 1 | 0.1 | 1 |
| Menu badges (run on every page) | 145.9 | 4 | 1.5 | 4 |
| Samples list, to collect | 64.9 | 5 | 3.7 | 5 |
| Scan a sample label | 0.4 | 1 | 0.3 | 1 |
| Worklist: to enter | 472.4 | 5 | 3.8 | 4 |
| Worklist: released, newest first | 616.2 | 5 | 3.7 | 4 |
| Reports ready to print, first page | 1,899.5 | 4 | 1.7 | 3 |
| One patient's past results | 1.9 | 2 | 2.3 | 2 |
| Audit log, first page | 77.3 | 2 | 0.5 | 1 |
| Audit history of one record | 0.3 | 1 | 0.3 | 1 |
| Sales report by day, 92 days | 7.2 | 8 | 7.3 | 8 |
| Sales report by test, 92 days | 1,171.3 | 1 | 255.2 | 2 |
| Sales report by doctor, 92 days | 1,350.5 | 1 | 369.8 | 2 |
| Sales report by cashier, 92 days | 72.6 | 1 | 71.8 | 1 |
| Profit report, one year | 686.0 | 11 | 195.7 | 16 |
| Commission list (30 doctors) | 134.9 | 21 | 19.7 | 21 |
| Data export page (row counts) | 1,452.6 | 1224 | 15.1 | 35 |
| Lab report as PDF | 65.0 | 10 | 60.7 | 10 |
| Create an order with 3 tests (and its lab work) | 19.7 | 37 | 17.8 | 37 |
| Take a payment | 8.8 | 19 | 9.5 | 20 |
| Collect and receive a sample | 8.9 | 17 | 10.3 | 17 |
| Enter and submit results for one test | 11.4 | 23 | 14.4 | 23 |
| Verify and release that test | 6.0 | 12 | 5.6 | 12 |

Three rows were already improved when the "before" column was taken, because
the problem showed on a tiny data set: the month view made 103 queries, the
14-day chart 85, and the 92-day daily report 553. They now make 8.

## What was wrong and what changed

| Problem | Change |
|---|---|
| The first indexes all began with the branch, so lists and counts covering every branch scanned whole tables. The menu badges did this on every page load | Indexes that begin with the status: invoices, lab work, samples |
| Daily figures were worked out with six queries for every day shown | Finished days are read from the daily summary table in one query; only today is worked out live. A change to a finished day drops its stored figures |
| The profit report added up a year of payments and invoice lines | It reads the same stored daily figures |
| Account balances added up every entry ever made, on each dashboard load | The balance is kept on the account and updated with each entry. `finance:sync` checks it against the entries nightly |
| The reports list built the set of every order with a released result before showing ten | A per-order lookup through an index |
| Sales by test and by doctor started from all order items | Order items are limited to the order numbers of the period |
| Large lists counted all their rows on every page load | The audit log, worklist, reports list, and money ledger show "next" and "previous" without a total |
| The data export screen counted every table exactly, and re-checked the table list for each | One query for the database's own estimates |

## What is still over 100 ms

| What | Time | Why it is accepted |
|---|---:|---|
| Sales by test, 92 days | about 0.26 s | Run on demand; it reads the 92,000 order items of the period |
| Sales by doctor, 92 days | about 0.37 s | Same |
| Profit report, one year | about 0.2 s | Run on demand |

## Things to know

- **The first view of a period with no stored figures is slower**, because
  each finished day is worked out once and then stored. The nightly job
  keeps the last eight days fresh. After loading old data, run
  `php artisan frontdesk:summarize --days=N` once, with the number of days.
- **Creating an order is about 37 queries.** That is fine for one clinic but
  is the first thing to look at if a server hosts many busy clinics.
- **Growth is roughly linear.** Nothing measured here gets slower with total
  history any more, except the first-page counts on the patient and order
  lists (15 to 30 ms at two years).
- **Migration time:** adding these indexes to the two-year clinic took about
  six seconds. With many clinics, a deploy that adds indexes takes that long
  per large clinic.
