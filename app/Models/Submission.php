<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    use UsesUuidAndMsTimestamps;

    public const MS_CREATED = 'started_at';

    public const MS_UPDATED = 'updated_at';

    protected $table = 'submissions';

    protected $guarded = [];

    protected $casts = ['started_at' => 'integer', 'submitted_at' => 'integer', 'updated_at' => 'integer'];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'submission_id');
    }
}
