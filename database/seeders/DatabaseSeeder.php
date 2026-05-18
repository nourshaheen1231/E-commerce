<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@gmail.com',
            'password' => '123123123',
            'role' => 'admin'
        ]);

        $users = User::factory(200)->create();

        Product::factory(100)->create();

        foreach ($users as $user) {
            Cart::create([
                'user_id' => $user->id
            ]);
        }

        Product::create([
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 10
        ]);
    }
}
