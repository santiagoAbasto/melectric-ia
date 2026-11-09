<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
