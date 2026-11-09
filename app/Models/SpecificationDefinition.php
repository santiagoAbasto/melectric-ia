<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecificationDefinition extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['safety_critical' => 'boolean'];
    }
}
