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
