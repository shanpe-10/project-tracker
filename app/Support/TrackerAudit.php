<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TrackerAudit
{
    private const OMIT = ['updatedAt', 'updatedBy', 'editedAt', 'projectId', 'actor', 'changes', 'after'];

    /** Structured evidence excludes credentials and uses authenticated server-side identity. */
    public static function record(array $row, array $evidence = []): void
    {
        if (!Schema::hasColumn('audit_log', 'metadata')) {
            unset($row['metadata']);
            DB::table('audit_log')->insert($row);
            return;
        }
        $metadata = self::decode($row['metadata'] ?? null);
        $evidence += array_intersect_key($metadata, ['before' => true, 'after' => true]);
        unset($metadata['before'], $metadata['after']);
        $actor = isset($row['actor_id']) ? User::find($row['actor_id']) : null;
        $identity = ['id' => $row['actor_id'] ?? null, 'username' => $row['actor_username'], 'role' => $actor?->role ?? 'system'];
        $target = ['type' => $row['entity_type'], 'id' => $row['entity_id'] ?? null];
        if ($row['entity_type'] === 'project' && !empty($row['entity_id']) && !isset($evidence['before'], $evidence['after'])
            && str_ends_with($row['action'], '.saved') && Schema::hasTable('history')) {
            $history = DB::table('history')->where('project_id', $row['entity_id'])->orderByDesc('id')->first();
            $entry = self::decode($history?->data);
            if ((int)($history?->updated_by ?? 0) === (int)($row['actor_id'] ?? -1)
                && isset($entry['after']) && abs(strtotime($entry['editedAt'] ?? '') - now()->timestamp) <= 5) {
                $evidence += ['before' => self::decode($entry['changes'] ?? null), 'after' => $entry['after']];
            }
        }
        $before = self::clean($evidence['before'] ?? []);
        $after = self::clean($evidence['after'] ?? []);
        $record = $after ?: $before;
        if ($row['entity_type'] === 'project' && !empty($row['entity_id']) && empty($record['title'])) {
            $record += self::decode(DB::table('projects')->where('id', $row['entity_id'])->value('data'));
        }
        $target += array_filter(['projectNo' => $record['projectNo'] ?? $metadata['projectNo'] ?? null,
            'title' => $record['title'] ?? $metadata['title'] ?? null], fn($value) => $value !== null);
        $changes = [];
        $labels = TrackerProjectFields::columns() + ['full_name' => 'Full name', 'active' => 'Active account', 'role' => 'Role',
            'username' => 'Username', 'attachments' => 'Attachments'];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (in_array($key, self::OMIT, true) || ($before[$key] ?? null) === ($after[$key] ?? null)) continue;
            $changes[] = ['field' => $key, 'label' => $labels[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key)),
                'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
        }
        $context = array_diff_key($evidence, ['before' => true, 'after' => true]);
        if (app()->bound('request') && !app()->runningInConsole()) {
            $context += ['ipAddress' => request()->ip(), 'device' => mb_substr((string)request()->userAgent(), 0, 400),
                'request' => request()->only(['action', 'settings', 'enabled', 'destination', 'reason', 'note', 'batchId',
                    'targetType', 'id', 'permission', 'allowed', 'active', 'fullName', 'role', 'username', 'archive'])];
        }
        $metadata['audit'] = ['actor' => $identity, 'target' => $target, 'beforeCaptured' => array_key_exists('before', $evidence),
            'changes' => $changes, 'context' => self::clean($context)];
        if ($actor?->role === 'superadmin') $metadata['visibility'] = 'superadmin';
        $row['metadata'] = json_encode(self::clean($metadata), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        DB::table('audit_log')->insert($row);
    }

    public static function decode(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        return is_array($value) ? $value : [];
    }

    public static function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) return '[Nested detail omitted]';
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 150, true) as $key => $item) {
                if (is_string($key) && preg_match('/password|secret|token|csrf|cookie|authorization|encrypted|(?:^|_)hash$/i', $key)) continue;
                $result[$key] = self::clean($item, $depth + 1);
            }
            return $result;
        }
        return is_string($value) ? mb_substr($value, 0, 4000) : (is_scalar($value) || $value === null ? $value : (string)$value);
    }
}
