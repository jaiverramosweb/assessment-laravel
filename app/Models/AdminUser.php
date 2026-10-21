<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;

class AdminUser extends Model
{
    use UsesUuidAndMsTimestamps;

    protected $table = 'admin_users';

    protected $guarded = [];

    protected $hidden = ['password_hash'];

    protected $casts = ['last_login_at' => 'integer', 'created_at' => 'integer'];

    /** Data the UI receives for the logged-in admin. */
    public function toSessionArray(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'name' => $this->name];
    }
}
