<?php

namespace App\Support;

final class TrackerHistory
{
    /** Save the prior snapshot and resulting values in the same transaction as the edit. */
    public static function append(int $id, array $before, array $after, array $user, string $action): void
    {
        $stamp = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
        $entry = ['projectId'=>$id,'editedAt'=>$stamp,'action'=>$action,'changes'=>json_encode($before,JSON_UNESCAPED_UNICODE),
            'after'=>$after,'updatedBy'=>$user['username'],'actor'=>self::actor($user)];
        \Illuminate\Support\Facades\DB::table('history')->insert(['project_id'=>$id,'data'=>json_encode($entry,JSON_UNESCAPED_UNICODE),'event_at'=>now(),'updated_at'=>now(),'updated_by'=>$user['id']]);
    }

    public static function snapshot(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : null;
    }

    public static function isLegacyEdit(array $entry, ?array $before): bool
    {
        return empty($entry['action']) && ! array_key_exists('after', $entry) && ! empty($before);
    }

    public static function matchesLegacyEdit(array $entry, array $before, ?array $candidate): bool
    {
        $savedAt = $candidate['updatedAt'] ?? null;
        $loggedAt = $entry['editedAt'] ?? null;
        $editor = $entry['updatedBy'] ?? null;
        $timestamp = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/';
        if (! is_string($editor) || $editor === '' || $editor !== ($candidate['updatedBy'] ?? null)
            || ! is_string($savedAt) || ! preg_match($timestamp, $savedAt)
            || ! is_string($loggedAt) || ! preg_match($timestamp, $loggedAt)) {
            return false;
        }

        // Older form saves stamped the resulting record immediately before writing its history entry.
        $previousVersion = $before['updatedAt'] ?? '';
        $savedTime = strtotime($savedAt);
        $loggedTime = strtotime($loggedAt);
        return $savedTime !== false && $loggedTime !== false
            && (! is_string($previousVersion) || $savedAt > $previousVersion)
            && $savedAt <= $loggedAt
            && $loggedTime - $savedTime <= 5;
    }

    /** Snapshot the authenticated account so later renames/deletions retain attribution. */
    public static function actor(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'fullName' => (string) ($user['fullName'] ?? ''),
        ];
    }
}
