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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use Throwable;

class ReleaseFundsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5; // Retry up to 5 times
    public int $timeout = 60; // Max execution time per try (seconds)

    protected int $transactionId;

    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $tx = Transaction::lockForUpdate()->find($this->transactionId);

            if (!$tx || $tx->status !== 'pending') {
                return; // Already processed or missing
            }

            $receiver = $tx->receiver;
            if (!$receiver) {
                Log::warning("ReleaseFundsJob: No receiver found for transaction ID {$tx->id}");
                // Clear in_progress so it can be retried later
                $tx->in_progress = false;
                $tx->save();
                return;
            }

            $receiver->balance += $tx->amount;
            $receiver->available_balance += $tx->amount;
            $receiver->save();

            $tx->status = 'completed';
            $tx->processed_at = Carbon::now();
            $tx->in_progress = false; // Clear flag on success
            $tx->save();

            // Notify (uncomment in production)
            // $receiver->notify(new TransactionCompleted($tx));

            Log::info("Funds released for transaction {$tx->reference} to receiver {$receiver->email}");

            TransactionLog::create([
                'transaction_id' => $tx->id,
                'action' => 'released',
                'note' => 'Funds auto-released after scheduled hold.',
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        $tx = Transaction::find($this->transactionId);

        if ($tx) {
            // Clear in_progress flag so it can be retried later
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

}
