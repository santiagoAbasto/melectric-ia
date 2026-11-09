<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stats' => 'array', 'log' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function running(): bool
    {
        return in_array($this->status, ['QUEUED', 'RUNNING'], true) && $this->created_at?->gt(now()->subMinutes(30));
    }
}
