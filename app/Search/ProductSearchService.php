<?php

declare(strict_types=1);

namespace App\Search;

use App\Catalog\ProductReferenceNormalizer;
use App\Catalog\Units\UnitService;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductSpecification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda híbrida de productos en el orden de spec §60: referencia exacta → referencia normalizada/alias →
 * consulta técnica estructurada → texto completo. Los embeddings no participan en la selección de productos:
 * sólo en la búsqueda de conocimiento (KnowledgeSearchService). Cada etapa queda en el resultado para el depurador.
 */
final class ProductSearchService
{
    /** Tensión de empleo cubierta por la tabla AC-3 de contactores del catálogo (380-400 V). */
    private const AC3_TABLE_VOLTAGE = [380.0, 400.0];

    public function __construct(private readonly ElectricalQueryParser $parser) {}

    public function search(string|ParsedQuery $query, int $limit = 40): SearchResult
    {
        $parsed = $query instanceof ParsedQuery ? $query : $this->parser->parse($query);
        $stages = [];
        $rules = [];

        // 1-2. Referencia exacta / normalizada / alias
        foreach ($parsed->referenceCandidates as $candidate) {
            $t = microtime(true);
            $exact = Product::query()->where('reference', $candidate)->orWhere('internal_code', $candidate)->pluck('id')->all();
            $stages[] = ['stage' => 'exact_reference', 'input' => $candidate, 'ids' => $exact, 'ms' => $this->ms($t)];
            if ($exact) {
                return $this->result($parsed, 'exact_reference', $exact, $stages, $rules, 1.0);
            }
            $t = microtime(true);
            $n = ProductReferenceNormalizer::normalize($candidate);
            $ids = Product::query()->where('normalized_reference', $n)->pluck('id')
                ->merge(ProductAlias::query()->where('normalized', $n)->pluck('product_id'))->unique()->values()->all();
            $stages[] = ['stage' => 'normalized_reference', 'input' => $n, 'ids' => $ids, 'ms' => $this->ms($t)];
            if ($ids) {
                return $this->result($parsed, 'normalized_reference', $ids, $stages, $rules, 0.95);
            }
            // Tamaño de serie: "DILM25" → todas las variantes DILM25-xx
            if ($key = ProductReferenceNormalizer::sizeKey($candidate)) {
                $ids = Product::query()->where('series', $key[0])->get(['id', 'reference'])
                    ->filter(fn ($p) => (ProductReferenceNormalizer::sizeKey($p->reference)[1] ?? null) === $key[1])->pluck('id')->values()->all();
                $stages[] = ['stage' => 'series_size', 'input' => $key[0].$key[1], 'ids' => $ids, 'ms' => 0];
                if ($ids) {
                    $rules[] = 'SERIES_SIZE_MATCH';

                    return $this->result($parsed, 'series_size', $ids, $stages, $rules, 0.85);
                }
            }
        }

        // 3. Consulta técnica estructurada
        $constraints = $this->constraints($parsed, $rules);
        if ($parsed->productType && $constraints !== []) {
            $t = microtime(true);
            [$ids, $nextSize] = $this->structured($parsed->productType, $constraints);
            $stages[] = ['stage' => 'structured', 'input' => $constraints, 'ids' => $ids, 'ms' => $this->ms($t)];
            if ($nextSize) {
                $rules[] = 'AC3_TABLE_NEXT_SIZE';
            }
            if ($ids) {
                return $this->result($parsed, 'structured', $ids, $stages, $rules, $nextSize ? 0.75 : 0.9, $constraints);
            }
        }

        // 4. Texto completo (nunca reemplaza filtros: sólo cuando no hay requisitos técnicos)
        if ($constraints === [] && mb_strlen($parsed->raw) >= 3 && ! $parsed->hasIntent('CALCULATION')) {
            $t = microtime(true);
            $ids = $this->fullText($parsed->raw, $parsed->productType);
            $stages[] = ['stage' => 'full_text', 'input' => $parsed->raw, 'ids' => $ids, 'ms' => $this->ms($t)];
            if ($ids) {
                return $this->result($parsed, 'full_text', $ids, $stages, $rules, 0.5);
            }
        }

        return $this->result($parsed, 'none', [], $stages, $rules, 0.0, $constraints);
    }

