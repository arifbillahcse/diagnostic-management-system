# Sample data for testing

One command fills a **new, empty clinic** with made-up data for every module,
so every screen has something to show:

```
php artisan clinic:demo-data <clinic id> --password="Your-Sample-Pass-1"
```

The password is for the three sample staff accounts (receptionist, lab
technician, verifier). It must be at least 10 characters. It takes a few
seconds and prints where to look at the end.

## Rules

- **Use a new clinic.** The loaders refuse a clinic that already has tests
  or orders, so they cannot overwrite real data. There is no command to remove
  sample data; start another clinic instead.
- **Never on a real clinic.** On a production server the command asks for
  `--force`; give it only for a clinic that will never hold real data.
- The reference ranges and imaging templates are examples. A laboratory and a
  reporting doctor must review them before real use.

## Steps on a server (for example cPanel)

1. Create an empty database in the hosting panel and give the application's
   MySQL user all privileges on it.
2. Create the clinic, with its own address:

   ```
   php artisan clinic:create "Test Clinic" test.example.com --owner-name="Owner" --owner-email=owner@example.com --database=account_testclinic
   ```
3. Find the clinic's ID with `php artisan clinic:list`, then:

   ```
   php artisan clinic:demo-data <clinic id> --password="Your-Sample-Pass-1" --force
   php artisan optimize:clear
   ```
4. If you want the messages to show "Sent" instead of "Queued", run the queue
   once: `php artisan queue:work --stop-when-empty`.

## What each step loads

`clinic:demo-data` switches the modules on, then runs each module's own
command. You can run them one by one, in this order, to load only some:

| Order | Command | Loads | Look at |
|---|---|---|---|
| 1 | `laboratory:sample-data` | 18 tests with ranges, 5 doctors, 3 staff accounts, 16 patients, 30 orders over 10 days at every stage | Orders, Dues, Samples, Worklist, Reports, Dashboard |
| 2 | `laboratory:sample-branches` | North branch, 6 bills there, 2 sample transfers | Branch switcher, Sample transfers, Collection centres |
| 3 | `frontdesk:sample-data` | A cash session closed Tk 50 short, 3 refunds (approved, waiting, rejected) | Cash sessions, Refunds |
| 4 | `inventory:sample-data` | 7 stock items, a supplier, a purchase, 14 stock use rules | Stock, Purchases, Use per test |
| 5 | `imaging:sample-data` | 4 report templates, 3 signed reports | Imaging reports, Report templates |
| 6 | `people:sample-data` | 4 staff, 2 months of attendance, an advance, a draft salary run | Staff, Attendance, Salary runs |
| 7 | `analyzers:sample-data` | 2 machines with mapped codes, 4 received messages, a blood sample waiting | Machines, Machine inbox, Test codes |
| 8 | `finance:sample-data` | 10 expenses (1 cancelled), commission rates, a part payout, a transfer | Accounts, Expenses, Referral commission, Profit report |
| 9 | `messaging:sample-data` | SMS switched on, bill, report-ready and due messages, 1 failed, 1 opted out | Message log, Message wording |
| 10 | `portal:sample-data` | Nothing written; prints a bill number and mobile that open a report | `/reports` on the clinic address |

Later steps need the earlier ones for the tests, patients and doctors they use.
Finance comes after the money has moved, so its accounts and commission
include every bill.

## Trying a machine without one

`analyzers:sample-data` leaves one blood sample waiting in the lab and prints
its number. Send its result with "Try a message" on the Machines screen, or
with the bridge's pretend analyzer (see `bridge/README.md`):

```
python -m dms_bridge.simulator --connect 127.0.0.1:5100 --sample <that number> --units cumm
```

The sample tests are set up in /cumm, so use `--units cumm`; DMS does not
convert units.

## For developers

The commands share `App\Core\Console\SampleDataCommand` (guards, running as
the owner, putting the clock back). A module adds its own command and
registers it with the others in its module class. `clinic:demo-data` lives in
core but only calls the module commands **by name**, and skips a step whose
module is off or missing, so core never depends on a module.
