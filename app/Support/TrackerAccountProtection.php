<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TrackerAccountProtection
{
    public static function originalSuperadminId(): ?int
    {
        $key = 'original_superadmin_id';
        $saved = DB::table('application_settings')->where('setting_key', $key)->value('setting_value');
        if ($saved !== null) return (int) json_decode((string) $saved, true);

        return DB::transaction(function () use ($key) {
            // Use the same lock order as account management. Pin the identity once,
            // so renaming, disabling or changing its role cannot bypass protection.
            User::orderBy('id')->lockForUpdate()->get();
            $id = User::where('role', 'superadmin')->orderBy('created_at')->orderBy('id')->value('id');
            if ($id === null) return null;
            DB::table('application_settings')->insertOrIgnore([
                'setting_key' => $key, 'setting_value' => json_encode((int) $id), 'updated_at' => now(),
            ]);
            return (int) json_decode((string) DB::table('application_settings')->where('setting_key', $key)->value('setting_value'), true);
        });
    }
}
