<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    use UsesUuidAndMsTimestamps;

    public const MS_CREATED = null;

    protected $table = 'question_options';

    protected $guarded = [];

    protected $casts = ['position' => 'integer', 'is_correct' => 'boolean'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
