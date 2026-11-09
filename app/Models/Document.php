<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['processing_log' => 'array', 'stats' => 'array', 'document_date' => 'date', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function pagesText(): HasMany
    {
        return $this->hasMany(DocumentPage::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DocumentTable::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(DocumentProductLink::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'supersedes_id');
    }

    /** URL interna que abre el PDF en la página citada. */
    public function pageUrl(int $page): string
    {
        return route('documents.file', $this).'#page='.$page;
    }
}
