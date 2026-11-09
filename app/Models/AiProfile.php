<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tools' => 'array', 'knowledge_scopes' => 'array', 'enabled' => 'boolean', 'min_confidence' => 'float'];
    }
}
