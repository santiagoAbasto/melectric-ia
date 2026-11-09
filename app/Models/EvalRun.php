<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvalRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'results' => 'array'];
    }
}
