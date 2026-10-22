<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use UsesUuidAndMsTimestamps;

    public const MS_UPDATED = 'updated_at';

    public const ACCESS_MODES = ['invitados', 'abierto'];

    public const STATUSES = ['borrador', 'publicado', 'archivado'];

    protected $table = 'assessments';

    protected $guarded = [];

    // MySQL TEXT columns have no database default (see the schema migration).
    protected $attributes = [
        'description' => '',
        'instructions' => '',
        'invite_subject' => '',
        'invite_body' => '',
    ];

    // Secrets stay on the server; the decrypted password is only sent on explicit request.
    protected $hidden = ['access_password_hash', 'access_password_enc'];

    protected $casts = [
        'starts_at' => 'integer',
        'ends_at' => 'integer',
        'open_max_chars' => 'integer',
        'series_order' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'assessment_id')->orderBy('position');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'assessment_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'assessment_id');
    }
}
