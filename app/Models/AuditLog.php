<?php

namespace App\Models;

use App\Support\Clock;
use Illuminate\Database\Eloquent\Model;

/** Audit trail entry (autoincrement id, ms timestamp). */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'created_at' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (AuditLog $log) => $log->created_at ??= Clock::nowMs());
    }
}
