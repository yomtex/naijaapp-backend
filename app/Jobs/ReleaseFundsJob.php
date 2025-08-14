<?php

namespace App\Jobs;

use App\Modules\Transaction\Models\Transaction;
use App\Models\TransactionLog;
use App\Notifications\TransactionCompleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Throwable;

class ReleaseFundsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 60;

    protected int $transactionId;

    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
    }

    public function handle(): void
    {
        DB::transaction(function () {

            // Atomically mark in_progress = true only if status is pending and not in progress
            $updated = Transaction::where('id', $this->transactionId)
                ->where('status', 'pending')
                ->where('in_progress', false)
                ->update(['in_progress' => true]);

            if (!$updated) {
                Log::info("ReleaseFundsJob: Transaction already processed or in progress (ID: {$this->transactionId})");
                return;
            }

            // Reload the transaction after marking in_progress
            $tx = Transaction::lockForUpdate()->find($this->transactionId);

            if (!$tx) {
                Log::warning("ReleaseFundsJob: Transaction not found (ID: {$this->transactionId})");
                return;
            }

            $receiver = $tx->receiver;

            if (!$receiver) {
                Log::warning("ReleaseFundsJob: No receiver found for transaction ID {$tx->id}");
                $tx->in_progress = false;
                $tx->save();
                return;
            }

            // Credit the receiver
            $receiver->balance += $tx->amount;
            $receiver->available_balance += $tx->amount;
            $receiver->save();

            // Update transaction
            $tx->status = 'completed';
            $tx->processed_at = Carbon::now();
            $tx->in_progress = false;
            $tx->save();

            // Log the release
            TransactionLog::create([
                'transaction_id' => $tx->id,
                'action' => 'released',
                'note' => 'Funds auto-released after scheduled hold.',
            ]);

            // Optional notification
            // $receiver->notify(new TransactionCompleted($tx));

            Log::info("Funds released successfully for transaction {$tx->reference} to {$receiver->email}");
        });
    }

    public function failed(Throwable $exception): void
    {
        $tx = Transaction::find($this->transactionId);

        if ($tx) {
            $tx->in_progress = false;
            $tx->save();
        }

        Log::error("ReleaseFundsJob FAILED for transaction ID {$this->transactionId}: {$exception->getMessage()}");

        TransactionLog::create([
            'transaction_id' => $this->transactionId,
            'action' => 'release_failed',
            'note' => "Job failed: " . $exception->getMessage(),
        ]);
    }

    /**
     * Optional: Exponential backoff for retries
     */
    public function backoff(): array
    {
        return [30, 60, 120]; // seconds between retries
    }
}
