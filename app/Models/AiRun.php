<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entities' => 'array', 'rules_fired' => 'array', 'tool_calls' => 'array', 'sources' => 'array', 'confidence_breakdown' => 'array', 'confidence' => 'float', 'llm_used' => 'boolean', 'llm_rejected' => 'boolean', 'cost_usd' => 'float'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class);
    }
}
