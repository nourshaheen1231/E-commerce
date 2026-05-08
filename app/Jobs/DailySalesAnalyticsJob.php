<?php

namespace App\Jobs;

use App\Models\Order;
use Carbon\Carbon;
use App\Models\DailySalesReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DailySalesAnalyticsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        $date = Carbon::yesterday()->toDateString();
        // $date = Carbon::today()->toDateString();

        $totalOrders = 0;
        $totalSales = 0;
        $paidOrders = 0;
        $canceledOrders = 0;


        Order::whereDate('created_at', $date)
            ->chunk(500, function ($orders) use (
                &$totalOrders,
                &$totalSales,
                &$paidOrders,
                &$canceledOrders
            ) {

                foreach ($orders as $order) {

                    $totalOrders++;

                    $totalSales += $order->total_price;

                    if ($order->status === 'paid') {
                        $paidOrders++;
                    }

                    if ($order->status === 'canceled') {
                        $canceledOrders++;
                    }
                }
            });

            $averageOrderValue =
            $totalOrders > 0
                ? $totalSales / $totalOrders
                : 0;

            DailySalesReport::updateOrCreate(
            [
                'report_date' => $date
            ],
            [
                'total_orders' => $totalOrders,
                'total_sales' => $totalSales,
                'average_order_value' => $averageOrderValue,
                'paid_orders' => $paidOrders,
                'canceled_orders' => $canceledOrders,
            ]
        );
    }
}
