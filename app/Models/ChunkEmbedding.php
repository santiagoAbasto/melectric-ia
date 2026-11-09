<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChunkEmbedding extends Model
{
    protected $guarded = ['id'];

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(DocumentChunk::class, 'chunk_id');
    }
}
