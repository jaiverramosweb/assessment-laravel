<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSession extends Model
{
    use UsesUuidAndMsTimestamps;

    protected $table = 'admin_sessions';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'integer', 'created_at' => 'integer'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
