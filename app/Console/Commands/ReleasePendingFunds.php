<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Attributes\AsScheduled;
use App\Modules\Transaction\Models\Transaction;
use Carbon\Carbon;
use App\Jobs\ReleaseFundsJob;

#[AsScheduled('everyMinute')] // run every minute
class ReleasePendingFunds extends Command
{
    protected $signature = 'funds:release-pending';
    protected $description = 'Automatically release held G&S funds after 20 minutes';

    public function handle(): void
    {
        $now = Carbon::now();
        $totalQueued = 0;

        Transaction::where('purpose', 'goods_services')
            ->where('status', 'pending')
            ->where('disputed', false)
            ->where('in_progress', false) // Only those not already being processed
            ->whereNotNull('scheduled_release_at')
            ->where('scheduled_release_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use (&$totalQueued) {
                foreach ($transactions as $tx) {
                    // Atomically mark in_progress = true only if currently false
                    $updated = Transaction::where('id', $tx->id)
                        ->where('in_progress', false)
                        ->update(['in_progress' => true]);

                    if ($updated) {
                        ReleaseFundsJob::dispatch($tx->id)->onQueue('funds_releases');
                        $totalQueued++;
                    }
                }
            });

        $this->info("Queued {$totalQueued} pending transactions for release.");
    }
}
