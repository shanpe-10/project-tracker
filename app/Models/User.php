<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username', 'full_name', 'password_hash', 'role', 'active',
        'can_create', 'can_edit', 'can_import', 'can_export', 'can_delete',
        'can_delete_all', 'can_resolve_alerts', 'can_archive',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'password_encrypted',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'can_create' => 'boolean', 'can_edit' => 'boolean',
            'can_import' => 'boolean', 'can_export' => 'boolean',
            'can_delete' => 'boolean', 'can_delete_all' => 'boolean',
            'can_resolve_alerts' => 'boolean', 'can_archive' => 'boolean',
        ];
    }
}
