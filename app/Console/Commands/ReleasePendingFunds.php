<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Attributes\AsScheduled;
use App\Modules\Transaction\Models\Transaction;
use Carbon\Carbon;
use App\Notifications\TransactionCompleted;
use App\Models\TransactionLog;

#[AsScheduled('everyMinute')] // <- run every minute, or change to 'everyTwentyMinutes'
class ReleasePendingFunds extends Command
{
    protected $signature = 'funds:release-pending';
    protected $description = 'Automatically release held G&S funds after 20 minutes';

    public function handle(): void
    {
        $now = Carbon::now();

        $pending = Transaction::where('purpose', 'goods_services')
            ->where('status', 'pending')
            ->where('disputed', false)
            ->whereNotNull('scheduled_release_at')
            ->where('scheduled_release_at', '<=', $now)
            ->get();

        foreach ($pending as $tx) {
            $receiver = $tx->receiver;
            if (!$receiver) continue;
            $receiver->balance += $tx->amount;
            $receiver->available_balance += $tx->amount;
            $receiver->save();

            $tx->status = 'completed';
            $tx->processed_at = $now;
            $tx->save();

             // Notify
            //  Uncomment in production
            // $receiver->notify(new TransactionCompleted($tx));
            Log::info("Funds released for transaction {$tx->reference} to receiver {$receiver->email}");


            // Log
            TransactionLog::create([
                'transaction_id' => $tx->id,
                'action' => 'released',
                'note' => 'Funds auto-released after scheduled hold.',
            ]);
        }

        $this->info("Released {$pending->count()} pending transactions.");
    }
}
