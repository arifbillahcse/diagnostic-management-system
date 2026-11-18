# Go-live checklist

Tick every line. A line that cannot be ticked is a reason to wait.

## Before the clinic (week 0)

- [ ] Server set up by [server-runbook.md](server-runbook.md); `platform:doctor` clean
- [ ] HTTPS works; HTTP redirects to HTTPS
- [ ] `BACKUP_PASSPHRASE` set and stored off the server
- [ ] A nightly backup has been restored on another machine
- [ ] Off-server backup copy runs nightly
- [ ] Mail sends (test with a password reset)
- [ ] Terms, privacy policy and pilot agreement reviewed by a lawyer
- [ ] Pilot agreement signed
- [ ] Clinic's price list in [services-template.csv](services-template.csv)
- [ ] Referral doctors in [doctors-template.csv](doctors-template.csv)
- [ ] Sample of their current receipt, report and envelope collected

## Setting up the clinic (week 1)

- [ ] `clinic:create` run; owner can sign in and has set up two-step sign-in
- [ ] Clinic settings: name, address, phone, logo, time zone
- [ ] Branches added (if more than one)
- [ ] Services imported:
  `php artisan frontdesk:import <clinic> services services.csv --dry-run`, then without `--dry-run`
- [ ] Doctors imported the same way
- [ ] Reference ranges checked against the lab's current reports, test by test
- [ ] Signatories added with signatures
- [ ] Receipt and report layouts match the clinic's papers (print and compare)
- [ ] Printers tested at reception and lab (paper size, margins)
- [ ] Staff accounts created, one per person, no shared accounts
- [ ] Each role has only the permissions it needs
- [ ] Training done with the cheat sheets; each person did one full order

## First day

- [ ] You are at the clinic, or on the phone, all day
- [ ] Old way runs alongside (parallel run) for at least one week
- [ ] First order, first payment, first report printed and checked by the owner
- [ ] Evening: [daily-check.md](daily-check.md) done with the owner

## Success measures (review at week 4)

| Measure | Target |
|---|---|
| Cash in the drawer matches DMS cash | Every day, to the taka |
| Reports with a wrong value or range | None |
| Order entry time at reception | Under 2 minutes |
| Blocker problems open | None |
| Owner willing to pay the agreed price | Yes |
