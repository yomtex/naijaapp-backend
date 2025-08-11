<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Auth\Models\User;
use App\Modules\Transaction\Models\Transaction;

class TransactionSeeder extends Seeder
{
    public function run()
    {
        $statuses = ['pending', 'completed', 'failed', 'disputed'];
        $types = ['send', 'request'];
        $purposes = ['friends_family', 'goods_services'];

        // Loop through all users
        foreach (User::all() as $user) {
            // Create 5 random transactions for each user
            for ($i = 0; $i < 5; $i++) {
                $receiver = User::inRandomOrder()
                    ->where('id', '!=', $user->id)
                    ->first();

                Transaction::create([
                    'sender_id' => $user->id,
                    'receiver_id' => $receiver->id,
                    'amount' => fake()->randomFloat(2, 5, 500),
                    'type' => fake()->randomElement($types),
                    'purpose' => fake()->randomElement($purposes),
                    'reference' => strtoupper(fake()->bothify('REF###??')),
                    'note' => fake()->optional()->sentence(),
                    'status' => fake()->randomElement($statuses), // ✅ Matches enum in migration
                    'processed_at' => now(),
                    'scheduled_release_at' => null,
                    'disputed' => false,
                    'created_at' => now()->subDays(rand(0, 30)),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
