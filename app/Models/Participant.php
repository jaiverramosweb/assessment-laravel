<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Participant extends Model
{
    use UsesUuidAndMsTimestamps;

    public const SOURCES = ['cargado', 'abierto'];

    protected $table = 'participants';

    protected $guarded = [];

    protected $casts = ['extra' => 'array', 'invited_at' => 'integer', 'created_at' => 'integer'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function submission(): HasOne
    {
        return $this->hasOne(Submission::class, 'participant_id');
    }
}
