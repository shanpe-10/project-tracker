<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TrackerDeletionPassword
{
    public static function verify(Request $request, int $userId): void
    {
        $data = $request->validate(['current_password' => 'required|string|max:4096'], [
            'current_password.required' => 'Enter your Admin password to confirm deletion.',
        ]);
        $account = User::find($userId);
        if (! $account || ! $account->active || $account->role !== 'admin') {
            self::fail(403, 'Only an active Admin can confirm deletion.');
        }

        $key = hash('sha256', "admin-deletion-password\0".$userId);
        $result = DB::transaction(function () use ($key, $account, $data) {
            DB::table('login_attempts')->insertOrIgnore([
                'attempt_key' => $key, 'failed_count' => 0, 'first_failed_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('login_attempts')->where('attempt_key', $key);
            $attempt = $query->lockForUpdate()->first();
            if ($attempt->locked_until && strtotime($attempt->locked_until) > time()) return 'locked';
            if (Hash::check($data['current_password'], $account->password_hash)) {
                $query->delete();
                return 'verified';
            }
            $expired = strtotime($attempt->first_failed_at) < time() - 900;
            $count = $expired ? 1 : (int) $attempt->failed_count + 1;
            $query->update([
                'failed_count' => $count, 'first_failed_at' => $expired ? now() : $attempt->first_failed_at,
                'locked_until' => $count >= 5 ? now()->addMinutes(15) : null, 'updated_at' => now(),
            ]);
            return $count >= 5 ? 'locked' : 'incorrect';
        });
        if ($result !== 'verified') {
            self::fail($result === 'locked' ? 429 : 403, $result === 'locked'
                ? 'Too many attempts to confirm your Admin password. Try again in 15 minutes.'
                : 'Your Admin password is incorrect. No records were deleted.');
        }
    }

    private static function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['ok' => false, 'message' => $message], $status));
    }
}
