<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersTest extends TestCase
{
    use FakesDaraja, RefreshDatabase;

    public function test_the_total_is_calculated_on_the_server(): void
    {
        $notebook = Product::create(['name' => 'Notebook', 'price' => 50]);
        $pen = Product::create(['name' => 'Pen', 'price' => 20]);

        $this->postJson('/api/orders', ['total' => 1, 'items' => [['id' => $notebook->id, 'quantity' => 2], ['id' => $pen->id, 'quantity' => 3]]])
            ->assertCreated()
            ->assertJsonPath('total', 160)
            ->assertJsonPath('status', 'pending')
            ->assertJsonCount(2, 'items');
    }

    public function test_unknown_products_and_bad_quantities_are_rejected(): void
    {
        $this->postJson('/api/orders', ['items' => [['id' => 999, 'quantity' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.id', 'items.0.quantity']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_order_is_shown_by_its_reference_not_its_id(): void
    {
        $order = $this->makeOrder();

        $this->getJson("/api/orders/{$order->reference}")->assertOk()->assertJsonPath('total', 100)->assertJsonMissingPath('id');
        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
    }
}
