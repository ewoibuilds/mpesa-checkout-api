# Acceptance checklist

Tick each line on your own app. The test named in brackets checks it for you.

## Milestone 01: orders

- [ ] Amounts are whole shillings in `unsignedInteger` columns, cast to `integer` in the models
- [ ] The order total is calculated from your product prices; any amount in the request is ignored (`OrdersTest`)
- [ ] Unknown products and quantities below 1 are refused with 422 and nothing is saved (`OrdersTest`)
- [ ] Orders are found by their reference, never by their id, and the id is not in the JSON (`OrdersTest`)

## Milestone 02: pay once

- [ ] Secrets are read with `config('services.mpesa...')`; `.env` holds the values and is not committed
- [ ] The placeholder callback token is refused loudly; only a random value of 32 or more characters works (`PayTest`)
- [ ] Pay sends one STK Push for the order total with a correct password and the HTTPS result address (`PayTest`)
- [ ] Tapping pay twice gives 409 and creates one payment (`PayTest`)
- [ ] Only a clear refusal (a 4xx with an `errorCode`, or a `ResponseCode` other than "0") reopens the order (`PayTest`)
- [ ] A server error, an unreadable answer or no answer leaves the order `awaiting_payment` with a 504 that says not to pay again (`PayTest`)
- [ ] Request IDs are saved only on a payment that is still pending (`PayTest`)
- [ ] A total outside 1 to 250,000 is refused before any call (`PayTest`)
- [ ] One phone gets at most 3 prompts in 10 minutes, and invalid requests (404, 409, 422) never count against it (`PayTest`)
- [ ] A placeholder token or an access-token failure answers 503 and leaves the order `pending` (`PayTest`)

## Milestone 03: result and confirmation

- [ ] A wrong token gets 404 and changes nothing (`CallbackTest`)
- [ ] A result with no `CheckoutRequestID` or an odd body is ignored and still acknowledged (`CallbackTest`)
- [ ] A success is confirmed with the status query before the order is paid (`CallbackTest`)
- [ ] The same result twice gives one paid order and one receipt (`CallbackTest`)
- [ ] A confirmed cancel (1032) returns the order to `pending` (`CallbackTest`)
- [ ] A forged cancel cannot reopen an order the status query says is paid (`CallbackTest`)
- [ ] An unknown or missing result code keeps the payment pending and the job retries (`CallbackTest`)
- [ ] A wrong amount, or a success for a failed payment, goes to `needs_review`; in the second case the order goes back to `awaiting_payment` (`CallbackTest`)

## Milestone 04: reconcile

- [ ] A payment with no result is settled by the status query after five minutes (`ReconcileTest`)
- [ ] A payment still being processed stays pending and is retried (`ReconcileTest`)
- [ ] A payment with no `CheckoutRequestID`, or with no final answer after 24 hours, goes to `needs_review` and is reported (`ReconcileTest`)
- [ ] One run handles at most 100 payments, and the batch rotates so newer payments are checked too (`ReconcileTest`)
- [ ] `payments:reconcile` appears in `php artisan schedule:list` (`ReconcileTest`)
- [ ] `php artisan test` passes with no failures

## Before real money

- [ ] Logs never contain full phone numbers, tokens, passkeys or Daraja request bodies (they carry the password)
- [ ] Someone checks the `needs_review` list every day
- [ ] Customers sign in, and the pay and order endpoints check that the order belongs to them
- [ ] An alert when a payment stays `pending` for more than an hour
- [ ] A daily check that paid orders match the M-Pesa statement
- [ ] An independent review of the payment code
