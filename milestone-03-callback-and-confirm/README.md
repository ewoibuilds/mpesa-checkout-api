# Milestone 03: callback and confirmation

Guide: [M-Pesa checkout API](https://ewoibuilds.com/projects/mpesa-checkout-api), step 5

**Your workspace folder:** `~/ewoi-learning/mpesa-checkout-api` (Laravel 13, with `php artisan install:api` run once).

## What this milestone adds

`POST /api/payments/result/{token}`: the secret token compared with `hash_equals`, the body shape checked (anything odd is logged and still answered with `{"ResultCode":0}`), and the payment found by `CheckoutRequestID`. The body is only a hint. Every result, success or failure, goes through the `ConfirmMpesaPayment` job, which asks Daraja's status query. Safaricom documents two result codes: 0 ("The service request is processed successfully.") and 1032 ("Request cancelled by user"); any other code "means an error occurred or the transaction failed" (Daraja, M-Pesa Express Query and Simulate, developer.safaricom.co.ke). So 0 marks the payment paid and the order paid, 1032 marks it cancelled and reopens the order, and any other code sends the payment to `needs_review` with the order still `awaiting_payment`, because that sentence does not prove the money did not move. A missing code makes the job retry. A wrong amount, or a success for a payment already marked failed, cancelled or in review, goes to `needs_review` (keeping the reported receipt) with an error in the log, never silently dropped; in the second case the order also moves back to `awaiting_payment`, so the customer is not asked to pay again. The receipt from the callback is kept in `reported_receipt` and copied to `mpesa_receipt` (straight from the database row) only when the status query confirms the payment. An empty receipt is stored as no value (null), never as blank text.

## The files

```
milestone-03-callback-and-confirm/
├── (everything from milestone 02)
├── app/Jobs/ConfirmMpesaPayment.php
├── routes/api.php                         adds the callback
└── tests/Feature/CallbackTest.php
```

Your migration file names start with your own date and time; compare the contents. Keep the migrations in the same order as here.

## Check

Copy this milestone's `tests/Feature/` files into your app, then from `~/ewoi-learning/mpesa-checkout-api`:

```bash
php artisan test
```

Expected: every test passes, including `CallbackTest`. No test calls Safaricom: every Daraja reply is faked.

## Commit

```bash
git status
git add .
git commit -m "Mark orders paid only after confirmation"
```

Check that `.env` is not in the `git status` list.

## Next

[Milestone 04: reconcile](../milestone-04-reconcile)

<!-- ewoi:step -->
---

**Next:** [milestone-04-reconcile](../milestone-04-reconcile/) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/mpesa-checkout-api/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/mpesa-checkout-api) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
