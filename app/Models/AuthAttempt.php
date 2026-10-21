<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Failed-attempt counter per rate-limit key. */
class AuthAttempt extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'auth_attempts';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['failures' => 'integer', 'window_start' => 'integer', 'locked_until' => 'integer'];
}