    /** @return list<array{key:string, op:string, value:mixed, label:string}> */
    private function constraints(ParsedQuery $q, array &$rules): array
    {
        $c = [];
        $type = $q->productType;
        if ($q->has('current')) {
            $key = match ($type) {
                'motor_protector', 'overload_relay' => 'current_setting_range',
                'contactor' => 'rated_current_ac3',
                default => 'rated_current',
            };
            $c[] = ['key' => $key, 'op' => $key === 'current_setting_range' ? 'contains' : ($type === 'contactor' ? 'gte' : 'eq'), 'value' => $q->get('current'), 'label' => UnitService::format($q->get('current'), 'A')];
        }
        if ($q->has('power') && in_array($type, ['contactor', 'motor_protector'], true)) {
            $c[] = ['key' => 'motor_power', 'op' => $type === 'contactor' ? 'gte' : 'eq', 'value' => $q->get('power'), 'label' => UnitService::format($q->get('power'), 'W')];
        }
        foreach (['poles' => null, 'sensitivity' => 'A', 'reactive_power' => 'VAR'] as $key => $unit) {
            if ($q->has($key)) {
                $c[] = ['key' => $key, 'op' => 'eq', 'value' => $q->get($key), 'label' => $unit ? UnitService::format($q->get($key), $unit) : $q->get($key).' polos'];
            }
        }
        if ($q->has('breaking_capacity')) {
            $c[] = ['key' => 'breaking_capacity', 'op' => 'gte', 'value' => $q->get('breaking_capacity'), 'label' => '≥ '.UnitService::format($q->get('breaking_capacity'), 'A')];
        }
        if ($q->has('curve')) {
            $c[] = ['key' => 'curve', 'op' => 'text', 'value' => $q->get('curve'), 'label' => 'curva '.$q->get('curve')];
        }
        if ($q->has('coil_voltage') && $type === 'contactor') {
            $c[] = ['key' => 'coil_voltage', 'op' => 'eq', 'value' => $q->get('coil_voltage'), 'qualifier' => $q->get('coil_kind'), 'label' => 'bobina '.$q->get('coil_voltage').' V '.($q->get('coil_kind') ?? '')];
        }
        if ($q->has('utilization_category') && $type === 'contactor') {
            $c[] = ['key' => 'utilization_category', 'op' => 'text', 'value' => $q->get('utilization_category'), 'label' => $q->get('utilization_category')];
        }
        if ($q->has('voltage') && $type === 'contactor' && $q->has('power')) {
            [$lo, $hi] = self::AC3_TABLE_VOLTAGE;
            if ($q->get('voltage') < $lo || $q->get('voltage') > $hi) {
                $rules[] = 'VOLTAGE_OUTSIDE_CATALOG_TABLE';
            }
        }

        return $c;
    }

