<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    use UsesUuidAndMsTimestamps;

    public const STATUSES = ['enviado', 'error'];

    protected $table = 'email_log';

    protected $guarded = [];

    protected $casts = ['created_at' => 'integer'];
}
