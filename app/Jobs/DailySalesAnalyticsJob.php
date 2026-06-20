<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\DailySalesReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class DailySalesAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $lock = Cache::lock(
            'daily-sales-report',
            300
        );

        if (!$lock->get()) {
            logger()->info('Another worker is generating the report');
            return;
        }

        try {

            logger()->info("Analytics Job started");
            sleep(5);
            $date = Carbon::yesterday()->toDateString();

            $totalOrders = 0;
            $totalSales = 0;
            $paidOrders = 0;
            $canceledOrders = 0;

            Order::chunkById(500, function ($orders) use (
                &$totalOrders,
                &$totalSales,
                &$paidOrders,
                &$canceledOrders
            ) {

                logger()->info("=== [Chunk Detected] === number of chunks in batch " . $orders->count() . " | first id : " . $orders->first()->id . " | last id: " . $orders->last()->id);

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

            $averageOrderValue = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

            DailySalesReport::updateOrCreate(
                ['report_date' => $date],
                [
                    'total_orders' => $totalOrders,
                    'total_sales' => $totalSales,
                    'average_order_value' => $averageOrderValue,
                    'paid_orders' => $paidOrders,
                    'canceled_orders' => $canceledOrders,
                ]
            );

            logger()->info("Analytics Job finished");
        } finally {
            $lock->release();
        }
    }
}
