<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $guarded = ['id'];

    public function families(): HasMany
    {
        return $this->hasMany(ProductFamily::class, 'category_id');
    }
}
