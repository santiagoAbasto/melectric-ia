<?php

declare(strict_types=1);

namespace App\Models;

use App\Catalog\Units\UnitService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = ['id'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'family_id');
    }

    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(SourceRecord::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class);
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(ProductRelationship::class);
    }

    public function documentLinks(): HasMany
    {
        return $this->hasMany(DocumentProductLink::class);
    }

    /** Imagen de la línea (agrupador), no de la referencia exacta. */
    public function imageUrl(): ?string
    {
        $path = $this->family?->image_path;

        return $path ? '/storage/'.$path : null;
    }

    /** @return array<string, mixed> resumen para tarjetas y respuestas */
    public function card(): array
    {
        $this->loadMissing(['brand', 'family.category', 'specifications.definition']);
        $specs = [];
        foreach ($this->specifications->where('status', '!=', 'REJECTED')->sortByDesc('confidence') as $s) {
            $specs[$s->definition->key] ??= [
                'label' => $s->definition->label,
                'display' => UnitService::display($s),
                'confidence' => $s->confidence,
                'status' => $s->status,
            ];
        }

        return [
            'id' => $this->id,
            'code' => $this->internal_code,
            'reference' => $this->reference,
            'description' => $this->description,
            'brand' => $this->brand?->name,
            'family' => $this->family?->name,
            'category' => $this->family?->category?->name,
            'subfamily' => $this->subfamily,
            'image' => $this->imageUrl(),
            'specs' => $specs,
        ];
    }
}
