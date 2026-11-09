<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'float', 'value_min' => 'float', 'value_max' => 'float', 'confidence' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SpecificationDefinition::class, 'definition_id');
    }

    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(SourceRecord::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
