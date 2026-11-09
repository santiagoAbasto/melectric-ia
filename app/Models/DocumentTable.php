<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTable extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['header_lines' => 'array', 'confidence' => 'float'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function cells(): HasMany
    {
        return $this->hasMany(DocumentTableCell::class, 'table_id');
    }

    public function footnotes(): HasMany
    {
        return $this->hasMany(TableFootnote::class, 'table_id');
    }

    /** @return list<list<string>> */
    public function grid(): array
    {
        $grid = [];
        foreach ($this->cells()->orderBy('row')->orderBy('col')->get() as $c) {
            $grid[$c->row][$c->col] = $c->raw_value;
        }

        return array_values(array_map(function ($r) {
            $out = [];
            for ($i = 0; $i <= max(array_keys($r)); $i++) {
                $out[] = $r[$i] ?? '';
            }

return $out;
        }, $grid));
    }
}
