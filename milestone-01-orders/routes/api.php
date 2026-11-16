<?php

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
