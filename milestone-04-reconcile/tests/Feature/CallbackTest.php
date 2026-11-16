<?php

namespace Tests\Feature;

use App\Jobs\ConfirmMpesaPayment;
use App\Models\Payment;
use App\Services\Mpesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CallbackTest extends TestCase
{
    use FakesDaraja, RefreshDatabase;

    private function queryAnswers(string $resultCode, string $desc = 'Result'): array
    {
        return ['*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0', 'ResultCode' => $resultCode, 'ResultDesc' => $desc])];
    }

    public function test_a_confirmed_payment_marks_the_order_paid(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(amount: 100)->assertOk()->assertJson(['ResultCode' => 0]);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('TEST1234AB', Payment::sole()->mpesa_receipt);
    }

    public function test_the_same_callback_twice_pays_the_order_once(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(amount: 100)->assertOk();
        $paidAt = $order->fresh()->paid_at;
        $this->travel(1)->minutes();
        $this->sendCallback(amount: 100)->assertOk();

        $this->assertSame(1, Payment::where('status', 'paid')->count());
        $this->assertEquals($paidAt, $order->fresh()->paid_at);
        Http::assertSentCount(3);
    }

    public function test_a_wrong_amount_goes_to_review_and_the_order_is_not_paid(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(amount: 1)->assertOk();

        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame('TEST1234AB', Payment::sole()->reported_receipt);
    }

    public function test_a_wrong_token_gets_404_and_changes_nothing(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(amount: 100, token: 'wrong-token')->assertNotFound();

        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_a_confirmed_cancel_returns_the_order_to_pending(): void
    {
        $this->setUpDaraja($this->queryAnswers('1032', 'Request cancelled by user'));
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(resultCode: 1032)->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('cancelled', Payment::sole()->status);
    }

    public function test_a_forged_cancel_cannot_reopen_a_paid_order(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(resultCode: 1032)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', Payment::sole()->status);
    }

    public function test_a_success_callback_is_not_trusted_when_the_status_query_says_cancelled(): void
    {
        $this->setUpDaraja($this->queryAnswers('1032', 'Request cancelled by user'));
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(amount: 100)->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull(Payment::sole()->mpesa_receipt);
    }

    public function test_an_undocumented_result_code_goes_to_review_and_the_order_keeps_waiting(): void
    {
        $this->setUpDaraja($this->queryAnswers('4999', 'The transaction is still under processing'));
        $order = $this->makeOrder();
        $this->pay($order);

        $this->sendCallback(resultCode: 1037)->assertOk();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->pay($order)->assertStatus(409);
    }

    public function test_a_missing_result_code_keeps_everything_waiting(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0'])]);
        $order = $this->makeOrder();
        $this->pay($order);

        try {
            (new ConfirmMpesaPayment(Payment::sole()->id))->handle(app(Mpesa::class));
            $this->fail('A missing result code must make the job retry.');
        } catch (RuntimeException) {
        }
        $this->assertSame('pending', Payment::sole()->status);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_a_success_for_a_failed_payment_goes_to_review(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);
        Payment::sole()->update(['status' => 'failed']);
        $order->update(['status' => 'pending']);

        $this->sendCallback(amount: 100)->assertOk();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame('TEST1234AB', Payment::sole()->reported_receipt);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
        $this->pay($order)->assertStatus(409);
    }

    public function test_an_empty_receipt_is_stored_as_null(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);

        $this->postJson('/api/payments/result/'.$this->callbackToken, ['Body' => ['stkCallback' => [
            'CheckoutRequestID' => 'ws_CO_1', 'ResultCode' => 0, 'ResultDesc' => 'Result',
            'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 1], ['Name' => 'MpesaReceiptNumber', 'Value' => '']]],
        ]]])->assertOk();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertNull(Payment::sole()->reported_receipt);
    }

    public function test_a_result_without_a_checkout_request_id_is_ignored_but_acknowledged(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);
        Payment::sole()->update(['checkout_request_id' => null]);

        $this->sendCallback(amount: 100, checkoutId: null)->assertOk()->assertJson(['ResultCode' => 0]);
        $this->sendCallback(amount: 100, checkoutId: '')->assertOk()->assertJson(['ResultCode' => 0]);
        $this->postJson('/api/payments/result/'.$this->callbackToken, ['Body' => 'not an object'])->assertOk()->assertJson(['ResultCode' => 0]);

        $this->assertSame('pending', Payment::sole()->status);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }
}
