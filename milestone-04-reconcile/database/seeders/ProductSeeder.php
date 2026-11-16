<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create(['name' => 'Notebook', 'price' => 1]);
        Product::create(['name' => 'Pen', 'price' => 2]);
    }
}
