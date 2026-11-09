<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeConflict extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function specA(): BelongsTo
    {
        return $this->belongsTo(ProductSpecification::class, 'spec_a_id');
    }

    public function specB(): BelongsTo
    {
        return $this->belongsTo(ProductSpecification::class, 'spec_b_id');
    }
}
