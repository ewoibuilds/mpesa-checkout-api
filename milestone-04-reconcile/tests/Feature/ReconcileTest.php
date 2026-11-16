<?php

namespace Tests\Feature;

use App\Jobs\ConfirmMpesaPayment;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use FakesDaraja, RefreshDatabase;

    public function test_a_payment_with_no_callback_is_settled_after_five_minutes(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);

        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('pending', Payment::sole()->status);

        $this->travel(6)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('paid', Payment::sole()->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_a_payment_still_being_processed_stays_pending(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpushquery/v1/query' => Http::response(['errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500)]);
        $order = $this->makeOrder();
        $this->pay($order);
        $this->travel(6)->minutes();

        try {
            $this->artisan('payments:reconcile');
        } catch (RequestException) {
            // With the sync queue in tests, the job's retry signal surfaces here.
        }

        $this->assertSame('pending', Payment::sole()->status);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_a_payment_with_no_checkout_request_id_goes_to_review(): void
    {
        $this->setUpDaraja(['*/mpesa/stkpush/v1/processrequest' => Http::failedConnection()]);
        $order = $this->makeOrder();
        $this->pay($order)->assertStatus(504);
        $this->travel(6)->minutes();

        $this->artisan('payments:reconcile')->expectsOutputToContain('Needs review')->assertSuccessful();

        $this->assertSame('needs_review', Payment::sole()->status);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_a_payment_with_no_answer_after_24_hours_goes_to_review(): void
    {
        $this->setUpDaraja();
        $order = $this->makeOrder();
        $this->pay($order);
        $this->travel(25)->hours();

        $this->artisan('payments:reconcile')->expectsOutputToContain('Needs review')->assertSuccessful();

        $this->assertSame('needs_review', Payment::sole()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'stkpushquery'));
    }

    public function test_one_run_handles_at_most_100_payments(): void
    {
        $this->setUpDaraja();
        foreach (range(1, 105) as $i) {
            Payment::create(['phone' => '254708374149', 'amount' => 1, 'status' => 'pending', 'checkout_request_id' => "ws_CO_{$i}"]);
        }
        $this->travel(6)->minutes();

        $this->artisan('payments:reconcile')->expectsOutputToContain('Sent 100 payment(s)')->assertSuccessful();

        $this->assertSame(5, Payment::where('status', 'pending')->count());
    }

    public function test_the_batch_rotates_so_newer_payments_are_checked_too(): void
    {
        $this->setUpDaraja();
        Queue::fake();
        foreach (range(1, 105) as $i) {
            Payment::create(['phone' => '254708374149', 'amount' => 1, 'status' => 'pending', 'checkout_request_id' => "ws_CO_{$i}"]);
        }
        $this->travel(6)->minutes();

        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(5, Payment::whereNull('checked_at')->count());

        $this->travel(1)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(0, Payment::whereNull('checked_at')->count());
        $newest = Payment::orderByDesc('id')->first();
        Queue::assertPushed(ConfirmMpesaPayment::class, fn ($job) => $job->paymentId === $newest->id);
    }

    public function test_the_reconcile_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('payments:reconcile')->assertSuccessful();
    }
}
