<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['reference', 'total', 'status', 'paid_at'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'paid_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
