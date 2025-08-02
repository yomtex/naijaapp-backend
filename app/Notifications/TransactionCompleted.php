<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Modules\Transaction\Models\Transaction;

class TransactionCompleted extends Notification
{
    use Queueable;

    public function __construct(public Transaction $transaction) {}

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $tx = $this->transaction;

        return (new MailMessage)
            ->subject('Funds Received from ' . $tx->sender->name)
            ->line("You received ₦{$tx->amount} from {$tx->sender->name}")
            ->line("Purpose: " . ucwords(str_replace('_', ' ', $tx->purpose)))
            ->line('Reference: ' . $tx->reference)
            ->line('Status: Completed')
            ->line('Thank you for using 9jaPay!');
    }
}
