<?php

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

        return response()->json(['message' => $mpesa->refusalMessage($response)], 502);
    }

    // No clear answer: the prompt may have been sent, so the order is not reopened for a second payment.
    Payment::whereKey($payment->id)->where('status', 'pending')->update(['result_desc' => 'No clear answer from Daraja: check manually']);

    return response()->json(['message' => 'We could not confirm the payment request. Please do not pay again; check this order in a few minutes.'], 504);
})->middleware('throttle:payments');
