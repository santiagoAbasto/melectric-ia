<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'source_date' => 'datetime', 'retrieved_at' => 'datetime'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }
}
