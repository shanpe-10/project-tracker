<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TrackerInitialAdmin
{
    private const SETTING = 'first_admin_activation';
    private const STARTER_HASH = '$2y$12$0Rw6xP.NlwekOxGAMAAvXOCQDtxXY2OI9d5GS8D.Z89WOiZldRu4K';

    public static function prepare(bool $renew = false): string
    {
        return DB::transaction(function () use ($renew): string {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['tracker:create-admin']);
            }
            $pending = self::pending();
            if ($renew) {
                $account = User::whereKey($pending['id'] ?? 0)->lockForUpdate()->first();
                if (! $account || ! $account->active || $account->role !== 'admin' || $account->password_hash !== self::STARTER_HASH) {
                    throw new \RuntimeException('There is no pending first Admin setup to renew.');
                }
                $id = $account->id;
            } else {
                if (DB::table('users')->exists() || $pending !== null) {
                    throw new \RuntimeException('First Admin setup requires an empty tracker account directory.');
                }
                $id = DB::table('users')->insertGetId([
                    'username' => 'admin', 'full_name' => 'Administrator',
                    'password_hash' => self::STARTER_HASH, 'role' => 'admin', 'active' => true,
                    'created_at' => now(),
                ]);
            }

            $code = strtoupper(bin2hex(random_bytes(8)));
            DB::table('application_settings')->updateOrInsert(['setting_key' => self::SETTING], [
                'setting_value' => json_encode(['id' => $id, 'code_hash' => hash('sha256', $code)], JSON_THROW_ON_ERROR),
            ]);

            return implode('-', str_split($code, 4));
        });
    }

    public static function pending(): ?array
    {
        $value = DB::table('application_settings')->where('setting_key', self::SETTING)->value('setting_value');

        return $value === null ? null : json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function activate(User $account, string $code, string $newPassword): ?User
    {
        return DB::transaction(function () use ($account, $code, $newPassword): ?User {
            $fresh = User::whereKey($account->id)->lockForUpdate()->first();
            $pending = self::pending();
            $codeHash = hash('sha256', strtoupper(str_replace('-', '', $code)));
            if (! $fresh || ! $fresh->active || $fresh->role !== 'admin'
                || ! hash_equals($fresh->password_hash, $account->password_hash)
                || (int) ($pending['id'] ?? 0) !== (int) $account->id
                || ! hash_equals($pending['code_hash'], $codeHash)) {
                return null;
            }

            $fresh->password_hash = Hash::make($newPassword);
            $fresh->save();
            DB::table('application_settings')->where('setting_key', self::SETTING)->delete();

            return $fresh;
        });
    }
}
