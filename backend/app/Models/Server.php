<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A remote server the platform can provision/deploy to over SSH.
 * The password is encrypted at rest and never exposed through the API.
 */
#[Fillable([
    'user_id', 'name', 'ip_address', 'ssh_port', 'ssh_user', 'password', 'status', 'last_checked_at',
])]
#[Hidden(['password'])]
class Server extends Model
{
    protected function casts(): array
    {
        return [
            'ssh_port'        => 'integer',
            'password'        => 'encrypted',
            'last_checked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
