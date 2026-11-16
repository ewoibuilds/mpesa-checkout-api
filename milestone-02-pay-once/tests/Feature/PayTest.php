<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayTest extends TestCase
{
    use FakesDaraja, RefreshDatabase;

    public function test_pay_sends_one_stk_push_for_the_order_total(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder(price: 50, quantity: 2);

        $this->pay($order)->assertStatus(202);

        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => 100, 'status' => 'pending', 'checkout_request_id' => 'ws_CO_1']);
        Http::assertSent(function (Request $request) use ($order) {
            if (! str_ends_with($request->url(), '/processrequest')) {
                return false;
            }

            return $request['Amount'] === 100
                && $request['AccountReference'] === $order->reference
                && $request['Password'] === base64_encode('174379'.'test-passkey'.$request['Timestamp'])
                && $request['CallBackURL'] === 'https://example.test/api/payments/result/'.$this->callbackToken;
        });
    }

    public function test_tapping_pay_twice_starts_only_one_payment(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(202);
        $this->pay($order)->assertStatus(409);

        $this->assertSame(1, $order->payments()->count());
    }

    public function test_a_clear_refusal_reopens_the_order(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response(['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid Amount'], 400)]);
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(502);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('failed', $order->payments()->first()->status);
    }

    public function test_a_non_zero_response_code_reopens_the_order(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response(['ResponseCode' => '1', 'ResponseDescription' => 'Rejected'])]);
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(502);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_no_answer_from_daraja_does_not_reopen_the_order(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::failedConnection()]);
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(504);

        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->assertSame('pending', $order->payments()->first()->status);
    }

    public function test_a_server_error_is_an_unknown_outcome_and_keeps_the_order_waiting(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'Internal error'], 500)]);
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(504);

        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->assertSame('pending', $order->payments()->first()->status);
    }

    public function test_an_unreadable_answer_keeps_the_order_waiting(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::response('<html>gateway</html>', 200)]);
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(504);

        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_the_ids_are_only_saved_on_a_pending_payment(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => function () {
            Payment::query()->update(['status' => 'needs_review']);

            return Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0']);
        }]);
        $order = $this->makeOrder();

        $this->pay($order);

        $this->assertNull($order->payments()->first()->checkout_request_id);
    }

    public function test_a_total_outside_the_m_pesa_range_is_refused_before_any_call(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder(price: 100000, quantity: 3);

        $this->pay($order)->assertUnprocessable();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'processrequest'));
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_placeholder_callback_token_stops_payment_without_leaving_the_order_stuck(): void
    {
        $this->setUpDaraja();
        config(['services.mpesa.callback_token' => 'changeme']);
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(503);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'processrequest'));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->payments()->count());
    }

    public function test_an_access_token_failure_does_not_leave_the_order_stuck(): void
    {
        $this->setUpDaraja(['*/oauth/v1/generate*' => Http::failedConnection()]);
        $order = $this->makeOrder();

        $this->pay($order)->assertStatus(503);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->payments()->count());
    }

    public function test_invalid_requests_do_not_lock_a_phone_out(): void
    {
        $this->setUpDaraja();
        $paid = $this->makeOrder();
        $paid->update(['status' => 'paid']);
        $order = $this->makeOrder();

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/orders/NOSUCHREF/pay', ['phone' => '0708374149'])->assertNotFound();
        }
        $this->pay($paid)->assertStatus(409);
        $this->pay($paid)->assertStatus(409);

        $this->pay($order)->assertStatus(202);
    }

    public function test_one_phone_can_start_only_three_payments_in_ten_minutes(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => fn () => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_'.Str::random(10), 'ResponseCode' => '0'])]);
        $product = Product::create(['name' => 'Pen', 'price' => 2]);
        $orders = collect(range(1, 4))->map(fn () => $this->makeOrderFor($product));

        $this->pay($orders[0])->assertStatus(202);
        $this->pay($orders[1])->assertStatus(202);
        $this->pay($orders[2])->assertStatus(202);
        $this->pay($orders[3])->assertStatus(429);

        $this->assertSame('pending', $orders[3]->fresh()->status);
    }
}
