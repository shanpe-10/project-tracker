<?php

namespace App\Support;

final class TrackerHistory
{
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
