<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['eval_summary' => 'array', 'published_at' => 'datetime'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(PromptDefinition::class, 'prompt_definition_id');
    }
}
