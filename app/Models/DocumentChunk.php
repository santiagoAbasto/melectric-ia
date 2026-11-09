<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $guarded = ['id'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DocumentTable::class, 'table_id');
    }
}
