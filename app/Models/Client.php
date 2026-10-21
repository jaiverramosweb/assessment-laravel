<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidAndMsTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use UsesUuidAndMsTimestamps;

    public const MS_UPDATED = 'updated_at';

    protected $table = 'clients';

    protected $guarded = [];

    // The logo bytes never travel inside JSON/Inertia props: they are served by their own route.
    protected $hidden = ['logo_data'];

    protected $casts = [
        'logo_updated_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'client_id');
    }
}
