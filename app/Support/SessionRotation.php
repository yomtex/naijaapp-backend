<?php
// app/Support/SessionRotation.php
namespace App\Support;

use App\Models\RefreshToken;
use App\Services\Automation\FCMService;

class SessionRotation
{
    public function __construct(private FCMService $fcm) {}

    /**
     * Revoke all old sessions for a user and send push notifications,
     * except optionally one session (the one that just logged in).
     */
    public function pushAndRevokeOldSessions(int $userId, ?int $exceptTokenId = null): void
    {
        $query = RefreshToken::where('user_id', $userId)->active();

        if ($exceptTokenId) {
            $query->where('id', '!=', $exceptTokenId);
        }

        $oldSessions = $query->get();

        foreach ($oldSessions as $session) {
            if ($session->fcm_token) {
                try {
                    $this->fcm->sendForceLogout($session->fcm_token);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        RefreshToken::whereIn('id', $oldSessions->pluck('id'))
            ->update(['revoked_at' => now()]);
    }
}