    /** @return array{0:list<int>, 1:bool} [ids, se eligió el tamaño siguiente] */
    private function structured(string $type, array $constraints): array
    {
        $base = fn () => Product::query()->whereHas('category', fn (Builder $b) => $b->where('product_type', $type));
        $apply = function (Builder $q, array $c, bool $exactOnly) {
            $q->whereHas('specifications', function (Builder $s) use ($c, $exactOnly) {
                $s->whereHas('definition', fn (Builder $d) => $d->where('key', $c['key']))->where('status', '!=', 'REJECTED');
                match (true) {
                    $c['op'] === 'text' => $s->where('value_text', $c['value']),
                    $c['op'] === 'contains' => $s->where('value_min', '<=', $c['value'])->where('value_max', '>=', $c['value']),
                    $c['op'] === 'gte' && ! $exactOnly => $s->where('value', '>=', $c['value'] - 1e-6),
                    default => $s->whereBetween('value', [$c['value'] - 1e-6, $c['value'] + 1e-6]),
                };
                if (! empty($c['qualifier'])) {
                    $s->where('qualifier', $c['qualifier']);
                }
            });
        };

        $q = $base();
        foreach ($constraints as $c) {
            $apply($q, $c, true);
        }
        $ids = $q->limit(400)->pluck('id')->all();
        if ($ids || ! collect($constraints)->contains('op', 'gte')) {
            return [$ids, false];
        }

        // Tamaño siguiente de la tabla: el menor valor ≥ al pedido (p. ej. 10 kW → fila de 11 kW)
        $q = $base();
        foreach ($constraints as $c) {
            $apply($q, $c, false);
        }
        $ids = $q->limit(400)->pluck('id')->all();
        $gte = collect($constraints)->firstWhere('op', 'gte');
        $min = ProductSpecification::query()->whereIn('product_id', $ids)
            ->whereHas('definition', fn (Builder $d) => $d->where('key', $gte['key']))->min('value');
        if ($min === null) {
            return [[], false];
        }
        $ids = ProductSpecification::query()->whereIn('product_id', $ids)->whereHas('definition', fn (Builder $d) => $d->where('key', $gte['key']))
            ->whereBetween('value', [$min - 1e-6, $min + 1e-6])->distinct()->pluck('product_id')->all();

        return [$ids, true];
    }

    /** @return list<int> */
    private function fullText(string $text, ?string $type): array
    {
        $q = Product::query()->when($type, fn ($b) => $b->whereHas('category', fn ($c) => $c->where('product_type', $type)));
        if (DB::getDriverName() === 'mysql') {
            $terms = trim(preg_replace('/[^\p{L}\p{N}\s\-\/]/u', ' ', $text) ?? '');
            if ($terms === '') {
                return [];
            }
            $q->whereRaw('MATCH(reference, description) AGAINST (? IN NATURAL LANGUAGE MODE)', [$terms])
                ->orderByRaw('MATCH(reference, description) AGAINST (? IN NATURAL LANGUAGE MODE) DESC', [$terms]);
        } else {
            $words = array_filter(preg_split('/\s+/u', mb_strtolower($text)) ?: [], fn ($w) => mb_strlen($w) > 3);
            $q->where(function ($b) use ($words) {
                foreach ($words as $w) {
                    $b->orWhereRaw('LOWER(description) LIKE ?', ['%'.$w.'%']);
                }
            });
        }

        return $q->limit(20)->pluck('id')->all();
    }

    private function result(ParsedQuery $parsed, string $strategy, array $ids, array $stages, array $rules, float $baseScore, array $constraints = []): SearchResult
    {
        $products = Product::query()->with(['brand', 'family.category', 'specifications.definition'])->whereIn('id', $ids)->get();
        $candidates = $products->map(function (Product $p) use ($baseScore, $constraints) {
            $matched = [];
            foreach ($constraints as $c) {
                $spec = $p->specifications->first(fn ($s) => $s->definition->key === $c['key'] && $s->status !== 'REJECTED');
                if ($spec) {
                    $matched[$c['key']] = ['display' => UnitService::display($spec), 'confidence' => $spec->confidence, 'status' => $spec->status,
                        'source' => $spec->document_id ? 'document:'.$spec->document_id.':'.$spec->page : 'record:'.$spec->source_record_id];
                }
            }
            $conf = $matched ? collect($matched)->avg('confidence') : 1.0;
            $attr = $constraints ? count($matched) / count($constraints) : 1.0;

            return ['product_id' => $p->id, 'score' => round($baseScore * $attr * $conf, 3), 'signals' => [
                'strategy_score' => $baseScore, 'attribute_match' => round($attr, 3), 'spec_confidence' => round($conf, 3),
            ], 'matched' => $matched, 'needs_review' => collect($matched)->contains('status', 'NEEDS_REVIEW')];
        })->sortBy([['score', 'desc']])->values()->all();

        return new SearchResult($parsed, $strategy, $candidates, $stages, $rules, $constraints);
    }

    private function ms(float $t): int
    {
        return (int) round((microtime(true) - $t) * 1000);
    }
}
