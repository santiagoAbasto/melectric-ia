<?php

declare(strict_types=1);

namespace App\Search;

use App\Catalog\ProductReferenceNormalizer;
use App\Models\Product;
use Illuminate\Support\Collection;

final readonly class SearchResult
{
    /**
     * @param  list<array{product_id:int, score:float, signals:array, matched:array, needs_review:bool}>  $candidates
     * @param  list<array<string, mixed>>  $stages
     * @param  list<string>  $rules
     * @param  list<array<string, mixed>>  $constraints
     */
    public function __construct(
        public ParsedQuery $parsed,
        public string $strategy,
        public array $candidates,
        public array $stages,
        public array $rules,
        public array $constraints,
    ) {}

    public function found(): bool
    {
        return $this->candidates !== [];
    }

    /** @return Collection<int, Product> */
    public function products(int $limit = 40): Collection
    {
        $ids = array_slice(array_column($this->candidates, 'product_id'), 0, $limit);
        $byId = Product::query()->with(['brand', 'family.category', 'specifications.definition'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $byId[$id])->filter()->values();
    }

    /** Agrupa variantes (bobinas) del mismo tamaño: DILM32-10(…24V…), DILM32-10(…230V…) → DILM32. */
    public function sizeGroups(): array
    {
        return $this->products(400)->groupBy(function (Product $p) {
            $k = ProductReferenceNormalizer::sizeKey($p->reference);

            return $k ? $k[0].$k[1] : $p->base_reference;
        })->map->count()->all();
    }

    public function toArray(): array
    {
        return ['strategy' => $this->strategy, 'parsed' => $this->parsed->toArray(), 'constraints' => $this->constraints,
            'rules' => $this->rules, 'stages' => $this->stages, 'candidates' => $this->candidates];
    }
}
