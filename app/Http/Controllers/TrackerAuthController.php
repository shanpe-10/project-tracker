<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TrackerAuthController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $request->validate(['action'=>['required','string','max:80']]);
        $action = (string) $request->input('action', '');
        if ($action === 'session') {
            $user = $this->currentSessionUser($request);

            return response()->json(['ok' => true, 'authenticated' => $user !== null, 'user' => $user, 'csrfToken' => $user ? $request->session()->get('tracker_csrf') : null,
                'sessionTiming' => $user ? \App\Support\TrackerSession::timing($request, User::findOrFail($user['id'])) : null,
                ...($request->attributes->get('tracker_session_failure') ?? [])]);
        }
        if ($action === 'login') {
            return $this->login($request);
        }
        if (in_array($action, ['register', 'registrationStatus'], true)) {
            return response()->json(['ok' => false, 'message' => 'For a new account, please contact the IT department (Superadmin).'], 403);
        }

        $current = $this->currentSessionUser($request);
        if (! $current) {
            return response()->json(['ok' => false, ...($request->attributes->get('tracker_session_failure') ?? ['message' => 'Authentication required.'])], 401);
        }
        if (! $this->validCsrf($request)) {
            return response()->json(['ok' => false, 'message' => 'The security token is invalid.'], 419);
        }
        if ($action === 'logout') {
            TrackerProjectPresenceController::endSession($request);
            \App\Support\TrackerSession::end($request);
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['ok' => true]);
        }
        if ($action === 'activity') {
            $account = User::findOrFail($current['id']);
            \App\Support\TrackerSession::activity($request, $account);
            return response()->json(['ok' => true, 'sessionTiming' => \App\Support\TrackerSession::timing($request, $account)]);
        }
        $activityAction = in_array($action, ['listAuditLog','clearAuditLogView'], true);
        if (($activityAction && $current['role'] !== 'admin') || (! $activityAction && $current['role'] !== 'superadmin')) {
            return response()->json(['ok' => false, 'message' => $activityAction ? 'Only Admin can access recent activity.' : 'Only Superadmin can manage accounts.'], 403);
        }

        return DB::transaction(fn () => $this->adminAction($request, $current, $action));
    }

    private function login(Request $request): JsonResponse
    {
        $request->validate(['username'=>['nullable','string','max:80'],'password'=>['nullable','string','max:4096']]);
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        if ($username === '' || $password === '') {
            return response()->json(['ok' => false, 'message' => 'Enter your username and password.'], 422);
        }
        $key = hash('sha256', strtolower($username)."\0".$request->ip());
        $attempt = DB::table('login_attempts')->where('attempt_key', $key)->first();
        if ($attempt?->locked_until && strtotime($attempt->locked_until) > time()) {
            return response()->json(['ok' => false, 'message' => 'Too many unsuccessful sign-in attempts. Try again in 15 minutes.'], 429);
        }
        $account = User::where('username', $username)->first();
        if (! $account || ! Hash::check($password, $account->password_hash)) {
            $locked = DB::transaction(function () use ($key) {
                DB::table('login_attempts')->insertOrIgnore(['attempt_key'=>$key,'failed_count'=>0,'first_failed_at'=>now(),'updated_at'=>now()]);
                $attempt=DB::table('login_attempts')->where('attempt_key',$key)->lockForUpdate()->first();
                if ($attempt->locked_until && strtotime($attempt->locked_until)>time()) return $attempt->locked_until;
                $expired=strtotime($attempt->first_failed_at)<time()-900;
                $count=$expired ? 1 : (int)$attempt->failed_count+1;
                $locked=$count>=5 ? now()->addMinutes(15) : null;
                DB::table('login_attempts')->where('attempt_key',$key)->update(['failed_count'=>$count,'first_failed_at'=>$expired ? now() : $attempt->first_failed_at,'locked_until'=>$locked,'updated_at'=>now()]);
                return $locked;
            });

            return response()->json(['ok' => false, 'message' => $locked ? 'Too many unsuccessful sign-in attempts. Try again in 15 minutes.' : 'The username or password is incorrect.'], $locked ? 429 : 401);
        }
        if (! $account->active) {
            return response()->json(['ok' => false, 'message' => 'Your account request is awaiting Superadmin approval.'], 403);
        }
        // A verified sign-in makes an older password viewable without changing it.
        // Keep a concurrent password reset from being overwritten by this login.
        User::whereKey($account->id)->where('password_hash', $account->password_hash)
            ->update(['password_encrypted' => Crypt::encryptString($password)]);
        DB::table('login_attempts')->where('attempt_key', $key)->delete();
        $request->session()->regenerate();
        $user = $this->userPayload($account);
        $csrf = Str::random(64);
        $request->session()->put(['tracker_user' => $user, 'tracker_csrf' => $csrf, 'tracker_created_at' => time(), 'tracker_last_activity' => time()]);
        \App\Support\TrackerSession::start($request, $account);

        return response()->json(['ok' => true, 'user' => $user, 'csrfToken' => $csrf, 'sessionTiming' => \App\Support\TrackerSession::timing($request, $account)]);
    }

    private function adminAction(Request $request, array $current, string $action): JsonResponse
    {
        if ($action === 'revealUserPassword') return $this->revealPassword($request, $current);
        if ($action === 'revealAllUserPasswords') return $this->revealAllPasswords($request, $current);
        $originalSuperadminId = \App\Support\TrackerAccountProtection::originalSuperadminId();
        if ($action === 'listManagedUsers') {
            $users = User::orderByRaw("CASE role WHEN 'superadmin' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('active')->orderBy('created_at')->get()->map(fn (User $u) => $this->userPayload($u) + ['passwordAvailable' => ! empty($u->password_encrypted), 'isOriginalSuperadmin' => (int) $u->id === $originalSuperadminId, 'active' => (bool) $u->active, 'createdAt' => \Carbon\CarbonImmutable::parse($u->created_at, 'UTC')->toIso8601String()]);

            return response()->json(['ok' => true, 'users' => $users]);
        }
        if ($action === 'listAuditLog') {
            $cutoff = json_decode((string) (DB::table('application_settings')->where('setting_key', 'audit_visible_after')->value('setting_value') ?: 'null'), true) ?: '1970-01-01 00:00:00';
            $entries = DB::table('audit_log')->where('created_at', '>=', $cutoff)->orderByDesc('id')->limit(100)->get()->map(fn ($e) => ['actorUsername' => $e->actor_username, 'action' => $e->action, 'entityType' => $e->entity_type, 'entityId' => $e->entity_id, 'summary' => $e->summary, 'createdAt' => \Carbon\CarbonImmutable::parse($e->created_at, 'UTC')->toIso8601String()]);

            return response()->json(['ok' => true, 'entries' => $entries]);
        }
        if ($action === 'clearAuditLogView') {
            if (! hash_equals('CLEAR HISTORY', (string) $request->input('confirmation', ''))) {
                return response()->json(['ok' => false, 'message' => 'The approval phrase did not match.'], 422);
            }
            DB::table('application_settings')->updateOrInsert(['setting_key' => 'audit_visible_after'], ['setting_value' => json_encode(now()->format('Y-m-d H:i:s')), 'updated_by' => $current['id'], 'updated_at' => now()]);
            $this->audit($current, 'audit.history_cleared', 'audit_log', null, 'Cleared the visible recent activity history.');

            return response()->json(['ok' => true, 'message' => 'Recent activity cleared. Older entries remain preserved.']);
        }
        if ($action === 'createUser' || $action === 'updateUser') return $this->saveAccount($request, $current, $action);
        $request->validate(['id'=>['required','integer','min:1'],'permission'=>['sometimes','string'],'allowed'=>['sometimes','boolean']]);
        User::orderBy('id')->lockForUpdate()->get();
        $id = (int) $request->input('id');
        $target = User::find($id);
        if (! $target) {
            return response()->json(['ok' => false, 'message' => 'The account was not found.'], 404);
        }
        if ((int) $target->id === $originalSuperadminId && in_array($action, ['rejectUser','deleteUser'], true)) {
            return response()->json(['ok'=>false,'message'=>'The original Superadmin account is protected and cannot be deleted.'],422);
        }
        if ($target->id === $current['id'] && in_array($action, ['rejectUser','deleteUser'], true)) {
            return response()->json(['ok'=>false,'message'=>'You cannot delete your signed-in account.'],422);
        }
        if (in_array($action, ['rejectUser','deleteUser'], true) && $target->role === 'superadmin'
            && User::where('role','superadmin')->where('active',true)->count() <= 1) {
            return response()->json(['ok'=>false,'message'=>'Keep at least one active Superadmin account.'],422);
        }
        if ($action === 'approveUser') {
            $target->active = true;
            $target->save();
        } elseif ($action === 'rejectUser' || $action === 'deleteUser') {
            $target->delete();
        } elseif ($action === 'setUserPermission') {
            $columns = ['canCreate' => 'can_create', 'canEdit' => 'can_edit', 'canImport' => 'can_import', 'canExport' => 'can_export', 'canDelete' => 'can_delete', 'canDeleteAll' => 'can_delete_all', 'canResolveAlerts' => 'can_resolve_alerts', 'canArchive' => 'can_archive'];
            $column = $columns[(string) $request->input('permission')] ?? null;
            if (! $column) {
                return response()->json(['ok' => false, 'message' => 'Invalid permission.'], 422);
            }
            if ($target->role !== 'staff') return response()->json(['ok'=>false,'message'=>'Project permissions apply to Staff accounts only.'],422);
            $target->{$column} = $request->boolean('allowed');
            $target->save();
        } else {
            return response()->json(['ok' => false, 'message' => 'Unsupported authentication request.'], 400);
        }
        $this->audit($current, 'account.'.$action, 'account', $id, 'Managed account '.$target->username.'.');

        return response()->json(['ok' => true, 'message' => 'Account updated.']);
    }

    private function saveAccount(Request $request, array $current, string $action): JsonResponse
    {
        $creating = $action === 'createUser';
        $allowedRoles = ['staff', 'admin', 'superadmin'];
        $data = $request->validate([
            'id'=>[$creating ? 'nullable' : 'required','integer','min:1'],
            'username'=>['required','regex:/^[A-Za-z0-9._-]{3,80}$/'],
            'fullName'=>['required','string','max:160'],
            'role'=>['required',\Illuminate\Validation\Rule::in($allowedRoles)],
            'active'=>['required','boolean'],
            'password'=>[$creating ? 'required' : 'nullable','string','min:12',\App\Support\TrackerPassword::rule()],
        ]);
        return DB::transaction(function () use ($data,$creating,$current) {
            // Serialize account changes, including the last-Superadmin guard.
            User::orderBy('id')->lockForUpdate()->get();
            $target = $creating ? new User() : User::find($data['id']);
            if (! $target) return response()->json(['ok'=>false,'message'=>'Account not found.'],404);
            if (User::where('username',$data['username'])->when(! $creating,fn ($q)=>$q->where('id','<>',$target->id))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['username'=>'This username is already used.']);
            }
            if (! $creating && $target->id === $current['id'] && ($data['role'] !== 'superadmin' || ! $data['active'])) {
                return response()->json(['ok'=>false,'message'=>'You cannot disable or change the role of your signed-in Superadmin account.'],422);
            }
            if (! $creating && $target->role === 'superadmin' && ($data['role'] !== 'superadmin' || ! $data['active'])
                && User::where('role','superadmin')->where('active',true)->where('id','<>',$target->id)->count() === 0) {
                return response()->json(['ok'=>false,'message'=>'Keep at least one active Superadmin account.'],422);
            }
            $target->fill(['username'=>trim($data['username']),'full_name'=>trim($data['fullName']),'role'=>$data['role'],'active'=>$data['active']]);
            if (! empty($data['password'])) {
                $target->password_hash = Hash::make($data['password']);
                $target->password_encrypted = Crypt::encryptString($data['password']);
            }
            $target->save();
            $passwordChanged = !$creating && !empty($data['password']);
            if ($passwordChanged) {
                DB::table('tracker_account_sessions')->where('user_id', $target->id)->delete();
                DB::table('project_editing_presence')->where('user_id', $target->id)->delete();
            }
            $this->audit($current,$creating ? 'account.created' : 'account.updated','account',$target->id,'Managed account '.$target->username.'.');
            return response()->json(['ok'=>true,'message'=>$creating ? 'Account created.' : 'Account updated.', 'signInRequired' => $passwordChanged && (int)$target->id === (int)$current['id']]);
        });
    }

    private function authorizePasswordView(Request $request, array $current): User|JsonResponse
    {
        $request->validate(['currentPassword' => 'required|string|max:4096']);
        $key = 'password-view:'.$current['id'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['ok' => false, 'message' => 'Too many incorrect passwords. Try again in five minutes.'], 429);
        }
        $actor = User::findOrFail($current['id']);
        if ($actor->role !== 'superadmin' || ! $actor->active) {
            return response()->json(['ok' => false, 'message' => 'Only an active Superadmin can view passwords.'], 403);
        }
        if (! Hash::check($request->input('currentPassword'), $actor->password_hash)) {
            RateLimiter::hit($key, 300);
            return response()->json(['ok' => false, 'message' => 'Your Superadmin password is incorrect.'], 403);
        }
        RateLimiter::clear($key);
        return $actor;
    }

    private function readablePassword(User $target, User $actor, string $currentPassword): ?string
    {
        // The Superadmin just supplied their own verified password.
        if ((int) $target->id === (int) $actor->id && Hash::check($currentPassword, $target->password_hash)) return $currentPassword;
        if (! $target->password_encrypted) return null;
        try {
            $password = Crypt::decryptString($target->password_encrypted);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return null;
        }
        // Never show an old password after a separate maintenance reset.
        return Hash::check($password, $target->password_hash) ? $password : null;
    }

    private function revealPassword(Request $request, array $current): JsonResponse
    {
        if (! $request->isMethod('post')) return response()->json(['ok' => false, 'message' => 'Use POST to view a password.'], 405);
        $request->validate(['id' => 'required|integer|min:1']);
        $actor = $this->authorizePasswordView($request, $current);
        if ($actor instanceof JsonResponse) return $actor;
        $target = User::find($request->integer('id'));
        if (! $target) return response()->json(['ok' => false, 'message' => 'Account not found.'], 404);
        $password = $this->readablePassword($target, $actor, $request->input('currentPassword'));
        if ($password === null) return response()->json(['ok' => false, 'passwordAvailable' => false, 'message' => 'This password will be available after the user next signs in. You can also set a new password.'], 409);
        $this->audit($current, 'account.password_viewed', 'account', $target->id, 'Viewed the password for account '.$target->username.'.');
        return response()->json(['ok' => true, 'password' => $password])->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }

    private function revealAllPasswords(Request $request, array $current): JsonResponse
    {
        if (! $request->isMethod('post')) return response()->json(['ok' => false, 'message' => 'Use POST to view passwords.'], 405);
        $actor = $this->authorizePasswordView($request, $current);
        if ($actor instanceof JsonResponse) return $actor;
        $accounts = User::orderByRaw("CASE role WHEN 'superadmin' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('username')->get()
            ->map(function (User $target) use ($request, $actor) {
                $password = $this->readablePassword($target, $actor, $request->input('currentPassword'));
                return ['id' => (int) $target->id, 'fullName' => $target->full_name, 'username' => $target->username,
                    'role' => $target->role, 'active' => (bool) $target->active, 'passwordAvailable' => $password !== null, 'password' => $password];
            });
        $available = $accounts->where('passwordAvailable', true)->count();
        $this->audit($current, 'account.passwords_viewed', 'account', null, 'Opened all account passwords: '.$available.' available, '.($accounts->count() - $available).' awaiting sign-in.');
        return response()->json(['ok' => true, 'accounts' => $accounts, 'total' => $accounts->count(), 'availableCount' => $available])
            ->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }

    private function userPayload(User $u): array
    {
        return ['id' => (int) $u->id, 'username' => $u->username, 'fullName' => $u->full_name, 'profilePhoto' => $u->profile_photo ? 'api/profile.php?action=photo&userId='.(int) $u->id.'&v='.rawurlencode((string) ($u->profile_photo_updated_at ?? '')) : null, 'role' => $u->role, ...\App\Support\TrackerAccess::capabilities($u)];
    }

    private function currentSessionUser(Request $request): ?array
    {
        $saved = $request->session()->get('tracker_user');
        if (! is_array($saved)) {
            return null;
        }
        $account = User::find((int) ($saved['id'] ?? 0));
        if (! $account || ! $account->active) {
            $request->session()->invalidate();

            return null;
        }
        if ($failure = \App\Support\TrackerSession::failure($request, $account)) {
            $request->attributes->set('tracker_session_failure', $failure);
            TrackerProjectPresenceController::endSession($request);
            \App\Support\TrackerSession::end($request);
            $request->session()->invalidate();
            return null;
        }
        $user = $this->userPayload($account);
        $request->session()->put('tracker_user', $user);

        return $user;
    }

    private function validCsrf(Request $request): bool
    {
        $expected = (string) $request->session()->get('tracker_csrf', '');

        return $expected !== '' && hash_equals($expected, (string) $request->header('X-CSRF-Token', ''));
    }

    private function audit(array $user, string $action, string $type, ?int $id, string $summary): void
    {
        DB::table('audit_log')->insert(['actor_id' => $user['id'], 'actor_username' => $user['username'], 'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'summary' => $summary, 'created_at' => now()]);
    }
}
