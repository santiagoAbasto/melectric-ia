<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptDefinition extends Model
{
    protected $guarded = ['id'];

    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class);
    }
}
