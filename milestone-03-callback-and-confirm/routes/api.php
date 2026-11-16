<?php

use App\Jobs\ConfirmMpesaPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Mpesa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::post('/orders', function (Request $request) {
    $data = $request->validate([
        'items' => ['required', 'array', 'min:1', 'max:20'],
        'items.*.id' => ['required', 'integer', 'distinct', 'exists:products,id'],
        'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
    ]);

    $order = DB::transaction(function () use ($data) {
        $prices = Product::whereIn('id', collect($data['items'])->pluck('id'))->pluck('price', 'id');
        $total = collect($data['items'])->sum(fn ($item) => $prices[$item['id']] * $item['quantity']);

        $order = Order::create(['reference' => strtoupper(Str::random(8)), 'total' => $total, 'status' => 'pending']);
        foreach ($data['items'] as $item) {
            $order->items()->create(['product_id' => $item['id'], 'quantity' => $item['quantity'], 'unit_price' => $prices[$item['id']]]);
        }

        return $order;
    });

    return response()->json($order->load('items'), 201);
});

Route::get('/orders/{order:reference}', function (Order $order) {
    return $order->load('items');
});

Route::post('/orders/{order:reference}/pay', function (Request $request, Order $order, Mpesa $mpesa) {
    $data = $request->validate(['phone' => ['required', 'string', 'regex:/^(?:\+?254|0)(7|1)\d{8}$/']]);
    abort_unless($order->total >= 1 && $order->total <= 250000, 422, 'This order total cannot be paid with M-Pesa.');
    $phone = '254'.substr(preg_replace('/\D/', '', $data['phone']), -9);

    try {
        $mpesa->ready();
    } catch (RuntimeException|HttpClientException $e) {
        Log::error('M-Pesa is not ready', ['error' => $e::class]);

        return response()->json(['message' => 'Payments are not available right now. Nothing was charged.'], 503);
    }

    $phoneKey = 'mpesa-phone:'.$phone;
    if (RateLimiter::tooManyAttempts($phoneKey, 3)) {
        return response()->json(['message' => 'Too many payment prompts for this phone number. Please wait a few minutes.'], 429);
    }

    $payment = DB::transaction(function () use ($order, $phone) {
        $claimed = Order::whereKey($order->id)->where('status', 'pending')->update(['status' => 'awaiting_payment']);
        abort_unless($claimed === 1, 409, 'This order is already being paid or is closed.');

        return $order->payments()->create(['phone' => $phone, 'amount' => $order->total, 'status' => 'pending']);
    });
    RateLimiter::hit($phoneKey, 600);

    try {
        $response = $mpesa->stkPush($phone, $payment->amount, $order->reference);
    } catch (ConnectionException) {
        $response = null;
    }

    if ($response && $mpesa->accepted($response)) {
        Payment::whereKey($payment->id)->where('status', 'pending')->update([
            'merchant_request_id' => $response->json('MerchantRequestID'),
            'checkout_request_id' => $response->json('CheckoutRequestID'),
        ]);

        return response()->json(['order' => $order->reference, 'message' => 'Check your phone and enter your M-Pesa PIN.'], 202);
    }

    if ($response && $mpesa->refused($response)) {
        DB::transaction(function () use ($payment, $order, $response) {
            Payment::whereKey($payment->id)->where('status', 'pending')->update([
                'status' => 'failed',
                'result_desc' => mb_substr((string) ($response->json('errorMessage') ?? $response->json('ResponseDescription')), 0, 255),
            ]);
            Order::whereKey($order->id)->where('status', 'awaiting_payment')->update(['status' => 'pending']);
        });

        return response()->json(['message' => 'The payment could not be started. You can try again.'], 502);
    }

    // No clear answer: the prompt may have been sent, so the order is not reopened for a second payment.
    Payment::whereKey($payment->id)->where('status', 'pending')->update(['result_desc' => 'No clear answer from Daraja: check manually']);

    return response()->json(['message' => 'We could not confirm the payment request. Please do not pay again; check this order in a few minutes.'], 504);
})->middleware('throttle:payments');

Route::post('/payments/result/{token}', function (Request $request, Mpesa $mpesa, string $token) {
    abort_unless(hash_equals($mpesa->callbackToken(), $token), 404);
    $ack = response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

    $result = $request->input('Body.stkCallback');
    $checkoutId = is_array($result) ? ($result['CheckoutRequestID'] ?? null) : null;
    $resultCode = is_array($result) ? ($result['ResultCode'] ?? null) : null;
    if (! is_string($checkoutId) || $checkoutId === '' || ! is_numeric($resultCode)) {
        Log::warning('M-Pesa result ignored: unexpected body');

        return $ack;
    }

    $payment = Payment::where('checkout_request_id', $checkoutId)->first();
    if (! $payment) {
        Log::warning('M-Pesa result for an unknown CheckoutRequestID', ['checkout_request_id' => $checkoutId]);

        return $ack;
    }

    if ((int) $resultCode === 0) {
        $items = collect($result['CallbackMetadata']['Item'] ?? [])->pluck('Value', 'Name');
        $receipt = mb_substr((string) $items->get('MpesaReceiptNumber'), 0, 32) ?: null;

        if ($payment->status === 'paid') {
            return $ack;
        }

        if ($payment->status !== 'pending') {
            DB::transaction(function () use ($payment, $receipt) {
                $flagged = Payment::whereKey($payment->id)->whereIn('status', ['failed', 'cancelled'])->update(['status' => 'needs_review', 'reported_receipt' => $receipt, 'result_desc' => 'Success reported for a closed payment: check manually']);
                if ($flagged === 1) {
                    Order::whereKey($payment->order_id)->where('status', 'pending')->update(['status' => 'awaiting_payment']);
                }
            });
            Log::error('M-Pesa payment needs review: success reported for a closed payment', ['payment' => $payment->id]);

            return $ack;
        }

        if ((int) $items->get('Amount') !== $payment->amount) {
            Payment::whereKey($payment->id)->where('status', 'pending')->update(['status' => 'needs_review', 'reported_receipt' => $receipt, 'result_desc' => 'Amount mismatch: check manually']);
            Log::error('M-Pesa payment needs review: amount mismatch', ['payment' => $payment->id]);

            return $ack;
        }

        Payment::whereKey($payment->id)->where('status', 'pending')->update(['reported_receipt' => $receipt, 'result_desc' => 'Success reported, confirming']);
    }

    if ($payment->status === 'pending') {
        ConfirmMpesaPayment::dispatch($payment->id);
    }

    return $ack;
});
