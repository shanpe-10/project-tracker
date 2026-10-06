<?php
namespace App\Support;
use App\Models\User;
final class TrackerAccess
{
    public static function capabilities(User $user): array
    {
        $result = [];
        foreach (['canCreate'=> 'can_create', 'canEdit'=> 'can_edit', 'canImport'=> 'can_import', 'canExport'=> 'can_export', 'canDelete'=> 'can_delete', 'canDeleteAll'=> 'can_delete_all', 'canResolveAlerts'=> 'can_resolve_alerts', 'canArchive'=> 'can_archive'] as $key => $column) {
            $result[$key] = $user->role === 'admin' || ($user->role === 'staff' && (bool) $user->{$column});
        }
        return $result;
    }
}
