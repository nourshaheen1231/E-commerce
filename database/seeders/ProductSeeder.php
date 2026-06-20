<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\Redis;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $keywords = ['laptop', 'phone', 'shoes', 'watch', 'camera', 'samsung', 'apple', 'bag'];

        $totalRecords = 20;
        $chunkSize = 20;

        $this->command->info("Starting to seed {$totalRecords} products...");

        for ($i = 0; $i < $totalRecords; $i += $chunkSize) {
            $products = [];
            $currentChunkSize = min($chunkSize, $totalRecords - $i);

            for ($j = 0; $j < $currentChunkSize; $j++) {
                $keyword = $keywords[array_rand($keywords)];

                $products[] = [
                    'name' => $faker->company() . ' ' . $keyword . ' ' . $faker->word(),
                    'description' => $faker->realText(100),
                    'price' => $faker->randomFloat(2, 10, 5000),
                    'stock' => $faker->numberBetween(0, 100),
                    'order_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('products')->insert($products);

            $insertedProducts = DB::table('products')
                ->orderBy('id', 'desc')
                ->limit($currentChunkSize)
                ->get();


            $inserted = $i + $currentChunkSize;
            $this->command->info("Inserted {$inserted} / {$totalRecords} products.");
        }

        $this->command->info("Successfully seeded {$totalRecords} products!");
    }
}

// php artisan db:seed --class=ProductSeeder
