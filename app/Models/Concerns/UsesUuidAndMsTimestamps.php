<?php

namespace App\Models\Concerns;

use App\Support\Clock;
use Illuminate\Support\Str;

/**
 * Text UUID primary key and integer-millisecond timestamps (the original app's layout).
 *
 * Models declare the timestamp columns they own with the `MS_CREATED` / `MS_UPDATED`
 * constants; they are filled on create and update unless the caller already set them.
 */
trait UsesUuidAndMsTimestamps
{
    public static function bootUsesUuidAndMsTimestamps(): void
    {
        static::creating(function ($model) {
            if (! $model->getKey()) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
            $now = Clock::nowMs();
            foreach ([static::msCreatedColumn(), static::msUpdatedColumn()] as $column) {
                if ($column && $model->getAttribute($column) === null) {
                    $model->setAttribute($column, $now);
                }
            }
        });

        static::updating(function ($model) {
            $column = static::msUpdatedColumn();
            if ($column && ! $model->isDirty($column)) {
                $model->setAttribute($column, Clock::nowMs());
            }
        });
    }

    public function initializeUsesUuidAndMsTimestamps(): void
    {
        $this->timestamps = false;
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    protected static function msCreatedColumn(): ?string
    {
        return defined(static::class.'::MS_CREATED') ? static::MS_CREATED : 'created_at';
    }

    protected static function msUpdatedColumn(): ?string
    {
        return defined(static::class.'::MS_UPDATED') ? static::MS_UPDATED : null;
    }
}
