# Milestone 02: pay once

Guide: [M-Pesa checkout API](https://ewoibuilds.com/projects/mpesa-checkout-api), step 4

**Your workspace folder:** `~/ewoi-learning/mpesa-checkout-api` (Laravel 13, with `php artisan install:api` run once).

## What this milestone adds

The tutorial's `Mpesa` service and settings, a `payments` table linked to orders, the `payments` rate limit in `app/Providers/AppServiceProvider.php` (10 requests a minute per address), a per-phone limit in the route (3 prompts per phone every 10 minutes, counted only once a valid order has been claimed, so invalid requests cannot lock a phone out), and `POST /api/orders/{reference}/pay`. A conditional update (`where status = pending`) makes a double tap get 409 instead of a second prompt. Only a clear refusal documented by Daraja reopens the order (a `ResponseCode` other than "0", a 4xx with an `errorCode`, or `500.001.1001`, `500.003.02` or `500.003.03`, each with honest advice such as "wait one minute"); any other 500, an unreadable answer or no answer at all leaves it waiting (504), because the prompt may have been sent. Source: the Error Codes table in Daraja, M-Pesa Express Simulate, developer.safaricom.co.ke. The order total must be between 1 and 250,000 before any call, and `Mpesa::ready()` checks the result token and fetches an access token before the order is claimed, so a broken setup answers 503 and never leaves an order stuck. Behind a proxy or tunnel, configure trusted proxies in `bootstrap/app.php` so the per-address limit sees the customer's address. Add the `MPESA_` lines from the repo's [.env.example](../.env.example) to your `.env.example` (as `changeme`) and `.env` (your sandbox values).

## The files

```
milestone-02-pay-once/
├── (everything from milestone 01)
├── config/services.php                    the mpesa entry
├── app/Providers/AppServiceProvider.php   the payments rate limit
├── app/Services/Mpesa.php
├── app/Models/Payment.php, Order.php      (Order gains payments())
├── database/migrations/2026_01_01_000004_create_payments_table.php
├── routes/api.php                         adds the pay endpoint
└── tests/Feature/PayTest.php
```

Your migration file names start with your own date and time; compare the contents. Keep the migrations in the same order as here.

## Check

Copy this milestone's `tests/Feature/` files into your app, then from `~/ewoi-learning/mpesa-checkout-api`:

```bash
php artisan test
```

Expected: every test passes, including `PayTest`. No test calls Safaricom: every Daraja reply is faked.

## Commit

```bash
git status
git add .
git commit -m "Start one STK Push per order"
```

Check that `.env` is not in the `git status` list.

## Next

[Milestone 03: callback and confirmation](../milestone-03-callback-and-confirm)

<!-- ewoi:step -->
---

**Next:** [milestone-03-callback-and-confirm](../milestone-03-callback-and-confirm/) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/mpesa-checkout-api/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/mpesa-checkout-api) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
