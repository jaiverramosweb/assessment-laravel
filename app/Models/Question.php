<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use UsesUuidAndMsTimestamps;

    public const TYPES = ['opcion_unica', 'abierta'];

    protected $table = 'questions';

    protected $guarded = [];

    protected $casts = ['position' => 'integer', 'required' => 'boolean', 'created_at' => 'integer'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id')->orderBy('position');
    }
}
