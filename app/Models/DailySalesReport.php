<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySalesReport extends Model
{
    protected $fillable = [
        'report_date',
        'total_orders',
        'total_sales',
        'average_order_value',
        'paid_orders',
        'canceled_orders'
    ];
}
