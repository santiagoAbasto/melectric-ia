<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRelationship extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'verified_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    public function relatedFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'related_family_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(SourceRecord::class);
    }
}
