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
use Illuminate\Support\Facades\Log; 

class DailySalesAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $lock = Cache::lock('daily-sales-report', 300);

        if (!$lock->get()) {
            Log::channel('analytics')->warning('Analytics Job Lock Acquisition Failed', [
                'reason' => 'Another worker is already generating the report',
                'lock_key' => 'daily-sales-report'
            ]);
            return;
        }

        try {
            $date = Carbon::yesterday()->toDateString();

            Log::channel('analytics')->info('Daily Sales Analytics Job Started', [
                'target_date' => $date
            ]);

            sleep(5);

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
                Log::channel('analytics')->info('Analytics Job Chunk Processed', [
                    'chunk_items_count' => $orders->count(),
                    'first_order_id' => $orders->first()?->id,
                    'last_order_id' => $orders->last()?->id,
                ]);

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

            Log::channel('analytics')->info('Daily Sales Analytics Job Finished Successfully', [
                'target_date' => $date,
                'metrics' => [
                    'total_processed_orders' => $totalOrders,
                    'total_sales_amount' => $totalSales,
                    'average_order_value' => $averageOrderValue,
                    'paid_count' => $paidOrders,
                    'canceled_count' => $canceledOrders
                ]
            ]);
        } catch (\Exception $e) {
            Log::channel('analytics')->error('Daily Sales Analytics Job Failed', [
                'target_date' => $date ?? null,
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
