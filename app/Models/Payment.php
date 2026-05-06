<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'amount',
        'payment_method',
        'status',
        'stripe_payment_intent_id',
        'currency',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
