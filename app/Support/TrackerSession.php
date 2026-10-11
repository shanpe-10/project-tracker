<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackerSession
{
    public const IDLE_SECONDS = 1800;
    public const MAX_SECONDS = 28800;

    public static function start(Request $request, User $account): void
    {
        DB::transaction(function () use ($request, $account) {
            // Serialize simultaneous logins for this account: the latest one wins.
            User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $token = Str::random(64);
            $now = now()->getTimestamp();
            DB::table('tracker_account_sessions')->updateOrInsert(['user_id' => $account->id], [
                'token_hash' => hash('sha256', $token), 'started_at' => $now, 'last_activity_at' => $now,
            ]);
            DB::table('project_editing_presence')->where('user_id', $account->id)->delete();
            $request->session()->put('tracker_session_token', $token);
        });
    }

    public static function failure(Request $request, User $account): ?array
    {
        $token = (string) $request->session()->get('tracker_session_token', '');
        $session = DB::table('tracker_account_sessions')->where('user_id', $account->id)->first();
        if ($token === '' || ! $session) {
            return ['reason' => 'session_ended', 'message' => 'Your session has ended. Please sign in again.'];
        }
        if (! hash_equals($session->token_hash, hash('sha256', $token))) {
            return ['reason' => 'session_replaced', 'message' => 'Your account was signed in elsewhere. Please sign in again.'];
        }
        $now = now()->getTimestamp();
        if ($now >= $session->started_at + self::MAX_SECONDS) {
            return ['reason' => 'session_expired', 'message' => 'Your eight-hour session has ended. Please sign in again.'];
        }
        if ($account->role !== 'superadmin' && $now >= $session->last_activity_at + self::IDLE_SECONDS) {
            return ['reason' => 'session_idle', 'message' => 'You were signed out after 30 minutes of inactivity. Please sign in again.'];
        }
        return null;
    }

    public static function end(Request $request): void
    {
        $user = $request->session()->get('tracker_user');
        $token = (string) $request->session()->get('tracker_session_token', '');
        if (! is_array($user) || $token === '') return;
        // A late logout from an old browser must never revoke the new login.
        DB::table('tracker_account_sessions')->where('user_id', (int) ($user['id'] ?? 0))
            ->where('token_hash', hash('sha256', $token))->delete();
    }

    public static function activity(Request $request, User $account): void
    {
        DB::table('tracker_account_sessions')->where('user_id', $account->id)
            ->where('token_hash', hash('sha256', (string) $request->session()->get('tracker_session_token')))
            ->update(['last_activity_at' => now()->getTimestamp()]);
    }

    public static function timing(Request $request, User $account): array
    {
        $session = DB::table('tracker_account_sessions')->where('user_id', $account->id)
            ->where('token_hash', hash('sha256', (string) $request->session()->get('tracker_session_token')))->first();
        return [
            'serverNow' => now()->getTimestamp(),
            'expiresAt' => $session ? $session->started_at + self::MAX_SECONDS : null,
            'idleExpiresAt' => $session && $account->role !== 'superadmin' ? $session->last_activity_at + self::IDLE_SECONDS : null,
        ];
    }
}
