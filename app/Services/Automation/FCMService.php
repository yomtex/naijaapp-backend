<?php
// app/Services/Automation/FCMService.php
namespace App\Services\Automation;

use Illuminate\Support\Facades\Http;

class FCMService
{
    public function sendForceLogout(string $fcmToken): void
    {
        $serverKey = config('services.fcm.server_key');
        if (!$serverKey || !$fcmToken) return;

        Http::withHeaders([
            'Authorization' => 'key='.$serverKey,
            'Content-Type' => 'application/json',
        ])->post('https://fcm.googleapis.com/fcm/send', [
            'to' => $fcmToken,
            'priority' => 'high',
            'notification' => [
                'title' => 'Logged out',
                'body'  => 'You were logged out because your account was used on another device.',
            ],
            'data' => ['event' => 'force_logout']
        ]);
    }
}
