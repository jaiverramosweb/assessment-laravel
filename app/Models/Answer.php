<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    use UsesUuidAndMsTimestamps;

    public const MS_CREATED = null;

    public const MS_UPDATED = 'updated_at';

    protected $table = 'answers';

    protected $guarded = [];

    protected $casts = ['updated_at' => 'integer'];
}
