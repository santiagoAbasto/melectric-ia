<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAlias extends Model
{
    protected $guarded = ['id'];

    protected $table = 'product_aliases';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
