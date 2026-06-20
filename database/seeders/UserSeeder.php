<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [];
        $hashedPassword = Hash::make('password123');
        $now = now();

        for ($i = 1; $i <= 100; $i++) {
            $users[] = [
                'name' => 'user' . $i,
                'email' => 'user' . $i . '@test.com',
                'email_verified_at' => $now,
                'password' => $hashedPassword,
                'role' => 'user',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('users')->insert($users);

        $insertedEmails = array_column($users, 'email');
        $userIds = DB::table('users')->whereIn('email', $insertedEmails)->pluck('id');

        $carts = [];

        foreach ($userIds as $userId) {
            $carts[] = [
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('carts')->insert($carts);
    }
}

// php artisan db:seed --class=UserSeeder

