<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

trait FakesDaraja
{
    protected string $callbackToken = 'test-callback-token-0123456789abcdef';

    protected function setUpDaraja(array $fakes = []): void
    {
        config([
            'app.url' => 'https://example.test',
            'services.mpesa' => [
                'env' => 'sandbox',
                'consumer_key' => 'test-key',
                'consumer_secret' => 'test-secret',
                'shortcode' => '174379',
                'passkey' => 'test-passkey',
                'callback_token' => $this->callbackToken,
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake($fakes + [
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => '3599']),
            '*/mpesa/stkpush/v1/processrequest' => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing']),
            '*/mpesa/stkpushquery/v1/query' => Http::response(['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']),
        ]);
    }

    protected function makeOrder(int $price = 50, int $quantity = 2): Order
    {
        return $this->makeOrderFor(Product::create(['name' => 'Notebook', 'price' => $price]), $quantity);
    }

    protected function makeOrderFor(Product $product, int $quantity = 2): Order
    {
        $reference = $this->postJson('/api/orders', ['items' => [['id' => $product->id, 'quantity' => $quantity]]])
            ->assertCreated()
            ->json('reference');

        return Order::where('reference', $reference)->sole();
    }

    protected function pay(Order $order): TestResponse
    {
        return $this->postJson("/api/orders/{$order->reference}/pay", ['phone' => '0708374149']);
    }

    protected function sendCallback(int $resultCode = 0, int $amount = 100, ?string $token = null, ?string $checkoutId = 'ws_CO_1'): TestResponse
    {
        $body = ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => $checkoutId, 'ResultCode' => $resultCode, 'ResultDesc' => 'Result'];
        if ($resultCode === 0) {
            $body['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => 'TEST1234AB'],
                ['Name' => 'TransactionDate', 'Value' => 20260101120000],
                ['Name' => 'PhoneNumber', 'Value' => 254708374149],
            ]];
        }

        return $this->postJson('/api/payments/result/'.($token ?? $this->callbackToken), ['Body' => ['stkCallback' => $body]]);
    }
}
