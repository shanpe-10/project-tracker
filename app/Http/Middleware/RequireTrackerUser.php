<?php

namespace App\Http\Middleware;

use App\Models\User;
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

        if (\App\Support\TrackerSession::expired($request)) {
            $request->session()->invalidate();

            return response()->json(['ok' => false, 'message' => 'Your session has expired.'], 401);
        }

        $account = User::find((int) ($sessionUser['id'] ?? 0));
        if (! $account || ! $account->active) {
            $request->session()->invalidate();

            return response()->json(['ok' => false, 'message' => 'Your account is no longer active. Please sign in again.'], 401);
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

        $request->session()->put('tracker_last_activity', time());

        if ($account->role === 'superadmin' && ! $request->is('api/profile.php')) {
            return response()->json(['ok' => false, 'message' => 'Superadmin access is limited to account management.'], 403);
        }

        return $next($request);
    }
}
