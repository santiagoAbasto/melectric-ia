<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPage extends Model
{
    protected $guarded = ['id'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
