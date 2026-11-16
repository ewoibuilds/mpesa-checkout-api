<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['phone', 'amount', 'status', 'merchant_request_id', 'checkout_request_id', 'reported_receipt', 'mpesa_receipt', 'result_code', 'result_desc', 'checked_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'checked_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
