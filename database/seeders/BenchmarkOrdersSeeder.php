<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class BenchmarkOrdersSeeder extends Seeder
{
    public function run(): void
    {

        // DatabaseSeeder.php
        Schema::disableForeignKeyConstraints();
        $date = Carbon::yesterday()->toDateTimeString();
        $chunks = array_fill(0, 100, []); // 1000 chunks of 1000 rows = 1M rows

        foreach ($chunks as $chunk) {
            $data = [];
            for ($i = 0; $i < 100; $i++) {
                $data[] = [
                    'user_id' => rand(1, 100),
                    'total_price' => rand(10, 500),
                    'status' => ['paid', 'canceled', 'pending'][rand(0, 2)],
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            DB::table('orders')->insert($data);
        }
        Schema::enableForeignKeyConstraints();
    }
    // php artisan db:seed --class=BenchmarkOrdersSeeder
    // php artisan db:show
    // php artisan db:table daily_sales_reports
    // \App\Jobs\DailySalesAnalyticsJob::dispatch()->onConnection('redis')->onQueue('default');
}
