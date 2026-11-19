# Milestone 04: reconcile

Guide: [M-Pesa checkout API](https://ewoibuilds.com/projects/mpesa-checkout-api), steps 6 and 7

**Your workspace folder:** `~/ewoi-learning/mpesa-checkout-api` (Laravel 13, with `php artisan install:api` run once).

## What this milestone adds

`php artisan payments:reconcile` takes up to 100 payments still pending five minutes after they started, never-checked ones first and then the ones checked longest ago (`checked_at`, stamped on each run), so with more than 100 stuck payments the batch rotates. Payments with a `CheckoutRequestID` go through the same confirmation job. Payments with no `CheckoutRequestID` (Daraja never gave a clear answer) and payments with no final answer after 24 hours go to `needs_review` and are listed in the command's output and the log, because nobody can prove what happened to them. `routes/console.php` schedules it every five minutes. On a server, run the scheduler from one cron entry (see the Laravel scheduling docs) and keep `php artisan queue:work` running.

## The files

```
milestone-04-reconcile/
├── (everything from milestone 03)
├── app/Console/Commands/ReconcilePayments.php
├── routes/console.php                     schedules payments:reconcile
└── tests/Feature/ReconcileTest.php
```

Your migration file names start with your own date and time; compare the contents. Keep the migrations in the same order as here.

## Check

Copy this milestone's `tests/Feature/` files into your app, then from `~/ewoi-learning/mpesa-checkout-api`:

```bash
php artisan test
```

Expected: every test passes, including `ReconcileTest`. No test calls Safaricom: every Daraja reply is faked.

## Commit

```bash
git status
git add .
git commit -m "Reconcile payments with no result"
```

Check that `.env` is not in the `git status` list.

## Next

Go through [ACCEPTANCE.md](../ACCEPTANCE.md), including "Before real money". Building a real payment integration? Book a one-to-one review at https://ewoibuilds.com/training before you go live.

<!-- ewoi:step -->
---

**Next:** you finished the path: pick a project from [all free repos](https://github.com/ewoibuilds) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/mpesa-checkout-api/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/mpesa-checkout-api) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
