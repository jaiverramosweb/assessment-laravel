<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;

class ParticipantSession extends Model
{
    use UsesUuidAndMsTimestamps;

    protected $table = 'participant_sessions';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'integer', 'created_at' => 'integer'];
}
