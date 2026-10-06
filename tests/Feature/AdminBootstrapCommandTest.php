<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminBootstrapCommandTest extends TestCase
{
    public function test_first_admin_can_be_created_once(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('full_name');
            $table->string('password_hash');
            $table->string('role');
            $table->boolean('active');
            $table->timestamp('created_at')->nullable();
        });

        $this->artisan('tracker:create-admin')
            ->expectsQuestion('Administrator full name', 'System Administrator')
            ->expectsQuestion('Administrator username', 'lgu-admin')
            ->expectsQuestion('Password (at least 12 characters)', 'LongExamplePassword123!')
            ->expectsQuestion('Confirm password', 'LongExamplePassword123!')
            ->assertExitCode(0);

        $account = DB::table('users')->first();
        $this->assertSame('admin', $account->role);
        $this->assertTrue((bool) $account->active);
        $this->assertTrue(Hash::check('LongExamplePassword123!', $account->password_hash));
        $this->artisan('tracker:create-admin')->assertExitCode(1);
        $this->assertSame(1, DB::table('users')->count());
    }
}
