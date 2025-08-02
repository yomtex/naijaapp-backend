<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Attributes\AsScheduled;
use App\Modules\Auth\Models\User;

#[AsScheduled('weekly')] // or 'monthly', or use cron expression
class DecayRiskScores extends Command
{
    protected $signature = 'risk:decay';
    protected $description = 'Reduce risk scores over time for all users';

    public function handle()
    {
        $users = User::where('risk_score', '>', 0)->get();

        foreach ($users as $user) {
            $user->decrementRiskScore(2); // reduce by 2 weekly/monthly
        }

        $this->info('Risk scores decayed successfully.');
    }
}
