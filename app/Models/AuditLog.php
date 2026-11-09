<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro inmutable: sólo se crea, nunca se actualiza ni borra desde la aplicación. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public static function record(string $action, Model|string $entity, ?array $before = null, ?array $after = null, ?string $traceId = null): self
    {
        return self::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => is_string($entity) ? $entity : class_basename($entity),
            'entity_id' => is_string($entity) ? null : $entity->getKey(),
            'before' => $before,
            'after' => $after,
            'trace_id' => $traceId,
        ]);
    }
}
