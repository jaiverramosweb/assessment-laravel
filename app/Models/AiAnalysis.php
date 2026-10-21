<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;

class AiAnalysis extends Model
{
    use UsesUuidAndMsTimestamps;

    public const SCOPES = ['pregunta', 'global'];

    protected $table = 'ai_analyses';

    protected $guarded = [];

    protected $casts = ['result' => 'array', 'response_count' => 'integer', 'created_at' => 'integer'];
}
