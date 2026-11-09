<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiFeedback extends Model
{
    protected $guarded = ['id'];

    protected $table = 'ai_feedback';
}
