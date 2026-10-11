<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrackerProjectPresenceController extends Controller
{
    private const TTL_SECONDS = 45;

    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('get')) {
            $input = $request->validate([
                'projectIds' => 'required|array|max:100',
                'projectIds.*' => 'required|integer|min:1|distinct',
            ]);

            return $this->editors($input['projectIds']);
        }

        $input = $request->validate([
            'action' => 'required|in:heartbeat,stop',
            'projectId' => 'required|integer|min:1',
            'editorToken' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
        ]);
        $user = $request->session()->get('tracker_user');
        if ($input['action'] === 'heartbeat' && ! ($user['canEdit'] ?? false)) {
            return $this->reply(['ok' => false, 'message' => 'Editing permission required.'], 403);
        }
        $sessionKey = hash('sha256', $request->session()->getId());
        $projectId = (int) $input['projectId'];
        $status = DB::transaction(function () use ($input, $user, $sessionKey, $projectId) {
            // A short shared lock lets project deletion wait until presence is written.
            if (! DB::table('projects')->where('id', $projectId)->sharedLock()->first(['id'])) {
                return $input['action'] === 'stop' ? 200 : 404;
            }
            $key = ['user_id' => (int) $user['id'], 'editor_token' => $input['editorToken']];
            $now = now();
            DB::table('project_editing_presence')->insertOrIgnore($key + [
                'project_id' => $projectId, 'session_key' => $sessionKey,
                'last_seen_at' => $now, 'ended_at' => $input['action'] === 'stop' ? $now : null,
            ]);
            $row = DB::table('project_editing_presence')->where($key)->lockForUpdate()->first();
            if ($row->session_key !== $sessionKey || (int) $row->project_id !== $projectId) return 409;
            if ($input['action'] === 'stop') {
                DB::table('project_editing_presence')->where($key)->update(['ended_at' => $now, 'last_seen_at' => $now]);
            } elseif ($row->ended_at === null) {
                DB::table('project_editing_presence')->where($key)->update(['last_seen_at' => $now]);
            }
            // Keep stop tombstones long enough that delayed heartbeats cannot revive an editor.
            DB::table('project_editing_presence')->where('last_seen_at', '<', now()->subDay())->delete();
            return 200;
        });
        if ($status !== 200) {
            return $this->reply(['ok' => false, 'message' => $status === 404 ? 'Project not found.' : 'Editor session does not match.'], $status);
        }

        return $input['action'] === 'stop' ? $this->reply(['ok' => true]) : $this->editors([$projectId]);
    }

    public static function endSession(Request $request): void
    {
        $userId = (int) $request->session()->get('tracker_user.id', 0);
        if (! $userId) return;
        DB::table('project_editing_presence')->where('user_id', $userId)
            ->where('session_key', hash('sha256', $request->session()->getId()))
            ->whereNull('ended_at')->update(['ended_at' => now(), 'last_seen_at' => now()]);
    }

    private function editors(array $projectIds): JsonResponse
    {
        $groups = [];
        foreach ($projectIds as $id) $groups[(int) $id] = [];
        $rows = DB::table('project_editing_presence as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.project_id', $projectIds)->whereNull('p.ended_at')
            ->where('p.last_seen_at', '>', now()->subSeconds(self::TTL_SECONDS))->where('u.active', true)
            ->where(fn ($query) => $query->where('u.role', 'admin')->orWhere(fn ($staff) => $staff->where('u.role', 'staff')->where('u.can_edit', true)))
            ->select('p.project_id', 'u.id', 'u.username', 'u.full_name')
            ->selectRaw('MAX(p.last_seen_at) AS last_seen_at')
            ->groupBy('p.project_id', 'u.id', 'u.username', 'u.full_name')->orderBy('u.full_name')->orderBy('u.id')->get();
        foreach ($rows as $row) {
            $groups[(int) $row->project_id][] = [
                'id' => (int) $row->id, 'username' => $row->username, 'fullName' => $row->full_name,
                'expiresAt' => \Carbon\Carbon::parse($row->last_seen_at)->addSeconds(self::TTL_SECONDS)->toISOString(),
            ];
        }

        return $this->reply(['ok' => true, 'editorsByProject' => (object) $groups, 'ttlSeconds' => self::TTL_SECONDS, 'serverTime' => now()->toISOString()]);
    }

    private function reply(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }
}
