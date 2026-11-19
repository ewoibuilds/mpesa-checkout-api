# Milestone 01: orders

Guide: [M-Pesa checkout API](https://ewoibuilds.com/projects/mpesa-checkout-api), steps 1 to 3

**Your workspace folder:** `~/ewoi-learning/mpesa-checkout-api` (Laravel 13, with `php artisan install:api` run once).

## What this milestone adds

Products with prices you control, orders with a short unique reference, and order items that keep the price at the time of ordering. `POST /api/orders` calculates the total on the server; `GET /api/orders/{reference}` shows an order.

## The files

```
milestone-01-orders/
├── app/Models/Product.php, Order.php, OrderItem.php
├── database/migrations/2026_01_01_000001_create_products_table.php
├── database/migrations/2026_01_01_000002_create_orders_table.php
├── database/migrations/2026_01_01_000003_create_order_items_table.php
├── database/seeders/ProductSeeder.php     two sample products for trying it by hand
├── routes/api.php
└── tests/Feature/OrdersTest.php, FakesDaraja.php (shared test helpers)
```

Your migration file names start with your own date and time; compare the contents. Keep the migrations in the same order as here.

## Check

Copy this milestone's `tests/Feature/` files into your app, then from `~/ewoi-learning/mpesa-checkout-api`:

```bash
php artisan test
```

Expected: every test passes, including `OrdersTest`. No test calls Safaricom: every Daraja reply is faked.

## Commit

```bash
git status
git add .
git commit -m "Create orders with server-side totals"
```

Check that `.env` is not in the `git status` list.

## Next

[Milestone 02: pay once](../milestone-02-pay-once)

<!-- ewoi:step -->
---

**Next:** [milestone-02-pay-once](../milestone-02-pay-once/) &nbsp;&middot;&nbsp; **Stuck?** [Ask with the "I am stuck" form](https://github.com/ewoibuilds/mpesa-checkout-api/issues/new/choose) &nbsp;&middot;&nbsp; **Helped?** Star [the repo](https://github.com/ewoibuilds/mpesa-checkout-api) and follow [Ewoi Builds](https://github.com/ewoibuilds).
<!-- /ewoi:step -->
