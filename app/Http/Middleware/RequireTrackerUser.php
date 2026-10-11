<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Http\Controllers\TrackerProjectPresenceController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTrackerUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionUser = $request->session()->get('tracker_user');
        if (! is_array($sessionUser)) {
            return response()->json(['ok' => false, 'message' => 'Authentication required.'], 401);
        }

        $account = User::find((int) ($sessionUser['id'] ?? 0));
        if (! $account || ! $account->active) {
            $request->session()->invalidate();

            return response()->json(['ok' => false, 'message' => 'Your account is no longer active. Please sign in again.'], 401);
        }
        if ($failure = \App\Support\TrackerSession::failure($request, $account)) {
            TrackerProjectPresenceController::endSession($request);
            \App\Support\TrackerSession::end($request);
            $request->session()->invalidate();
            return response()->json(['ok' => false, ...$failure], 401);
        }
        $request->session()->put('tracker_user', [
            'id' => (int) $account->id, 'username' => $account->username,
            'fullName' => $account->full_name, 'profilePhoto' => $sessionUser['profilePhoto'] ?? null,
            'role' => $account->role,
            ...\App\Support\TrackerAccess::capabilities($account),
        ]);

        if ($request->isMethod('post')) {
            $sent = (string) $request->header('X-CSRF-Token', '');
            $expected = (string) $request->session()->get('tracker_csrf', '');
            if ($expected === '' || ! hash_equals($expected, $sent)) {
                return response()->json(['ok' => false, 'message' => 'The security token is invalid. Refresh and try again.'], 419);
            }
        }

        if ($account->role === 'superadmin' && ! $request->is('api/profile.php', 'api/holidays.php')) {
            return response()->json(['ok' => false, 'message' => 'Superadmin access is limited to account management and holiday settings.'], 403);
        }

        return $next($request);
    }
}
