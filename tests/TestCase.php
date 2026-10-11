<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private array $trackerFixtureTokens = [];

    /** Create a valid account session for tests of features other than sign-in. */
    protected function withTrackerSession(array $data)
    {
        $id = (int) ($data['tracker_user']['id'] ?? 0);
        if ($id && \App\Models\User::find($id)) {
            if (! \Illuminate\Support\Facades\Schema::hasTable('tracker_account_sessions')) {
                (require database_path('migrations/2026_10_11_000002_create_tracker_account_sessions.php'))->up();
            }
            $token = $this->trackerFixtureTokens[$id] ??= \Illuminate\Support\Str::random(64);
            \Illuminate\Support\Facades\DB::table('tracker_account_sessions')->updateOrInsert(['user_id' => $id], [
                'token_hash' => hash('sha256', $token),
                'started_at' => $data['tracker_created_at'] ?? time(),
                'last_activity_at' => $data['tracker_last_activity'] ?? time(),
            ]);
            $data['tracker_session_token'] = $token;
        }
        return $this->withSession($data);
    }
}
