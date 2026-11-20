<?php

namespace App\Http\Controllers;

use App\Support\TrackerHistory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrackerDeletionController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('get')) return $this->listing($request);
        $request->validate(['action' => 'required|string|in:create,review,prepareConfirmation,confirm,editReason,cancel,previewDirect,prepareDirect,confirmDirect,cancelDirect,clearHistory']);
        return match ($request->input('action')) {
            'clearHistory' => $this->clearHistory($request),
            'create' => $this->create($request),
            'review' => $this->review($request),
            'prepareConfirmation' => $this->prepareConfirmation($request),
            'confirm' => $this->confirm($request),
            'editReason' => $this->editReason($request),
            'cancel' => $this->cancel($request),
            'previewDirect' => $this->previewDirect($request),
            'prepareDirect' => $this->prepareDirect($request),
            'confirmDirect' => $this->confirmDirect($request),
            'cancelDirect' => $this->cancelDirect($request),
        };
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['ok' => false, 'message' => $message], $status));
    }

    private function user(Request $request, bool $admin = false): array
    {
        $user = (array) $request->session()->get('tracker_user');
        if (! in_array($user['role'] ?? '', $admin ? ['admin'] : ['admin', 'staff'], true)) {
            $this->fail(403, $admin ? 'Only administrators can review and confirm deletions.' : 'Project access is required.');
        }
        return $user;
    }

    private function listing(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate(['id' => 'sometimes|required|integer|min:1', 'page' => 'sometimes|integer|min:1',
            'status' => 'sometimes|nullable|in:pending,approved,rejected,deleted,cancelled', 'scope' => 'sometimes|in:mine,review',
            'view' => 'sometimes|in:active,history,all',
            'lookup' => 'sometimes|boolean', 'all' => 'sometimes|boolean']);
        $scope = $request->query('scope', $user['role'] === 'admin' ? 'review' : 'mine');
        if ($scope === 'review') $this->user($request, true);
        if ($request->boolean('lookup')) return $this->lookup($request, $user);
        $query = DB::table('deletion_requests');
        if ($scope === 'mine') $query->where('requested_by', $user['id']);
        $activeStatuses = $scope === 'mine' ? ['pending'] : ['pending', 'approved'];
        $pending = (clone $query)->whereIn('status', $activeStatuses)->count();
        if ($request->filled('id')) {
            $row = $query->where('id', $request->integer('id'))->first();
            if (! $row) $this->fail(404, 'Deletion request not found.');
            return response()->json(['ok' => true, 'request' => $this->detail($row, $user)])->header('Cache-Control', 'private, no-store');
        }
        $view = $request->query('view', $request->filled('status') ? 'all' : 'active');
        if ($view === 'active') $query->whereIn('status', $activeStatuses);
        elseif ($view === 'history') $query->whereNotIn('status', $activeStatuses);
        if ($view !== 'active') $this->visibleHistory($query, $user, $scope, $activeStatuses);
        if ($request->input('status') === 'pending') $query->whereIn('status', ['pending', 'approved']);
        elseif ($request->filled('status')) $query->where('status', $request->input('status'));
        $page = $query->select('deletion_requests.*')->selectSub(
            DB::table('deletion_request_targets')->selectRaw('COUNT(*)')->whereColumn('request_id', 'deletion_requests.id'), 'target_count'
        )->orderByDesc('id')->paginate(20);
        return response()->json(['ok' => true, 'requests' => collect($page->items())->map(fn ($row) => $this->present($row, $user))->all(),
            'pendingCount' => $pending, 'view' => $view, 'page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()])
            ->header('Cache-Control', 'private, no-store');
    }

    private function visibleHistory($query, array $user, string $scope, array $activeStatuses): void
    {
        $query->where(function ($q) use ($user, $scope, $activeStatuses) {
            $q->whereIn('status', $activeStatuses)->orWhereNotExists(function ($hidden) use ($user, $scope) {
                $hidden->selectRaw('1')->from('deletion_request_history_hidden as hidden')
                    ->whereColumn('hidden.request_id', 'deletion_requests.id')
                    ->where('hidden.user_id', $user['id'])->where('hidden.scope', $scope);
            });
        });
    }

    private function clearHistory(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate(['scope' => 'required|in:mine,review', 'confirmed' => 'required|accepted']);
        $scope = $request->input('scope');
        if ($scope === 'review') $this->user($request, true);
        $activeStatuses = $scope === 'mine' ? ['pending'] : ['pending', 'approved'];
        $count = DB::transaction(function () use ($user, $scope, $activeStatuses) {
            $query = DB::table('deletion_requests')->whereNotIn('status', $activeStatuses);
            if ($scope === 'mine') $query->where('requested_by', $user['id']);
            $this->visibleHistory($query, $user, $scope, $activeStatuses);
            $ids = $query->orderBy('id')->lockForUpdate()->pluck('id');
            foreach ($ids->chunk(500) as $chunk) {
                DB::table('deletion_request_history_hidden')->insertOrIgnore($chunk->map(fn ($id) => [
                    'user_id' => $user['id'], 'request_id' => $id, 'scope' => $scope, 'hidden_at' => now('UTC'),
                ])->all());
            }
            if ($ids->isNotEmpty()) $this->audit($user, 'deletion.history_cleared', null, ['scope' => $scope, 'count' => $ids->count()]);
            return $ids->count();
        }, 3);
        return response()->json(['ok' => true, 'clearedCount' => $count, 'message' => 'Your deletion request history has been cleared.'])
            ->header('Cache-Control', 'private, no-store');
    }

    private function detail(object $row, array $user): array
    {
        $result = $this->present($row, $user);
        $result['targets'] = DB::table('deletion_request_targets')->where('request_id', $row->id)->orderBy('id')->get()
            ->map(fn ($target) => ['id' => (int) $target->target_id, 'record' => json_decode($target->snapshot, true)])->all();
        return $result;
    }

    /** Staff lookup is owner-only; Admin can open a matching request for review. */
    private function lookup(Request $request, array $user): JsonResponse
    {
        $request->validate(['targetType' => 'required|in:project,history', 'targetId' => 'required_unless:all,1|integer|min:1']);
        $type = $request->input('targetType');
        $review = $user['role'] === 'admin' && $request->query('scope', 'review') === 'review';
        $active = DB::table('deletion_requests')->whereIn('status', ['pending', 'approved']);
        if ($request->boolean('all')) {
            $active->where('target_type', $type);
            $own = (clone $active)->when(! $review, fn ($q) => $q->where('requested_by', $user['id']))->where('bulk', true)->orderByDesc('id')->first();
        } else {
            $id = $request->integer('targetId');
            $parent = $type === 'history' ? DB::table('history')->where('id', $id)->value('project_id') : null;
            $active->whereExists(function ($q) use ($type, $id, $parent) {
                $q->selectRaw('1')->from('deletion_request_targets as t')->whereColumn('t.request_id', 'deletion_requests.id')->whereNotNull('t.active_key');
                if ($type === 'project') $q->where('t.project_id', $id);
                else $q->whereIn('t.active_key', array_filter(['history:'.$id, $parent ? 'project:'.$parent : null]));
            });
            $own = (clone $active)->when(! $review, fn ($q) => $q->where('requested_by', $user['id']))->orderByDesc('id')->first();
        }
        return response()->json(['ok' => true, 'request' => $own ? $this->detail($own, $user) : null,
            'blocked' => ! $own && $active->exists()])->header('Cache-Control', 'private, no-store');
    }

    private function version(object $row, ?int $revision = null): string
    {
        $revision ??= (int) DB::table('audit_log')->where('entity_type', 'deletion_request')->where('entity_id', $row->id)->max('id');
        return hash('sha256', json_encode([(int) $row->id, $row->status, $row->reason, $revision]));
    }

    private function sameVersion(Request $request, object $row): void
    {
        $request->validate(['expectedVersion' => 'required|string|size:64']);
        if (! hash_equals($this->version($row), $request->input('expectedVersion'))) {
            $this->fail(409, 'This deletion request changed. Refresh the request before saving or reviewing it.');
        }
    }

    private function present(object $row, array $user): array
    {
        $events = DB::table('audit_log')->where('entity_type', 'deletion_request')->where('entity_id', $row->id)
            ->selectRaw("MAX(id) as revision, MAX(CASE WHEN action = 'deletion.cancelled' THEN created_at END) as cancelled_at, MAX(CASE WHEN action = 'deletion.reason_updated' THEN created_at END) as reason_updated_at")->first();
        $first = DB::table('deletion_request_targets')->where('request_id', $row->id)->orderBy('id')->first();
        $snapshot = $first ? json_decode($first->snapshot, true) : [];
        return ['id' => (int) $row->id, 'targetType' => $row->target_type, 'bulk' => (bool) $row->bulk,
            'title' => $snapshot['title'] ?? $snapshot['action'] ?? 'Deletion request', 'projectNo' => $snapshot['projectNo'] ?? null,
            'isOwner' => (int) $row->requested_by === (int) $user['id'], 'version' => $this->version($row, (int) $events->revision),
            'status' => $row->status === 'approved' ? 'pending' : $row->status, 'stage' => $row->status,
            'targetCount' => (int) ($row->target_count ?? DB::table('deletion_request_targets')->where('request_id', $row->id)->count()),
            'requester' => json_decode($row->requester, true), 'reason' => $row->reason, 'requestedAt' => $this->date($row->requested_at),
            'reviewer' => json_decode($row->reviewer ?? 'null', true), 'reviewedAt' => $this->date($row->reviewed_at),
            'decisionNote' => $row->decision_note, 'deletedBy' => json_decode($row->deleted_by ?? 'null', true), 'deletedAt' => $this->date($row->deleted_at),
            'cancelledAt' => $this->date($events->cancelled_at), 'reasonUpdatedAt' => $this->date($events->reason_updated_at)];
    }

    private function date(?string $value): ?string
    {
        return $value ? \Carbon\CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }

    private function create(Request $request): JsonResponse
    {
        $request->validate(['targetType' => 'required|in:project,history', 'all' => 'sometimes|boolean', 'id' => 'required_unless:all,true|integer|min:1']);
        return $this->requestFor($request, $request->input('targetType'), $request->boolean('all'));
    }

    /** Legacy deletion endpoints enter the same approval workflow. */
    public function requestFor(Request $request, string $type, bool $all = false): JsonResponse
    {
        $user = $this->user($request);
        if ($all && $user['role'] !== 'admin') $this->fail(403, 'Only Admin can delete all records.');
        if (empty($user[$all ? 'canDeleteAll' : 'canDelete'])) $this->fail(403, 'You do not have permission to request this deletion.');
        $request->merge(['reason' => is_string($request->input('reason')) ? trim($request->input('reason')) : $request->input('reason')]);
        $request->validate(['reason' => 'required|string|max:2000']);
        if (! $all) $request->validate(['id' => 'required|integer|min:1']);
        $id = DB::transaction(function () use ($request, $type, $all, $user) {
            $rows = $this->lockedRows($type, $all ? null : [$request->integer('id')]);
            if ($rows->isEmpty()) $this->fail(404, 'No records found for deletion.');
            foreach ($rows as $row) {
                $keys = [$type.':'.$row->id];
                if ($type === 'history' && $row->project_id) $keys[] = 'project:'.$row->project_id;
                $overlap = DB::table('deletion_request_targets')->whereIn('active_key', $keys)->exists();
                if ($type === 'project') $overlap = $overlap || DB::table('deletion_request_targets')->where('project_id', $row->id)->whereNotNull('active_key')->exists();
                if ($overlap) $this->fail(409, 'A pending deletion request already includes one of these records. Review the existing request first.');
            }
            $id = DB::table('deletion_requests')->insertGetId(['target_type' => $type, 'bulk' => $all, 'status' => 'pending',
                'requested_by' => $user['id'], 'requester' => json_encode(TrackerHistory::actor($user)),
                'reason' => $request->input('reason'), 'requested_at' => now('UTC')]);
            foreach ($rows as $row) {
                DB::table('deletion_request_targets')->insert(['request_id' => $id, 'target_type' => $type, 'target_id' => $row->id,
                    'project_id' => $type === 'project' ? $row->id : $row->project_id, 'active_key' => $type.':'.$row->id,
                    'fingerprint' => $this->fingerprint($type, $row), 'snapshot' => $row->data]);
            }
            $this->audit($user, 'deletion.requested', $id, ['reason' => $request->input('reason'), 'targetType' => $type, 'targetIds' => $rows->pluck('id')->all()]);
            return $id;
        }, 3);
        return response()->json(['ok' => true, 'pending' => true, 'requestId' => $id, 'message' => 'Deletion request submitted. The records remain intact until an administrator reviews and confirms deletion.'], 202);
    }

    private function lockedRows(string $type, ?array $ids): Collection
    {
        $table = $type === 'project' ? 'projects' : 'history';
        if ($type === 'history') {
            $parents = DB::table('history')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->pluck('project_id')->filter()->unique()->sort()->all();
            DB::table('projects')->whereIn('id', $parents)->orderBy('id')->lockForUpdate()->get();
        }
        return DB::table($table)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->lockForUpdate()->get();
    }

    private function fingerprint(string $type, object $row): string
    {
        // Include project history so a new log or edit requires a fresh request too.
        $history = $type === 'project' ? DB::table('history')->where('project_id', $row->id)->orderBy('id')->get(['id', 'data', 'updated_at'])->all() : [];
        return hash('sha256', json_encode([$row->data, $row->updated_at, $history]));
    }

    private function lockedRequest(Request $request): object
    {
        $request->validate(['id' => 'required|integer|min:1']);
        $row = DB::table('deletion_requests')->where('id', $request->integer('id'))->lockForUpdate()->first();
        if (! $row) $this->fail(404, 'Deletion request not found.');
        return $row;
    }

    private function verifyTargets(object $request): Collection
    {
        $targets = DB::table('deletion_request_targets')->where('request_id', $request->id)->orderBy('target_id')->get();
        $rows = $this->lockedRows($request->target_type, $targets->pluck('target_id')->all())->keyBy('id');
        foreach ($targets as $target) {
            $row = $rows->get($target->target_id);
            if (! $row || ! hash_equals($target->fingerprint, $this->fingerprint($request->target_type, $row))) {
                $this->fail(409, 'A record or its history changed after this request was submitted. Reject this request and submit a new one to review the current records.');
            }
        }
        return $rows;
    }

    private function review(Request $request): JsonResponse
    {
        $user = $this->user($request, true);
        $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:2000']);
        return DB::transaction(function () use ($request, $user) {
            $row = $this->lockedRequest($request);
            $approve = $request->input('decision') === 'approve';
            if (! in_array($row->status, $approve ? ['pending'] : ['pending', 'approved'], true)) $this->fail(409, 'This request has already been processed. Refresh the list.');
            $this->sameVersion($request, $row);
            if ($approve) $this->verifyTargets($row);
            $token = $approve ? Str::random(64) : null;
            DB::table('deletion_requests')->where('id', $row->id)->update(['status' => $approve ? 'approved' : 'rejected',
                'reviewer' => json_encode(TrackerHistory::actor($user)), 'reviewed_at' => now('UTC'),
                'decision_note' => $request->input('note'), 'confirmation_hash' => $token ? hash('sha256', $token) : null]);
            if (! $approve) DB::table('deletion_request_targets')->where('request_id', $row->id)->update(['active_key' => null]);
            $this->audit($user, $approve ? 'deletion.approved' : 'deletion.rejected', $row->id, ['note' => $request->input('note')]);
            return response()->json(['ok' => true, 'confirmationToken' => $token]);
        }, 3);
    }

    private function ownedPending(Request $request, array $user): object
    {
        $row = $this->lockedRequest($request);
        if ((int) $row->requested_by !== (int) $user['id']) $this->fail(404, 'Deletion request not found.');
        if (! in_array($row->status, ['pending', 'approved'], true)) $this->fail(409, 'Only pending deletion requests can be edited or cancelled.');
        $this->sameVersion($request, $row);
        return $row;
    }

    private function editReason(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->merge(['reason' => is_string($request->input('reason')) ? trim($request->input('reason')) : $request->input('reason')]);
        $request->validate(['reason' => 'required|string|max:2000']);
        return DB::transaction(function () use ($request, $user) {
            $row = $this->ownedPending($request, $user);
            if ($row->reason === $request->input('reason')) return response()->json(['ok' => true]);
            DB::table('deletion_requests')->where('id', $row->id)->update(['reason' => $request->input('reason'), 'status' => 'pending',
                'reviewer' => null, 'reviewed_at' => null, 'decision_note' => null, 'confirmation_hash' => null]);
            $this->audit($user, 'deletion.reason_updated', $row->id, ['previousReason' => $row->reason, 'reason' => $request->input('reason'),
                'previousStatus' => $row->status, 'previousReviewer' => json_decode($row->reviewer ?? 'null', true)]);
            return response()->json(['ok' => true, 'message' => 'Reason updated. The request is awaiting admin review.']);
        }, 3);
    }

    private function cancel(Request $request): JsonResponse
    {
        $user = $this->user($request);
        return DB::transaction(function () use ($request, $user) {
            $row = $this->ownedPending($request, $user);
            DB::table('deletion_requests')->where('id', $row->id)->update(['status' => 'cancelled', 'confirmation_hash' => null]);
            DB::table('deletion_request_targets')->where('request_id', $row->id)->update(['active_key' => null]);
            $this->audit($user, 'deletion.cancelled', $row->id, ['reason' => $row->reason, 'previousStatus' => $row->status]);
            return response()->json(['ok' => true, 'message' => 'Deletion request cancelled. The records have been kept.']);
        }, 3);
    }

    private function prepareConfirmation(Request $request): JsonResponse
    {
        $this->user($request, true);
        return DB::transaction(function () use ($request) {
            $row = $this->lockedRequest($request);
            if ($row->status !== 'approved') $this->fail(409, 'Review and approve this request before confirming deletion.');
            $this->verifyTargets($row);
            $token = Str::random(64);
            DB::table('deletion_requests')->where('id', $row->id)->update(['confirmation_hash' => hash('sha256', $token)]);
            return response()->json(['ok' => true, 'confirmationToken' => $token]);
        }, 3);
    }

    private function confirm(Request $request): JsonResponse
    {
        $user = $this->user($request, true);
        $request->validate(['confirmationToken' => 'required|string|size:64', 'confirmed' => 'required|accepted']);
        \App\Support\TrackerDeletionPassword::verify($request, (int) $user['id']);
        $projects = DB::transaction(function () use ($request, $user) {
            $row = $this->lockedRequest($request);
            if ($row->status !== 'approved' || ! $row->confirmation_hash || ! hash_equals($row->confirmation_hash, hash('sha256', $request->input('confirmationToken')))) {
                $this->fail(409, 'Deletion has not been approved or the confirmation expired. Open the request again.');
            }
            $targets = $this->verifyTargets($row);
            $ids = $targets->keys()->all();
            if ($row->target_type === 'project') {
                DB::table('history')->whereIn('project_id', $ids)->delete();
                DB::table('projects')->whereIn('id', $ids)->delete();
            } else DB::table('history')->whereIn('id', $ids)->delete();
            DB::table('deletion_requests')->where('id', $row->id)->update(['status' => 'deleted',
                'deleted_by' => json_encode(TrackerHistory::actor($user)), 'deleted_at' => now('UTC'), 'confirmation_hash' => null]);
            DB::table('deletion_request_targets')->where('request_id', $row->id)->update(['active_key' => null]);
            $this->audit($user, 'deletion.confirmed', $row->id, ['targetType' => $row->target_type, 'targetIds' => $ids]);
            foreach ($ids as $id) {
                \App\Support\TrackerAudit::record(['actor_id' => $user['id'], 'actor_username' => $user['username'],
                    'action' => $row->target_type === 'project' ? 'project.deleted' : 'history.deleted', 'entity_type' => $row->target_type,
                    'entity_id' => $id, 'summary' => 'Deleted after two-step admin approval (request #'.$row->id.').',
                    'metadata' => json_encode(['deletionRequestId' => $row->id]), 'created_at' => now('UTC')]);
            }
            return $row->target_type === 'project' ? $targets : collect();
        }, 3);
        foreach ($projects as $project) TrackerDataController::removeAttachmentDirectory((int) $project->id, json_decode($project->data, true) ?: []);
        return response()->json(['ok' => true, 'message' => 'Deletion confirmed. The approved records have been deleted.']);
    }

    /** Admin deletion uses a short-lived session confirmation, never a pending request. */
    private function directInput(Request $request): array
    {
        $user = $this->user($request, true);
        $request->validate(['targetType' => 'required|in:project,history', 'all' => 'sometimes|boolean',
            'id' => 'required_unless:all,true|integer|min:1']);
        return [$user, $request->input('targetType'), $request->boolean('all')];
    }

    private function availableDirectRows(string $type, ?array $ids, bool $bulk): Collection
    {
        $rows = $this->lockedRows($type, $ids);
        if ($rows->isEmpty() || ($ids !== null && $rows->count() !== count($ids))) {
            $this->fail(409, 'The selected records changed or no longer exist. Reopen deletion and review them again.');
        }
        if ($type === 'project' && $bulk) return $rows;

        foreach ($rows as $row) {
            $keys = [$type.':'.$row->id];
            if ($type === 'history' && $row->project_id) $keys[] = 'project:'.$row->project_id;
            $overlap = DB::table('deletion_request_targets')->whereIn('active_key', $keys)->exists();
            if ($type === 'project') $overlap = $overlap || DB::table('deletion_request_targets')->where('project_id', $row->id)->whereNotNull('active_key')->exists();
            if ($overlap) $this->fail(409, 'A deletion request already covers these records. Review it in Workspace → Deletion requests.');
        }
        return $rows;
    }

    private function closeRequestsForBulkDeletion(array $user, array $projectIds): void
    {
        $requests = DB::table('deletion_requests')->whereIn('status', ['pending', 'approved'])
            ->whereIn('id', DB::table('deletion_request_targets')->select('request_id')
                ->whereIn('project_id', $projectIds)->whereNotNull('active_key'))
            ->orderBy('id')->lockForUpdate()->get(['id', 'status', 'decision_note']);

        foreach ($requests as $request) {
            $targets = DB::table('deletion_request_targets')->where('request_id', $request->id);
            $hasRemainingTargets = (clone $targets)->where(function ($query) use ($projectIds) {
                $query->whereNull('project_id')->orWhereNotIn('project_id', $projectIds);
            })->exists();

            $changes = ['confirmation_hash' => null];
            if ($hasRemainingTargets) {
                $changes['status'] = 'cancelled';
                $changes['decision_note'] = trim(($request->decision_note ?? '')."\n\nAn Admin deleted part of this selection directly. Submit a new request for any remaining records.");
            } else {
                $changes['status'] = 'deleted';
                $changes['deleted_by'] = json_encode(TrackerHistory::actor($user));
                $changes['deleted_at'] = now('UTC');
            }
            DB::table('deletion_requests')->where('id', $request->id)->update($changes);
            $targets->update(['active_key' => null]);
            $this->audit($user, $hasRemainingTargets ? 'deletion.cancelled' : 'deletion.confirmed', $request->id, [
                'method' => 'admin_direct_bulk', 'previousStatus' => $request->status,
            ]);
        }
    }

    private function directVersion(string $type, bool $bulk, Collection $rows): string
    {
        return hash('sha256', json_encode([$type, $bulk, $rows->map(fn ($row) => [(int)$row->id, $this->fingerprint($type, $row)])->all()]));
    }

    private function previewDirect(Request $request): JsonResponse
    {
        [, $type, $bulk] = $this->directInput($request);
        return DB::transaction(function () use ($request, $type, $bulk) {
            $rows = $this->availableDirectRows($type, $bulk ? null : [$request->integer('id')], $bulk);
            $first = json_decode($rows->first()->data, true) ?: [];
            return response()->json(['ok' => true, 'selection' => ['direct' => true, 'targetType' => $type, 'bulk' => $bulk,
                'targetCount' => $rows->count(), 'title' => $first['title'] ?? $first['action'] ?? 'History record',
                'version' => $this->directVersion($type, $bulk, $rows),
                'targets' => $rows->take(20)->map(fn ($row) => ['id' => (int)$row->id, 'record' => array_intersect_key(json_decode($row->data, true) ?: [], array_flip(['title','action','projectNo']))])->values()->all()]]);
        }, 3);
    }

    private function prepareDirect(Request $request): JsonResponse
    {
        [$user, $type, $bulk] = $this->directInput($request);
        $request->validate(['expectedVersion' => 'required|string|size:64', 'reason' => 'nullable|string|max:2000']);
        return DB::transaction(function () use ($request, $user, $type, $bulk) {
            $rows = $this->availableDirectRows($type, $bulk ? null : [$request->integer('id')], $bulk);
            $version = $this->directVersion($type, $bulk, $rows);
            if (!hash_equals($version, $request->input('expectedVersion'))) $this->fail(409, 'The selected records changed. Reopen deletion and review them again.');
            $token = Str::random(64);
            $request->session()->put('tracker_direct_deletion', ['userId' => (int)$user['id'], 'targetType' => $type,
                'bulk' => $bulk, 'ids' => $rows->pluck('id')->map(fn ($id) => (int)$id)->all(), 'version' => $version,
                'reason' => trim((string)$request->input('reason')), 'reviewedAt' => now('UTC')->toIso8601String(),
                'expiresAt' => now()->addMinutes(10)->getTimestamp(), 'tokenHash' => hash('sha256', $token)]);
            return response()->json(['ok' => true, 'confirmationToken' => $token]);
        }, 3);
    }

    private function cancelDirect(Request $request): JsonResponse
    {
        $this->user($request, true);
        $request->validate(['confirmationToken' => 'required|string|size:64']);
        $prepared = $request->session()->get('tracker_direct_deletion');
        if ($prepared && hash_equals($prepared['tokenHash'], hash('sha256', $request->input('confirmationToken')))) {
            $request->session()->forget('tracker_direct_deletion');
        }
        return response()->json(['ok' => true]);
    }

    private function confirmDirect(Request $request): JsonResponse
    {
        $user = $this->user($request, true);
        $request->validate(['confirmationToken' => 'required|string|size:64', 'confirmed' => 'required|accepted']);
        $prepared = $request->session()->get('tracker_direct_deletion');
        if (!$prepared || (int)$prepared['userId'] !== (int)$user['id'] || $prepared['expiresAt'] <= now()->getTimestamp()
            || !hash_equals($prepared['tokenHash'], hash('sha256', $request->input('confirmationToken')))) {
            $this->fail(409, 'Review the records again before confirming deletion. The confirmation is missing or expired.');
        }
        \App\Support\TrackerDeletionPassword::verify($request, (int) $user['id']);
        $projects = DB::transaction(function () use ($user, $prepared) {
            $type = $prepared['targetType'];
            $rows = $this->availableDirectRows($type, $prepared['ids'], $prepared['bulk']);
            if (!hash_equals($prepared['version'], $this->directVersion($type, $prepared['bulk'], $rows))) {
                $this->fail(409, 'A record or its history changed. Reopen deletion and review the current records.');
            }
            $ids = $rows->pluck('id')->all();
            if ($type === 'project') {
                if ($prepared['bulk']) $this->closeRequestsForBulkDeletion($user, $ids);
                DB::table('history')->whereIn('project_id', $ids)->delete();
                DB::table('projects')->whereIn('id', $ids)->delete();
            } else DB::table('history')->whereIn('id', $ids)->delete();
            foreach ($rows as $row) {
                $snapshot = json_decode($row->data, true) ?: [];
                \App\Support\TrackerAudit::record(['actor_id' => $user['id'], 'actor_username' => $user['username'],
                    'action' => $type === 'project' ? 'project.deleted' : 'history.deleted', 'entity_type' => $type,
                    'entity_id' => $row->id, 'summary' => 'Deleted directly by Admin after review and final confirmation.',
                    'metadata' => json_encode(['actor' => TrackerHistory::actor($user), 'method' => 'admin_direct_two_step',
                        'reason' => $prepared['reason'], 'reviewedAt' => $prepared['reviewedAt'], 'confirmedAt' => now('UTC')->toIso8601String(),
                        'before' => $snapshot, 'after' => [], 'title' => $snapshot['title'] ?? $snapshot['action'] ?? '', 'projectNo' => $snapshot['projectNo'] ?? null]), 'created_at' => now('UTC')]);
            }
            return $type === 'project' ? $rows : collect();
        }, 3);
        $request->session()->forget('tracker_direct_deletion');
        foreach ($projects as $project) TrackerDataController::removeAttachmentDirectory((int)$project->id, json_decode($project->data, true) ?: []);
        return response()->json(['ok' => true, 'message' => 'Deletion confirmed. The selected records have been deleted.']);
    }

    private function audit(array $user, string $action, ?int $id, array $metadata): void
    {
        \App\Support\TrackerAudit::record(['actor_id' => $user['id'], 'actor_username' => $user['username'], 'action' => $action,
            'entity_type' => 'deletion_request', 'entity_id' => $id, 'summary' => Str::limit(str_replace('.', ' ', $action).($id !== null ? ' #'.$id : ''), 500),
            'metadata' => json_encode(['actor' => TrackerHistory::actor($user)] + $metadata), 'created_at' => now('UTC')]);
    }
}
