<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\TrackerAccountProtection;
use App\Support\TrackerInitialAdmin;
use App\Support\TrackerPassword;
use App\Support\TrackerAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            return response()->json(['ok' => false, 'message' => 'Contact Admin for a new account.'], 403);
        }

        $current = $this->currentSessionUser($request);
        if (! $current) {
            return response()->json(['ok' => false, ...($request->attributes->get('tracker_session_failure') ?? ['message' => 'Authentication required.'])], 401);
        }
        if (! $this->validCsrf($request)) {
            return response()->json(['ok' => false, 'message' => 'The security token is invalid.'], 419);
        }
        if ($action === 'logout') {
            $this->audit($current, 'account.signed_out', 'account', $current['id'], 'Signed out of Project Tracker.');
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
        if ($current['role'] === 'superadmin') {
            if ($action === 'listAuditLog') return $this->listAuditLog($request);
            if ($action === 'getAuditLogEntry') return $this->auditLogEntry($request);
            return response()->json(['ok' => false, 'message' => 'This account has read-only accountability access.'], 403);
        }
        if ($action === 'getAuditLogEntry') return response()->json(['ok' => false, 'message' => 'Detailed audit access is restricted.'], 403);
        if ($current['role'] !== 'admin') {
            return response()->json(['ok' => false, 'message' => 'Only Admin can manage accounts and recent activity.'], 403);
        }
        if (in_array($action, ['revealUserPassword', 'revealAllUserPasswords'], true)) {
            return response()->json(['ok' => false, 'message' => 'Set a new password from the Admin account directory.'], 410);
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
        if ($this->passwordAttemptLocked($key)) {
            return response()->json(['ok' => false, 'message' => 'Too many unsuccessful sign-in attempts. Try again in 15 minutes.'], 429);
        }
        $account = User::where('username', $username)->first();
        if (! $account || ! Hash::check($password, $account->password_hash)) {
            $locked = $this->recordFailedPasswordAttempt($key);
            return response()->json(['ok' => false, 'message' => $locked ? 'Too many unsuccessful sign-in attempts. Try again in 15 minutes.' : 'The username or password is incorrect.'], $locked ? 429 : 401);
        }
        if (! $account->active) {
            return response()->json(['ok' => false, 'message' => 'Your account is inactive. Contact Admin for access.'], 403);
        }
        $setup = TrackerInitialAdmin::pending();
        if ((int) ($setup['id'] ?? 0) === (int) $account->id) {
            $activation = $this->activateInitialAdmin($request, $account, $key);
            if ($activation instanceof JsonResponse) {
                return $activation;
            }
            $account = $activation;
        }
        DB::table('login_attempts')->where('attempt_key', $key)->delete();
        $request->session()->regenerate();
        $user = $this->userPayload($account);
        $csrf = Str::random(64);
        $request->session()->put(['tracker_user' => $user, 'tracker_csrf' => $csrf, 'tracker_created_at' => time(), 'tracker_last_activity' => time()]);
        \App\Support\TrackerSession::start($request, $account);
        $this->audit($user, 'account.signed_in', 'account', $account->id, 'Signed in to Project Tracker.');

        return response()->json(['ok' => true, 'user' => $user, 'csrfToken' => $csrf, 'sessionTiming' => \App\Support\TrackerSession::timing($request, $account)]);
    }

    private function activateInitialAdmin(Request $request, User $account, string $key): JsonResponse|User
    {
        if (! in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            || ! in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            || ! $request->isJson()) {
            return response()->json(['ok' => false, 'message' => 'Finish Admin setup from the host desktop shortcut.'], 403);
        }
        if (! $request->filled('setup_code')) {
            return response()->json([
                'ok' => false, 'requiresPasswordSetup' => true,
                'message' => 'Enter the one-time code shown by Host Setup and choose a new Admin password.',
            ], 428);
        }
        $data = $request->validate([
            'setup_code' => ['required', 'string', 'regex:/^(?:[0-9A-F]{4}-){3}[0-9A-F]{4}$/iD'],
            'new_password' => ['required', 'string', 'min:12', 'confirmed', TrackerPassword::rule()],
        ], ['setup_code.regex' => 'Enter the setup code as shown by Host Setup.']);
        if (Hash::check($data['new_password'], $account->password_hash)) {
            return response()->json(['ok' => false, 'message' => 'Choose a different password from the starter password.'], 422);
        }

        $activated = TrackerInitialAdmin::activate($account, $data['setup_code'], $data['new_password']);
        if ($activated === null) {
            $locked = $this->recordFailedPasswordAttempt($key);

            return response()->json([
                'ok' => false,
                'message' => $locked ? 'Too many unsuccessful setup attempts. Try again in 15 minutes.' : 'The setup code is incorrect or has already been used.',
            ], $locked ? 429 : 403);
        }

        return $activated;
    }

    private function passwordAttemptLocked(string $key): bool
    {
        $until = DB::table('login_attempts')->where('attempt_key', $key)->value('locked_until');

        return $until !== null && strtotime($until) > time();
    }

    private function recordFailedPasswordAttempt(string $key): bool
    {
        return DB::transaction(function () use ($key) {
            DB::table('login_attempts')->insertOrIgnore([
                'attempt_key' => $key, 'failed_count' => 0, 'first_failed_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('login_attempts')->where('attempt_key', $key);
            $attempt = $query->lockForUpdate()->first();
            if ($attempt->locked_until && strtotime($attempt->locked_until) > time()) return true;
            $expired = strtotime($attempt->first_failed_at) < time() - 900;
            $count = $expired ? 1 : (int) $attempt->failed_count + 1;
            $locked = $count >= 5;
            $query->update([
                'failed_count' => $count, 'first_failed_at' => $expired ? now() : $attempt->first_failed_at,
                'locked_until' => $locked ? now()->addMinutes(15) : null, 'updated_at' => now(),
            ]);

            return $locked;
        });
    }

    private function confirmAdminPassword(User $actor, string $password): ?JsonResponse
    {
        $key = hash('sha256', "admin-password-reset\0".$actor->id);
        $locked = $this->passwordAttemptLocked($key);
        if (! $locked) {
            if (Hash::check($password, $actor->password_hash)) {
                DB::table('login_attempts')->where('attempt_key', $key)->delete();

                return null;
            }
            $locked = $this->recordFailedPasswordAttempt($key);
        }

        return response()->json([
            'ok' => false,
            'message' => $locked ? 'Too many attempts to confirm your Admin password. Try again in 15 minutes.' : 'Your Admin password is incorrect.',
        ], $locked ? 429 : 403);
    }

    private function adminAction(Request $request, array $current, string $action): JsonResponse
    {
        if ($action === 'listManagedUsers') {
            $originalAdminId = TrackerAccountProtection::originalAdminId();
            $retiredId = TrackerAccountProtection::retiredLegacyAdminId();
            $users = User::query()
                ->whereIn('role', ['admin', 'staff'])
                ->when($retiredId !== null, fn ($query) => $query->whereKeyNot($retiredId))
                ->orderBy('role')->orderBy('active')->orderBy('created_at')->get()
                ->map(fn (User $u) => $this->userPayload($u) + ['isOriginalAdmin' => (int) $u->id === $originalAdminId, 'active' => (bool) $u->active, 'createdAt' => \Carbon\CarbonImmutable::parse($u->created_at, 'UTC')->toIso8601String()]);

            return response()->json(['ok' => true, 'users' => $users]);
        }
        if ($action === 'listAuditLog') {
            return $this->listAuditLog($request);
        }
        if ($action === 'clearAuditLogView') {
            if (! hash_equals('CLEAR HISTORY', (string) $request->input('confirmation', ''))) {
                return response()->json(['ok' => false, 'message' => 'The approval phrase did not match.'], 422);
            }
            DB::table('application_settings')->updateOrInsert(['setting_key' => 'audit_visible_after'], ['setting_value' => json_encode(now()->format('Y-m-d H:i:s')), 'updated_by' => $current['id'], 'updated_at' => now()]);
            $this->audit($current, 'audit.history_cleared', 'audit_log', null, 'Cleared the visible recent activity history.');

            return response()->json(['ok' => true, 'message' => 'Recent activity cleared. Older entries remain preserved.']);
        }
        $originalAdminId = TrackerAccountProtection::originalAdminId();
        if ($action === 'createUser' || $action === 'updateUser') return $this->saveAccount($request, $current, $action);
        $request->validate(['id'=>['required','integer','min:1'],'permission'=>['sometimes','string'],'allowed'=>['sometimes','boolean']]);
        $accounts = User::orderBy('id')->lockForUpdate()->get();
        $actor = $accounts->find($current['id']);
        if ($actor?->role !== 'admin' || ! $actor->active) {
            return response()->json(['ok' => false, 'message' => 'Admin access is required.'], 403);
        }
        $id = (int) $request->input('id');
        $target = User::find($id);
        if (! $target || $target->role === 'superadmin' || $id === TrackerAccountProtection::retiredLegacyAdminId()) {
            return response()->json(['ok' => false, 'message' => 'The account was not found.'], 404);
        }
        if ((int) $target->id === $originalAdminId && in_array($action, ['rejectUser','deleteUser'], true)) {
            return response()->json(['ok'=>false,'message'=>'The original Admin account is protected and cannot be deleted.'],422);
        }
        if ((int) $target->id === (int) $current['id'] && in_array($action, ['rejectUser','deleteUser'], true)) {
            return response()->json(['ok'=>false,'message'=>'You cannot delete your signed-in account.'],422);
        }
        if (in_array($action, ['rejectUser','deleteUser'], true) && $target->role === 'admin' && $target->active
            && User::where('role','admin')->where('active',true)->count() <= 1) {
            return response()->json(['ok'=>false,'message'=>'Keep at least one active Admin account.'],422);
        }
        $before = $this->accountEvidence($target);
        if ($action === 'approveUser') {
            $target->active = true;
            $target->save();
        } elseif ($action === 'rejectUser' || $action === 'deleteUser') {
            $target->delete();
        } elseif ($action === 'setUserPermission') {
            $columns = ['canCreate' => 'can_create', 'canEdit' => 'can_edit', 'canImport' => 'can_import', 'canExport' => 'can_export', 'canDelete' => 'can_delete', 'canResolveAlerts' => 'can_resolve_alerts', 'canArchive' => 'can_archive'];
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
        $this->audit($current, 'account.'.$action, 'account', $id, 'Managed account '.$target->username.'.',
            ['before' => $before, 'after' => in_array($action, ['rejectUser', 'deleteUser'], true) ? [] : $this->accountEvidence($target)]);

        return response()->json(['ok' => true, 'message' => 'Account updated.']);
    }

    private function saveAccount(Request $request, array $current, string $action): JsonResponse
    {
        $creating = $action === 'createUser';
        $resetting = ! $creating && $request->input('password') !== null && $request->input('password') !== '';
        $allowedRoles = ['staff', 'admin'];
        $data = $request->validate([
            'id'=>[$creating ? 'nullable' : 'required','integer','min:1'],
            'username'=>['required','regex:/^[A-Za-z0-9._-]{3,80}$/'],
            'fullName'=>['required','string','max:160'],
            'role'=>['required',\Illuminate\Validation\Rule::in($allowedRoles)],
            'active'=>['required','boolean'],
            'password'=>[$creating ? 'required' : 'nullable','string','min:12',\App\Support\TrackerPassword::rule()],
            'current_password'=>[$resetting ? 'required' : 'nullable','string','max:4096'],
        ], ['current_password.required' => 'Enter your own Admin password to continue.']);
        if (strcasecmp(trim($data['username']), 'superadmin') === 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['username' => 'This username is unavailable.']);
        }
        return DB::transaction(function () use ($data,$creating,$current,$resetting) {
            // Serialize account changes, including the last-Admin guard.
            $accounts = User::orderBy('id')->lockForUpdate()->get();
            $actor = $accounts->find($current['id']);
            if ($actor?->role !== 'admin' || ! $actor->active) {
                return response()->json(['ok' => false, 'message' => 'Admin access is required.'], 403);
            }
            $target = $creating ? new User() : User::find($data['id']);
            if (! $target || $target->role === 'superadmin' || (! $creating && (int) $target->id === TrackerAccountProtection::retiredLegacyAdminId())) {
                return response()->json(['ok'=>false,'message'=>'Account not found.'],404);
            }
            if (User::where('username',$data['username'])->when(! $creating,fn ($q)=>$q->where('id','<>',$target->id))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['username'=>'This username is already used.']);
            }
            if (! $creating && (int) $target->id === (int) $current['id'] && ($data['role'] !== 'admin' || ! $data['active'])) {
                return response()->json(['ok'=>false,'message'=>'You cannot disable or change the role of your signed-in Admin account.'],422);
            }
            if (! $creating && $target->role === 'admin' && ($data['role'] !== 'admin' || ! $data['active'])
                && User::where('role','admin')->where('active',true)->where('id','<>',$target->id)->count() === 0) {
                return response()->json(['ok'=>false,'message'=>'Keep at least one active Admin account.'],422);
            }
            if ($resetting && ($failure = $this->confirmAdminPassword($actor, $data['current_password']))) {
                return $failure;
            }
            $before = $creating ? [] : $this->accountEvidence($target);
            $target->fill(['username'=>trim($data['username']),'full_name'=>trim($data['fullName']),'role'=>$data['role'],'active'=>$data['active']]);
            if (! empty($data['password'])) {
                $target->password_hash = Hash::make($data['password']);
                $target->password_encrypted = null;
            }
            $target->save();
            if ($resetting) {
                DB::table('tracker_account_sessions')->where('user_id', $target->id)->delete();
                DB::table('project_editing_presence')->where('user_id', $target->id)->delete();
            }
            $this->audit($current,$creating ? 'account.created' : 'account.updated','account',$target->id,'Managed account '.$target->username.'.',
                ['before' => $before, 'after' => $this->accountEvidence($target), 'credentialReset' => $resetting]);
            return response()->json(['ok'=>true,'message'=>$creating ? 'Account created.' : 'Account updated.', 'signInRequired' => $resetting && (int)$target->id === (int)$current['id']]);
        });
    }

    private function userPayload(User $u): array
    {
        return ['id' => (int) $u->id, 'username' => $u->username, 'fullName' => $u->full_name, 'profilePhoto' => $u->profile_photo ? 'api/profile.php?action=photo&userId='.(int) $u->id.'&v='.rawurlencode((string) ($u->profile_photo_updated_at ?? '')) : null, 'role' => $u->role, ...\App\Support\TrackerAccess::capabilities($u)];
    }

    private function accountEvidence(User $user): array
    {
        return $user->only(['username', 'full_name', 'role', 'active', 'can_create', 'can_edit', 'can_import',
            'can_export', 'can_delete', 'can_delete_all', 'can_resolve_alerts', 'can_archive']);
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

    private function listAuditLog(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'fromDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
            'toDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'actor' => ['nullable', 'string', 'max:80'],
            'actionFilter' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);
        $from = $filters['fromDate'] ?? null;
        $to = $filters['toDate'] ?? null;
        if ($from && $to && $from > $to) {
            return response()->json(['ok' => false, 'message' => 'The From date must be on or before the To date.'], 422);
        }

        $cutoff = json_decode((string) (DB::table('application_settings')->where('setting_key', 'audit_visible_after')->value('setting_value') ?: 'null'), true) ?: '1970-01-01 00:00:00';
        $auditor = $request->session()->get('tracker_user.role') === 'superadmin';
        $query = DB::table('audit_log');
        if (!$auditor) {
            $query->where('created_at', '>=', $cutoff)
                ->where(fn($q) => $q->whereNull('actor_id')->orWhereNotIn('actor_id', User::where('role', 'superadmin')->select('id')));
            if (\Illuminate\Support\Facades\Schema::hasColumn('audit_log', 'metadata')) {
                $query->where(fn($q) => $q->whereNull('metadata')->orWhereRaw("COALESCE(metadata->>'visibility','') <> 'superadmin'"));
            }
        }
        foreach (['actor' => 'actor_username', 'actionFilter' => 'action'] as $filter => $column) {
            if ($value = trim((string)($filters[$filter] ?? ''))) $query->whereRaw("LOWER($column) LIKE ?", ['%'.mb_strtolower($value).'%']);
        }
        if ($value = trim((string)($filters['search'] ?? ''))) {
            $query->where(fn($q) => $q->whereRaw('LOWER(summary) LIKE ?', ['%'.mb_strtolower($value).'%'])
                ->orWhereRaw('LOWER(actor_username) LIKE ?', ['%'.mb_strtolower($value).'%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%'.mb_strtolower($value).'%']));
        }
        if ($from) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Asia/Manila')->utc());
        }
        if ($to) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Asia/Manila')->addDay()->utc());
        }
        $page = $query->orderByDesc('id')->simplePaginate(100, ['id','actor_username','action','entity_type','entity_id','summary','created_at'], 'page', (int) ($filters['page'] ?? 1));
        $entries = $page->getCollection()->map(fn ($entry) => [
            'id' => (int) $entry->id,
            'actorUsername' => $entry->actor_username,
            'action' => $entry->action,
            'entityType' => $entry->entity_type,
            'entityId' => $entry->entity_id,
            'summary' => $entry->summary,
            'createdAt' => CarbonImmutable::parse($entry->created_at, 'UTC')->toIso8601String(),
        ]);

        return response()->json(['ok' => true, 'entries' => $entries, 'page' => $page->currentPage(),
            'hasMorePages' => $page->hasMorePages(), 'readOnly' => $auditor])->header('Cache-Control', 'private, no-store');
    }

    private function auditLogEntry(Request $request): JsonResponse
    {
        $request->validate(['id' => ['required', 'integer', 'min:1']]);
        $entry = DB::table('audit_log')->where('id', $request->integer('id'))->first();
        if (!$entry) return response()->json(['ok' => false, 'message' => 'Log entry not found.'], 404);
        return response()->json(['ok' => true, 'entry' => [
            'id' => (int)$entry->id, 'actorUsername' => $entry->actor_username,
            'action' => $entry->action, 'entityType' => $entry->entity_type, 'entityId' => $entry->entity_id,
            'summary' => $entry->summary, 'createdAt' => CarbonImmutable::parse($entry->created_at, 'UTC')->toIso8601String(),
            'metadata' => TrackerAudit::clean(TrackerAudit::decode($entry->metadata ?? null)),
        ]])->header('Cache-Control', 'private, no-store');
    }

    private function audit(array $user, string $action, string $type, ?int $id, string $summary, array $evidence = []): void
    {
        TrackerAudit::record(['actor_id' => $user['id'], 'actor_username' => $user['username'], 'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'summary' => $summary, 'created_at' => now()], $evidence);
    }
}
