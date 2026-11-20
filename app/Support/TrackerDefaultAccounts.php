<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TrackerDefaultAccounts
{
    public const MARKER = 'source_default_accounts_20261120';
    private const ADMIN_HASH = '$2y$12$rjmuu0hKF9VLHuOny0L7u.nsFACEHAyW4Kt.2qnQHSaulEO8VsGjS';
    private const AUDITOR_HASH = '$2y$12$aGW1jjPDV/34wQ1AQF9dp.JhaE3psF9mx6IfLewU3QxkUKRkm9ioq';

    /** Apply the requested initial credentials once; later password changes survive deploys. */
    public static function initialize(): bool
    {
        return DB::transaction(function (): bool {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tracker:source-default-accounts']);
            }
            if (DB::table('application_settings')->where('setting_key', self::MARKER)->exists()) return false;
            User::orderBy('id')->lockForUpdate()->get();
            $adminId = null;
            foreach (['admin' => ['admin', 'Administrator', self::ADMIN_HASH],
                'superadmin' => ['superadmin', 'Accountability Auditor', self::AUDITOR_HASH]] as $username => [$role, $name, $hash]) {
                $account = User::whereRaw('LOWER(username) = ?', [$username])->first() ?? new User();
                $account->fill(['username' => $username, 'full_name' => $name, 'role' => $role, 'active' => true]);
                $account->password_hash = $hash;
                $account->password_encrypted = null;
                if (!$account->exists) $account->created_at = now();
                $account->save();
                DB::table('tracker_account_sessions')->where('user_id', $account->id)->delete();
                DB::table('project_editing_presence')->where('user_id', $account->id)->delete();
                if ($role === 'admin') $adminId = $account->id;
                TrackerAudit::record(['actor_id' => null, 'actor_username' => 'system', 'action' => 'account.defaults_configured',
                    'entity_type' => 'account', 'entity_id' => $account->id, 'summary' => 'Configured an initial source-edition account.',
                    'metadata' => json_encode(['visibility' => 'superadmin']), 'created_at' => now()],
                    ['after' => ['username' => $username, 'role' => $role, 'active' => true], 'credentialReset' => true]);
            }
            foreach ([self::MARKER => true, 'original_admin_id' => $adminId] as $key => $value) {
                DB::table('application_settings')->updateOrInsert(['setting_key' => $key], ['setting_value' => json_encode($value), 'updated_at' => now()]);
            }
            DB::table('application_settings')->where('setting_key', 'first_admin_activation')->delete();
            return true;
        });
    }
}
