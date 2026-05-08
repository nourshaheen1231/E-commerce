<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();
            $table->integer('total_orders');
            $table->decimal('total_sales', 12, 2);
            $table->decimal('average_order_value', 12, 2);
            $table->integer('paid_orders');
            $table->integer('canceled_orders');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_sales_reports');
    }
};
